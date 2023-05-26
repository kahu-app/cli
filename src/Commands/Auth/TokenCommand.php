<?php
declare(strict_types = 1);

namespace Kahu\Cli\Commands\Auth;

use DateTimeInterface;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('auth:token', 'Print the auth token cli is configured to use')]
final class TokenCommand extends Command {
  private AccessTokenInterface $accessToken;

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $output->writeln(
      [
        '',
        'Access token: <options=bold>' . $this->accessToken->getToken() . '</>',
        ''
      ]
    );
    if ($this->accessToken->hasExpired() === true) {
      $output->writeln(
        [
          '<info>This token has already expired!</info>',
          '<info>Expired in: <options=bold>' . date(DateTimeInterface::ATOM, $this->accessToken->getExpires()) . '</>'
        ]
      );
    }

    return Command::SUCCESS;
  }

  public function __construct(AccessTokenInterface $accessToken) {
    parent::__construct();

    $this->accessToken = $accessToken;
  }
}
