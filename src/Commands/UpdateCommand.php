<?php
declare(strict_types = 1);

namespace Kahu\Cli\Commands;

use Humbug\SelfUpdate\Updater;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('update', 'Updates kahu-app.phar to the latest version')]
final class UpdateCommand extends Command {
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $updater = new Updater(
      'bin/kahu-app.phar',
      false,
      Updater::STRATEGY_GITHUB
    );
    $strategy = $updater->getStrategy();
    $strategy->setPackageName('kahu-app/cli');
    $strategy->setPharName('kahu-app.phar');

    if ($updater->update()) {
      $output->writeln(
        sprintf(
          'Your PHAR has been updated from "%s" to "%s".',
          $updater->getOldVersion(),
          $updater->getNewVersion()
        )
      );

      return Command::SUCCESS;
    }

    $output->writeln('Your PHAR is already up to date.');

    return Command::SUCCESS;
  }
}
