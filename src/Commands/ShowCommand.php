<?php
declare(strict_types = 1);

namespace Kahu\Cli\Commands;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('show', 'Shows information about packages')]
final class ShowCommand extends Command {
  private ClientInterface $client;
  private RequestFactoryInterface $requestFactory;
  private StreamFactoryInterface $streamFactory;

  protected function configure(): void {
    $this
      ->setAliases(['info'])
      ->addOption(
        'tag',
        null,
        InputOption::VALUE_REQUIRED,
        'The version',
        'latest'
      )
      ->addArgument(
        'package',
        InputArgument::REQUIRED,
        'The name of the package that '
      );
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $package = $input->getArgument('package');
    $tag = $input->getOption('tag');

    $request = $this->requestFactory
      ->createRequest('GET', "https://api.kahu.app/v1/packages/{$package}/{$tag}");

    $response = $this->client->sendRequest($request);

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
