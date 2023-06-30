<?php
declare(strict_types = 1);

namespace Kahu\Cli\Commands\Manifest;

use Jay\Json;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Path;
use Teapot\StatusCode\Http;

#[AsCommand('manifest:upload', 'Upload a manifest file to be analysed')]
final class UploadCommand extends Command {
  private ClientInterface $client;
  private RequestFactoryInterface $requestFactory;
  private StreamFactoryInterface $streamFactory;

  protected function configure(): void {
    $this
      ->addOption(
        'json',
        null,
        InputOption::VALUE_NONE,
        'Format the output as "json"'
      )
      ->addOption(
        'id-only',
        null,
        InputOption::VALUE_NONE,
        'Prints the report id in a way that can be used by another command'
      )
      ->addArgument(
        'manifest',
        InputArgument::REQUIRED,
        'The manifest file that will be uploaded'
      );
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $json = (bool)$input->getOption('json');
    $idOnly = (bool)$input->getOption('id-only');
    $manifest = $input->getArgument('manifest');
    $manifest = Path::makeAbsolute($manifest, __ROOT__);
    if (is_file($manifest) === false) {
      $output->writeln("File \"{$manifest}\" could not be found");

      return Command::FAILURE;
    }

    if (is_readable($manifest) === false) {
      $output->writeln("File \"{$manifest}\" is not readable");

      return Command::FAILURE;
    }

    if (str_ends_with($manifest, '/composer.lock') === false) {
      $output->writeln("Invalid manifest file \"{$manifest}\", the only currently supported file is \"composer.lock\"");

      return Command::FAILURE;
    }

    $checksum = sha1_file($manifest);
    if ($checksum === false) {
      $output->writeln('Failed to calculate the sha1 hash of manifest file');
      return Command::FAILURE;
    }

    $buffer = $this->streamFactory->createStream();
    $buffer->write("--{$checksum}\r\n");
    $buffer->write('Content-Disposition: form-data; name="manifest"; filename="' . basename($manifest) . "\"\r\n");
    $buffer->write("Content-Type: text/plain\r\n");
    $buffer->write("\r\n");
    $buffer->write(file_get_contents($manifest));
    $buffer->write("\r\n");
    $buffer->write("--{$checksum}--\r\n");
    $buffer->rewind();

    $request = $this->requestFactory
      ->createRequest('POST', 'https://api.kahu.app/v0/upload')
      ->withHeader('Content-Type', "multipart/form-data; boundary=\"{$checksum}\"")
      ->withHeader('Content-Length', $buffer->getSize())
      ->withBody($buffer);

    $response = $this->client->sendRequest($request);
    if ($response->getStatusCode() !== Http::CREATED) {
      if ($idOnly) {
        return Command::FAILURE;
      }

      if ($json) {
        $output->write(
          json_encode(
            [
              'error' => 'Kahu API returned an unexpected status code',
              'code' => $response->getStatusCode()
            ]
          )
        );

        return Command::FAILURE;
      }

      $output->writeln(
        sprintf(
          'Error: Kahu API returned an unexpected status code (%d)',
          $response->getStatusCode()
        )
      );

      if ($output->isDebug()) {
        $body = (string)$response->getBody();
        if (str_starts_with($response->getHeaderLine('content-type'), 'application/json') === true) {
          $body = Json::fromString($body, true);
          if (isset($body['data']['message'])) {
            $output->writeln($body['data']['message']);
          }

          return Command::FAILURE;
        }

        $output->writeln($body);
      }

      return Command::FAILURE;
    }

    $body = Json::fromString((string)$response->getBody(), true);
    if ($body['status'] === true) {
      if ($idOnly) {
        $output->write($body['data']['reportId']);

        return Command::SUCCESS;
      }

      if ($json) {
        $output->write(
          json_encode(
            [
              'reportId' => $body['data']['reportId'],
              'checksum' => $checksum === $body['data']['checksum']
            ]
          )
        );

        return Command::SUCCESS;
      }

      $output->writeln(
        [
          '',
          'Report ID: <options=bold>' . $body['data']['reportId'] . '</>',
          'Checksum: <options=bold>' . ($checksum === $body['data']['checksum'] ? 'OK' : 'ERROR') . '</>',
          ''
        ]
      );
    }

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
