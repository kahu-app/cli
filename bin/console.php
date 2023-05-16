#!/usr/bin/env php
<?php
declare(strict_types = 1);

date_default_timezone_set('UTC');
setlocale(LC_ALL, 'en_US.UTF8');
error_reporting(E_ALL);

// ensure correct absolute path
chdir(dirname($argv[0]));

require_once __DIR__ . '/../vendor/autoload.php';

use Composer\InstalledVersions;
use Kahu\Cli\Commands\CheckCommand;
use Kahu\Cli\Commands\ShowCommand;
use Kahu\Cli\Commands\UpdateCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\CommandLoader\FactoryCommandLoader;

define(
  '__VERSION__',
  sprintf(
    '%s@%s',
    InstalledVersions::getPrettyVersion('kahu-app/cli') ?? 'unknown',
    substr(InstalledVersions::getReference('kahu-app/cli') ?? 'unknown', 0, 7)
  )
);

$client = new GuzzleHttp\Client(
  [
    'base_uri' => 'https://api.kahu.app/v1',
    'allow_redirects' => [
      'max' => 10,
      'strict' => true,
      'referer' => true,
      'protocols' => ['https'],
      'track_redirects' => true
    ],
    'headers' => [
      'Accept' => 'application/json',
      'Authorization' => 'Bearer X-AUTH-TOKEN',
      'User-Agent' => sprintf(
        'kahu-cli/%s (php/%s; %s)',
        __VERSION__,
        PHP_VERSION,
        PHP_OS_FAMILY
      )
    ]
  ]
);

$httpFactory = new GuzzleHttp\Psr7\HttpFactory();

$app = new Application('kahu.app console', __VERSION__);
$app->setCommandLoader(
  new FactoryCommandLoader(
    [
      CheckCommand::getDefaultName() => static function () use ($client, $httpFactory): CheckCommand {
        return new CheckCommand($client, $httpFactory, $httpFactory);
      },
      ShowCommand::getDefaultName() => static function () use ($client, $httpFactory): ShowCommand {
        return new ShowCommand($client, $httpFactory, $httpFactory);
      },
      UpdateCommand::getDefaultName() => static function (): UpdateCommand {
        return new UpdateCommand();
      }
    ]
  )
);

$app->run();