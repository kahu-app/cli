<?php
declare(strict_types = 1);

namespace Kahu\Cli\Commands;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('check', 'Check a manifest file')]
final class CheckCommand extends Command {
  private ClientInterface $client;
  private RequestFactoryInterface $requestFactory;
  private StreamFactoryInterface $streamFactory;

  protected function configure(): void {
    $this
      ->addOption(
        'sbom',
        null,
        InputOption::VALUE_NONE,
        'Generate a Software Bill of Materials'
      )
      ->addArgument(
        'lockfile',
        InputArgument::REQUIRED,
        'The composer.lock of your project, that will be checked'
      );
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $sbom = (bool)$input->getOption('sbom');
    $lockfile = $input->getArgument('lockfile');

    $request = $this->requestFactory
      ->createRequest('POST', 'https://api.kahu.app/v1/projects')
      ->withHeader('Authorization', 'Bearer X-AUTH-TOKEN')
      ->withBody($this->streamFactory->createStreamFromFile($lockfile));

    $response = $this->client->sendRequest($request);







    $output->writeln('<options=bold>Risky APIs</>');
    $output->writeln('<fg=gray>APIs that should be reviewed carefully as they may be abused by malicious users</>');

    $table = new Table($output);
    $table->setHeaders(
      [
        'API',
        'Occurrences'
      ]
    );
    $table->addRow(['code-eval', '10 occurrences']);
    $table->addRow(['shell-exec', '2 occurrences']);

    $table->render();

    return Command::SUCCESS;
  }

  public function __construct(
    ClientInterface $client,
    RequestFactoryInterface $requestFactory,
    StreamFactoryInterface $streamFactory
  ) {
    parent::__construct();

    $this->client = $client;
    $this->requestFactory = $requestFactory;
    $this->streamFactory = $streamFactory;
  }
}
