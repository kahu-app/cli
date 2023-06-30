<?php
declare(strict_types = 1);

namespace Kahu\Cli\Commands\Auth;

use Kahu\OAuth2\Client\Provider\Exception\KahuIdentityProviderException;
use Kahu\OAuth2\Client\Provider\Kahu;
use League\Config\ConfigurationInterface;
use League\OAuth2\Client\Token\AccessTokenInterface;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('auth:refresh', 'Refresh stored authentication credentials')]
final class RefreshCommand extends Command {
  private AccessTokenInterface $accessToken;
  private Kahu $kahu;
  private ConfigurationInterface $config;

  protected function configure(): void {
    $this
      ->addOption(
        'force',
        'f',
        InputOption::VALUE_NONE,
        'Force the token refresh even if it is not yet expired'
      );
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    if ($this->accessToken->getToken() === 'unauthenticated') {
      $output->writeln(
        [
          '',
          'You are currently not authenticated',
          'Use <options=bold>auth:login</> instead',
          ''
        ]
      );

      return Command::FAILURE;
    }

    $force = (bool)$input->getOption('force');
    if ($this->accessToken->hasExpired() === false && $force === false) {
      $output->writeln(
        [
          '',
          'Access token is not yet expired',
          ''
        ]
      );

      return Command::SUCCESS;
    }

    try {
      $accessToken = $this->kahu->getAccessToken(
        'refresh_token',
        [
          'refresh_token' => $this->accessToken->getRefreshToken()
        ]
      );

      $authFile = $this->config->get('authFile');
      if (is_string($authFile) === false) {
        throw new RuntimeException('Invalid authentication file path');
      }

      $path = dirname($authFile);
      if (is_dir($path) === false && mkdir($path, recursive: true) === false) {
        throw new RuntimeException('Failed to create configuration directory');
      }

      file_put_contents(
        $authFile,
        json_encode($accessToken, JSON_THROW_ON_ERROR),
        LOCK_EX
      );

      $output->writeln(
        [
          '',
          'Token refreshed',
          ''
        ]
      );

      return Command::SUCCESS;
    } catch (KahuIdentityProviderException $exception) {
      $output->writeln(
        [
          '',
          '<error>Failed to refresh token, message from the authentication server: <options=bold;bg=red>' . $exception->getMessage() . '</>',
          ''
        ]
      );

      return Command::FAILURE;
    }
  }

  public function __construct(AccessTokenInterface $accessToken, Kahu $kahu, ConfigurationInterface $config) {
    parent::__construct();

    $this->accessToken = $accessToken;
    $this->kahu = $kahu;
    $this->config = $config;
  }
}
