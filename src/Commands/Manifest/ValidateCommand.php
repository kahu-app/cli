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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\SyntaxError;
use Symfony\Component\Filesystem\Path;
use Teapot\StatusCode\Http;

#[AsCommand('manifest:validate', 'Validate the analysis report using expression-based rules')]
final class ValidateCommand extends Command {
  private ClientInterface $client;
  private RequestFactoryInterface $requestFactory;
  private StreamFactoryInterface $streamFactory;

  protected function configure(): void {
    $this
      ->addOption(
        'lint',
        null,
        InputOption::VALUE_NONE,
        'Lint the rule argument to check for errors'
      )
      ->addOption(
        'file',
        'f',
        InputOption::VALUE_REQUIRED,
        'Load rule expressions from a file'
      )
      ->addOption(
        'rule',
        'r',
        InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
        'Rule expression to be evaluated and process report contents'
      )
      ->addArgument(
        'reportId',
        InputArgument::REQUIRED,
        'The report identification (unique 40-chars long string)'
      );
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $reportId = $input->getArgument('reportId');
    if (is_string($reportId) === false || preg_match('/^[a-z0-9]{40}$/', $reportId) !== 1) {
      throw new InvalidArgumentException('Invalid "reportId" argument');
    }

    $inlineRules = $input->getOption('rule');
    $output->writeln(
      sprintf(
        'Got %d inline rules',
        count($inlineRules)
      ),
      OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_DEBUG
    );

    $filePath = $input->getOption('file');
    $fileRules = [];
    if ($filePath !== null) {
      $filePath = Path::makeAbsolute($filePath, __ROOT__);
      if (is_file($filePath) === false) {
        $output->writeln(
          sprintf(
            'Error: File "%s" could not be found',
            $filePath
          ),
          OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_VERBOSE
        );

        return Command::FAILURE;
      }

      if (is_readable($filePath) === false) {
        $output->writeln(
          sprintf(
            'Error: File "%s" is not readable',
            $filePath
          ),
          OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_VERBOSE
        );

        return Command::FAILURE;
      }

      $fileRules = Json::fromFile($filePath, true);
      $output->writeln(
        sprintf(
          'Loaded %d rules from "%s"',
          count($fileRules),
          $filePath
        ),
        OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_DEBUG
      );
    }

    $rules = [];
    foreach ($inlineRules as $idx => $expression) {
      $ruleNum = $idx + 1;
      $rules[] = [
        'name' => sprintf('Inline rule #%d', $ruleNum),
        'exp' => $expression,
        'msg' => sprintf('Inline rule #%d failed: "%s"', $ruleNum, $expression)
      ];
    }

    foreach ($fileRules as $idx => $rule) {
      $ruleNum = $idx + 1;
      $rules[] = [
        'name' => $rule['name'] ?? sprintf('File rule #%d', $ruleNum),
        'exp' => $rule['exp'],
        'msg' => $rule['msg'] ?? sprintf('File rule #%d failed: "%s"', $ruleNum, $rule['exp'])
      ];
    }

    $endpoints = [
      'advisories',
      'details',
      'sbom',
      'summary'
    ];

    $expressionLanguage = new ExpressionLanguage();
    if ((bool)$input->getOption('lint') === true) {
      try {
        foreach ($rules as $rule) {
          $output->write(
            "Linting rule \"{$rule['name']}\": ",
            false,
            OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_DEBUG
          );

          $expressionLanguage->lint(
            $rule['exp'],
            $endpoints
          );

          $output->writeln(
            'PASS',
            OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_DEBUG
          );
        }
      } catch (SyntaxError $error) {
        $output->writeln(
          'FAIL',
          OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_DEBUG
        );

        $output->writeln(
          sprintf(
            'Syntax error: %s',
            $error->getMessage()
          )
        );

        return Command::FAILURE;
      }

      $output->writeln('No errors were found.');

      return Command::SUCCESS;
    }

    $variables = [];
    // async it!
    foreach ($endpoints as $endpoint) {
      $output->writeln(
        sprintf(
          'Retrieving data from api.kahu.app: %s',
          $endpoint
        ),
        OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_DEBUG
      );

      $request = $this->requestFactory->createRequest(
        'GET',
        "https://api.kahu.app/v0/reports/{$reportId}/{$endpoint}"
      );
      $response = $this->client->sendRequest($request);
      if ($response->getStatusCode() !== Http::OK) {
        $output->writeln(
          sprintf(
            'Failed to retrieve "%s" from api.kahu.app',
            $endpoint
          )
        );

        return Command::FAILURE;
      }

      $body = Json::fromString((string)$response->getBody(), true);
      if (isset($body['data']) === false) {}

      $variables[$endpoint] = $body['data'];
    }

    foreach ($rules as $rule) {
      $output->write(
        "Evaluating rule \"{$rule['name']}\": ",
        false,
        OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_DEBUG
      );

      if ($expressionLanguage->evaluate($rule['exp'], $variables) === false) {
        $output->writeln(
          'FAIL',
          OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_DEBUG
        );

        if (isset($rule['msg']) === true && $output->isQuiet() === false) {
          $output->writeln($rule['msg']);
        }

        return Command::FAILURE;
      }

      $output->writeln(
        'PASS',
        OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_DEBUG
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

