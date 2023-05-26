<?php
declare(strict_types = 1);

namespace Kahu\Cli\Commands\Auth;

use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\SocketClientFactory;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket;
use Jay\Json;
use League\Config\ConfigurationInterface;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

use Kahu\OAuth2\Client\Provider\Kahu;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('auth:login', 'Authenticate with Kahu.app')]
final class LoginCommand extends Command {
  private AccessTokenInterface $accessToken;
  private Kahu $kahu;
  private ConfigurationInterface $config;

  private function openBrowser(string $url): void {
    $command = match (strtolower(PHP_OS_FAMILY)) {
      'bsd', 'linux' => 'xdg-open',
      'dawrin' => 'open',
      default => 'start'
    };

    exec(
      sprintf(
        '%s %s',
        escapeshellcmd($command),
        escapeshellarg($url)
      )
    );
  }

  protected function configure(): void {
    $this
      ->addOption(
        'force',
        'f',
        InputOption::VALUE_NONE,
        'Force a new login even if you are already authenticated'
      );
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $force = (bool)$input->getOption('force');
    if (
      $force === false &&
      $this->accessToken->getToken() !== 'unauthenticated' &&
      $this->accessToken->hasExpired() === false
    ) {
      $output->writeln(
        [
          '',
          'You are already authenticated',
          ''
        ]
      );

      return Command::SUCCESS;
    }

    $logger = new NullLogger();
    if ($output->isDebug()) {
      $logger = new class($output) extends AbstractLogger {
        private OutputInterface $output;

        public function __construct(OutputInterface $output) {
          $this->output = $output;
        }

        public function log($level, string|\Stringable $message, array $context = []): void {
          $this->output->writeln($message);
        }
      };
    }

    $server = new SocketHttpServer(
      $logger,
      new Socket\ResourceServerSocketFactory(),
      new SocketClientFactory($logger)
    );

    $records = \Amp\Dns\resolve('localhost', \Amp\Dns\DnsRecord::A);

    $server->expose(sprintf('%s:0', $records[0]->getValue()));
    $server->start(
      new class($this->kahu, $this->config) implements RequestHandler {
        private Kahu $kahu;
        private ConfigurationInterface $config;

        public function __construct(Kahu $kahu, ConfigurationInterface $config) {
          $this->kahu = $kahu;
          $this->config = $config;
        }

        public function handleRequest(Request $request): Response {
          if ($request->hasQueryParameter('code') === false) {
            return new Response(HttpStatus::BAD_REQUEST);
          }

          if (
            $request->hasQueryParameter('state') === false ||
            $request->getQueryParameter('state') !== $this->kahu->getState()
          ) {
            return new Response(HttpStatus::BAD_REQUEST);
          }

          $accessToken = $this->kahu->getAccessToken(
            'authorization_code',
            [
              'code' => $request->getQueryParameter('code')
            ]
          );

          $authFile = $this->config->get('authFile');
          $path = dirname($authFile);
          if (is_dir($path) === false && mkdir($path, recursive: true) === false) {
            throw new RuntimeException('Failed to create configuration directory');
          }

          file_put_contents(
            $authFile,
            json_encode($accessToken, JSON_THROW_ON_ERROR),
            LOCK_EX
          );

          posix_kill(getmypid(), SIGINT);

          return new Response(
            HttpStatus::FOUND,
            [
              'location' => 'https://sso.kahu.app/authorization/success'
            ]
          );
        }
      },
      new DefaultErrorHandler()
    );

    $servers = $server->getServers();
    $localhost = $servers[0]->getAddress();
    $localAddress = $localhost->getAddress();
    $localPort = $localhost->getPort();

    $this->kahu->setRedirectUri("http://{$localAddress}:{$localPort}/callback");
    $authUrl = $this->kahu->getAuthorizationUrl();

    $output->writeln(
      [
        '',
        'Opening browser..',
        $authUrl,
        ''
      ]
    );

    $this->openBrowser($authUrl);

    $signal = \Amp\trapSignal([\SIGHUP, \SIGINT, \SIGQUIT, \SIGTERM]);
    $server->stop();

    $authFile = $this->config->get('authFile');
    $json = Json::fromFile($authFile, true);

    $accessToken = new AccessToken($json);

    $profile = $this->kahu->getResourceOwner($accessToken);

    $output->writeln(
      [
        '',
        'Authenticated as <options=bold>' . $profile->getName() . '</>.',
        ''
      ]
    );

    return Command::SUCCESS;
  }

  public function __construct(AccessTokeninterface $accessToken, Kahu $kahu, ConfigurationInterface $config) {
    parent::__construct();

    $this->accessToken = $accessToken;
    $this->kahu = $kahu;
    $this->config = $config;
  }
}
