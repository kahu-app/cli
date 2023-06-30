<?php
declare(strict_types = 1);

namespace Kahu\Cli\Commands\Auth;

use DateTimeInterface;
use Kahu\OAuth2\Client\Provider\Kahu;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('auth:status', 'View authentication status')]
final class StatusCommand extends Command {
  private AccessTokenInterface $accessToken;
  private Kahu $kahu;

  protected function execute(InputInterface $input, OutputInterface $output): int {
    if ($this->accessToken->getToken() === 'unauthenticated') {
      $output->writeln(
        [
          '',
          'You are currently not authenticated',
          ''
        ]
      );

      return Command::SUCCESS;
    }

    if ($this->accessToken->hasExpired() === true) {
      $output->writeln(
        [
          '',
          'Your access token has expired, try to refresh it (<options=bold>auth:refresh</>) or login again (<options=bold>auth:login</>)',
          ''
        ]
      );

      return Command::SUCCESS;
    }

    $profile = $this->kahu->getResourceOwner($this->accessToken);

    $output->writeln(
      [
        '',
        'Authenticated as <options=bold>' . $profile->getName() . '</>.',
        'Valid until: <options=bold>' . date(DateTimeInterface::ATOM, $this->accessToken->getExpires()) . '</>',
        ''
      ]
    );

    return Command::SUCCESS;
  }

  public function __construct(AccessTokenInterface $accessToken, Kahu $kahu) {
    parent::__construct();

    $this->accessToken = $accessToken;
    $this->kahu = $kahu;
  }
}
