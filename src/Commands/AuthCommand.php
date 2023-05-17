<?php
declare(strict_types = 1);

namespace Kahu\Cli\Commands;

use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\SocketClientFactory;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket;
use League\OAuth2\Client\Token\AccessToken;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

use Kahu\OAuth2\Client\Provider\Kahu;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('auth', 'Authenticate with Kahu.app')]
final class AuthCommand extends Command {
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
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    // "cli.kahu.app" OAuth app
    $provider = new Kahu(
      [
        'clientId' => 'fb738462e4c64c37a96c5488999a07a7',
        'clientSecret' => 'd5719d8ef4d011eda05b0242ac120003'
      ]
    );

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
      new class($provider) implements RequestHandler {
        private Kahu $provider;

        public function __construct(Kahu $provider) {
          $this->provider = $provider;
        }

        public function handleRequest(Request $request): Response {
          if ($request->hasQueryParameter('code') === false) {
            return new Response(HttpStatus::BAD_REQUEST);
          }

          if (
            $request->hasQueryParameter('state') === false ||
            $request->getQueryParameter('state') !== $this->provider->getState()
          ) {
            return new Response(HttpStatus::BAD_REQUEST);
          }

          $accessToken = $this->provider->getAccessToken(
            'authorization_code',
            [
              'code' => $request->getQueryParameter('code')
            ]
          );

          $path = dirname(AUTH_FILE);
          if (is_dir($path) === false && mkdir($path, recursive: true) === false) {
            throw new RuntimeException('Failed to create configuration directory');
          }

          file_put_contents(
            AUTH_FILE,
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

    $provider->setRedirectUri("http://{$localAddress}:{$localPort}/callback");
    $authUrl = $provider->getAuthorizationUrl();

    $output->writeln('Opening browser..');
    $output->writeln($authUrl);

    $this->openBrowser($authUrl);

    $signal = \Amp\trapSignal([\SIGHUP, \SIGINT, \SIGQUIT, \SIGTERM]);
    $server->stop();

    $accessToken = new AccessToken(
      [
        'token_type' => 'Bearer',
        'access_token' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJhdWQiOiJmYjczODQ2MmU0YzY0YzM3YTk2YzU0ODg5OTlhMDdhNyIsImp0aSI6Ijk2NGJiMGIyZjIyNjQ1ZGIzZTZiZTQ2NmZiMzMyNzY5YTA0MmQ5ZTUxMzZhMWU4YmEzNTg1ZWRjNDk5MDRmMjk1YzUxYzBhMGMzMzE5NmEzIiwiaWF0IjoxNjg0MzQ0NjEzLjAyODc2MSwibmJmIjoxNjg0MzQ0NjEzLjAyODc2MywiZXhwIjoxNjg0MzQ4MjEzLjAyMjcwOSwic3ViIjoiMGEyM2U3ODY4MTJmZTYxMmQ5NzQ2ZWE2ZDZhZTVjY2NjNTJmZTE0Y2YzNGI2YWVjMTE5YzZkZGJmNzAyZTQ1OSIsInNjb3BlcyI6WyJ1c2VyLm5hbWUiLCJ1c2VyLmVtYWlsIl19.QLeeXnejKLLcREmCqvtUnz4F9WdtAr9sA3df33G3EpNenMiVFh4yvAYTLrCLGvUqYWT0Mrt1T1q9Loef7M48X4qv815vGs0R5D3RE8CkNsfN-lLEeP7C5UyD4MzsYAwT8h6mS1hS-O-zj_m8TTtgco7YcW_zlj5GKzk6-6YaOmu1IQcReJD_zBYEGHdUKuh5rwA9yObXtrfrPl4CHjjoJuN0oplD7ykzqMO_lhZfnrgDgbNfmcQtF4gtt85rvzRtKWkDqNnC-9WZRca27quBU_CnbJ640JC0gy0EIS91TpW6wpwicBE2Czsk-BWs01TOSN5PmrJmReQYHIjW0GCfZA',
        'refresh_token' => 'def502008d5027d4df8ada97a3e6b7151eaef386dff6135a43199f7ce769ae052d82d6c864b098c0c384c93c304fa8dbce15f0a2c46f3817a2d01e3ce558fa2e559a2d513e797e4496e5baeb8fa3914cca90fb8ebf56b485977d36e5926cd5466b7ccb2bb91f34afb5126b2df8ae362e9ae1fd5f2c44c0a67cf5a8df86b4fb0dab34fe077d03f2254667d40c59d2b4ea41e0d93da1d4c5f697802026f410afde7d07c80435d7afe81d9d504c636a6c517bac15518a4f2dd146cfbdbde63c43a9c2320257175551bb1463bb53d1c952b104d659cab3045de32371b5ea42919d2408915ccd5acbc06ff9264ff7b55cfb6a5f8dcc06dbcaf9fb5a3accac121258989ba652d87b31d8f21c68e1ef88a7b06ef0e83cb13a9bca3ba4929a0e252adb411b45ac1dbf86932d83127a7de8be6153f487d7b5bd57ac7e6848c8981eb2ec98060dd75f7a12b01a839f57f13463aa2cdf073c59b0773f4fc11ce2d7a886dfe4941eeaa5721fa7fc15f0b99364beddc26fd886d599759b1ea4bdad46561cc5b405e99ac6783cf1e8a78ced8bce88850be80b217f0b224883c138fcc826c203527e4922eebda062725e0579777679445bedd8beac355b22e5e982cdc1ef1e8051aec12b2270d572d2d457bb8ec9202bd7a694276d50549491c7',
        'expires' => 1684348213
      ]
    );

    $profile = $provider->getResourceOwner($accessToken);

    $output->writeln('Authenticated as <options=bold>' . $profile->getName() . '</>.');

    return Command::SUCCESS;
  }
}
