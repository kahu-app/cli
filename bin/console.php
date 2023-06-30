#!/usr/bin/env php
<?php
declare(strict_types = 1);

date_default_timezone_set('UTC');
setlocale(LC_ALL, 'en_US.UTF8');
error_reporting(E_ALL);

// ensure correct absolute path
chdir(dirname($argv[0]));

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Composer\InstalledVersions;
use DI\ContainerBuilder;
use Kahu\Cli\Commands\Auth;
use Kahu\Cli\Commands\Manifest;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\CommandLoader\ContainerCommandLoader;

define(
  '__VERSION__',
  sprintf(
    '%s@%s',
    InstalledVersions::getPrettyVersion('kahu/cli') ?? 'unknown',
    substr(InstalledVersions::getReference('kahu/cli') ?? 'unknown', 0, 7)
  )
);

define('__ROOT__', dirname(__DIR__));

// default PHP_ENV to "prod"
if (isset($_ENV['PHP_ENV']) === false) {
  $_ENV['PHP_ENV'] = 'prod';
}

// Instantiate PHP-DI ContainerBuilder
$containerBuilder = new ContainerBuilder();

// Set up dependencies
$dependencies = require_once dirname(__DIR__) . '/config/dependencies.php';
$dependencies($containerBuilder);

// Build PHP-DI Container instance
$container = $containerBuilder->build();

$app = new Application('kahu.app console', __VERSION__);
$app->setCommandLoader(
  new ContainerCommandLoader(
    $container,
    [
      Auth\LoginCommand::getDefaultName() => Auth\LoginCommand::class,
      Auth\LogoutCommand::getDefaultName() => Auth\LogoutCommand::class,
      Auth\RefreshCommand::getDefaultName() => Auth\RefreshCommand::class,
      Auth\StatusCommand::getDefaultName() => Auth\StatusCommand::class,
      Auth\TokenCommand::getDefaultName() => Auth\TokenCommand::class,
      Manifest\ReportCommand::getDefaultName() => Manifest\ReportCommand::class,
      Manifest\UploadCommand::getDefaultName() => Manifest\UploadCommand::class,
      Manifest\ValidateCommand::getDefaultName() => Manifest\ValidateCommand::class
    ]
  )
);

$app->run();
