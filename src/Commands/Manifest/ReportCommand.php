<?php
declare(strict_types = 1);

namespace Kahu\Cli\Commands\Manifest;

use InvalidArgumentException;
use Jay\Json;
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
use Teapot\StatusCode\Http;

#[AsCommand('manifest:report', 'View the analysis report')]
final class ReportCommand extends Command {
  private ClientInterface $client;
  private RequestFactoryInterface $requestFactory;
  private StreamFactoryInterface $streamFactory;

  protected function configure(): void {
    $this
      ->addOption(
        'advisories',
        null,
        InputOption::VALUE_NONE,
        'Include the advisories list to the output'
      )
      ->addOption(
        'sbom',
        null,
        InputOption::VALUE_NONE,
        'Include the Software Bill of Materials to the output'
      )
      ->addOption(
        'summary',
        null,
        InputOption::VALUE_NONE,
        'Include the summary metrics to the output'
      )
      ->addOption(
        'wait',
        'w',
        InputOption::VALUE_NONE,
        'Wait until the report is ready'
      )
      ->addOption(
        'timeout',
        't',
        InputOption::VALUE_REQUIRED,
        'Interval in seconds to wait for report analysis to be done (default: 60)',
        60
      )
      ->addArgument(
        'reportId',
        InputArgument::REQUIRED,
        'The report identification (unique 40-chars long string)'
      );
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $wait = (bool)$input->getOption('wait');
    $timeout = (int)$input->getOption('timeout');
    if ($timeout < 0) {
      throw new InvalidArgumentException('Invalid "timeout" option, it must be a positive integer');
    }

    $reportId = $input->getArgument('reportId');
    if (is_string($reportId) === false || preg_match('/^[a-z0-9]{40}$/', $reportId) !== 1) {
      throw new InvalidArgumentException('Invalid "reportId" argument');
    }

    $request = $this->requestFactory->createRequest('GET', "https://api.kahu.app/v0/reports/{$reportId}");
    $time = time();
    do {
      $response = $this->client->sendRequest($request);
      if ($response->getStatusCode() === Http::ACCEPTED) {
        if ($wait === false) {
          $output->writeln(
            'Report is not ready yet',
            OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_VERBOSE
          );

          return Command::SUCCESS;
        }

        if ($timeout > 0 && (time() - $time) >= $timeout) {
          $output->writeln('Wait timeout, ' . time() - $time . ' seconds elapsed');

          return Command::FAILURE;
        }

        sleep(5);

        continue;
      }

      $wait = false;
    } while ($wait === true);

    $body = (string)$response->getBody();
    if (str_starts_with($response->getHeaderLine('content-type'), 'application/json') === true) {
      $body = Json::fromString($body, true);
    }

    if ($response->getStatusCode() >= Http::BAD_REQUEST) {
      $output->writeln('Error: Unexpected API error (code ' . $response->getStatusCode() . ')');
      if (is_array($body) === true && isset($body['error']['message']) === true) {
        $output->writeln('Message: ' . $body['error']['message']);
      }

      return Command::FAILURE;
    }

    if ((bool)$input->getOption('summary') === true) {
      $request = $this->requestFactory->createRequest('GET', "https://api.kahu.app/v0/reports/{$reportId}/summary");
      $response = $this->client->sendRequest($request);

      $body = (string)$response->getBody();
      if (str_starts_with($response->getHeaderLine('content-type'), 'application/json') === true) {
        $body = Json::fromString($body, true);
      }

      if ($response->getStatusCode() !== Http::OK) {
        $output->writeln('Error: Unexpected API error (code ' . $response->getStatusCode() . ')');
        if (is_array($body) === true && isset($body['error']['message']) === true) {
          $output->writeln('Message: ' . $body['error']['message']);
        }
      }

      if (is_array($body) === false) {
        $output->writeln('Invalid API response format');

        return Command::FAILURE;
      }

      $table = new Table($output);
      $table
        ->setHeaderTitle('Report Summary')
        ->setHeaders(['Entry', 'Count'])
        ->addRows(
          array_reduce(
            array_keys($body['data']),
            static function (array $carry, string $key) use ($body): array {
              if (in_array($key, ['createdAt', 'finishedAt'], true) === true) {
                return $carry;
              }

              if (is_array($body['data'][$key]) === true) {
                foreach ($body['data'][$key] as $name => $count) {
                  $carry[] = ["{$key}.{$name}", $count];
                }

                return $carry;
              }

              $carry[] = [$key, $body['data'][$key]];

              return $carry;
            },
            []
          )
        )
        ->render();
    }

    if ((bool)$input->getOption('advisories') === true) {
      $request = $this->requestFactory->createRequest('GET', "https://api.kahu.app/v0/reports/{$reportId}/advisories");
      $response = $this->client->sendRequest($request);

      $body = (string)$response->getBody();
      if (str_starts_with($response->getHeaderLine('content-type'), 'application/json') === true) {
        $body = Json::fromString($body, true);
      }

      if ($response->getStatusCode() !== Http::OK) {
        $output->writeln('Error: Unexpected API error (code ' . $response->getStatusCode() . ')');
        if (is_array($body) === true && isset($body['error']['message']) === true) {
          $output->writeln('Message: ' . $body['error']['message']);
        }
      }

      if (is_array($body) === false || isset($body['data']['advisories']) === false) {
        $output->writeln('Invalid API response format');

        return Command::FAILURE;
      }

      $table = new Table($output);
      $table
        ->setHeaderTitle('Package Advisories')
        ->setHeaders(['Package', 'Title', 'CVE', 'Link'])
        ->addRows(
          array_reduce(
            $body['data']['advisories'],
            static function (array $carry, array $entries) use ($body): array {
              foreach ($entries as $entry) {
                $carry[] = [
                  $entry['packageName'],
                  $entry['title'],
                  $entry['cve'] ?? 'n/a',
                  $entry['link']
                ];
              }

              return $carry;
            },
            []
          )
        )
        ->render();
    }

    if ((bool)$input->getOption('sbom') === true) {
      $request = $this->requestFactory->createRequest('GET', "https://api.kahu.app/v0/reports/{$reportId}/sbom");
      $response = $this->client->sendRequest($request);

      $body = (string)$response->getBody();
      if (str_starts_with($response->getHeaderLine('content-type'), 'application/json') === true) {
        $body = Json::fromString($body, true);
      }

      if ($response->getStatusCode() !== Http::OK) {
        $output->writeln('Error: Unexpected API error (code ' . $response->getStatusCode() . ')');
        if (is_array($body) === true && isset($body['error']['message']) === true) {
          $output->writeln('Message: ' . $body['error']['message']);
        }
      }

      if (is_array($body) === false || isset($body['data']['packages']) === false) {
        $output->writeln('Invalid API response format');

        return Command::FAILURE;
      }

      $table = new Table($output);
      $table
        ->setHeaderTitle('Software Bill Of Materials')
        ->setHeaders(['Package', 'Version', 'Latest version', 'Last update', 'Advisories', 'License', 'Flags'])
        ->addRows(
          array_reduce(
            $body['data']['packages'],
            static function (array $carry, array $entry): array {
              $carry[] = [
                $entry['name'],
                $entry['version']['installed'],
                $entry['version']['available'] ?? 'n/a',
                $entry['lastUpdate'] === null ? 'n/a' : date('Y-m-d', strtotime($entry['lastUpdate'])),
                $entry['advisories'] === null ? 'n/a' : count($entry['advisories']),
                $entry['license'] === null ?
                  'n/a' :
                  implode(
                    ', ',
                    array_column($entry['license'], 'name')
                  ),
                implode(', ', $entry['flags'])
              ];

              return $carry;
            },
            []
          )
        )
        ->render();
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
