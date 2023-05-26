<?php
declare(strict_types = 1);

namespace Kahu\Cli\Commands\Auth;

use League\Config\ConfigurationInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('auth:logout', 'Log out of Kahu.app')]
final class LogoutCommand extends Command {
  private ConfigurationInterface $config;

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $authFile = $this->config->get('authFile');
    if (file_exists($authFile) === false) {
      $output->writeln(
        [
          '',
          'You are currently not authenticated',
          ''
        ]
      );

      return Command::SUCCESS;
    }

    if (unlink($authFile) === false) {
      $output->writeln(
        [
          '',
          'Failed to remove credential file',
          ''
        ]
      );

      return Command::FAILURE;
    }

    $output->writeln(
      [
        '',
        'You are now logged out',
        ''
      ]
    );

    return Command::SUCCESS;
  }

  public function __construct(ConfigurationInterface $config) {
    parent::__construct();

    $this->config = $config;
  }
}
