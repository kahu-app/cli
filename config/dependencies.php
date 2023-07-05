<?php
declare(strict_types = 1);

use DI\ContainerBuilder;
use Jay\Json;
use Kahu\OAuth2\Client\Provider\Kahu;
use League\Config\Configuration;
use League\Config\ConfigurationInterface;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Nette\Schema\Expect;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use STS\Backoff\Backoff;
use STS\Backoff\Strategies\ExponentialStrategy;
use Symfony\Component\Filesystem\Path;

return static function (ContainerBuilder $builder): void {
  $builder->addDefinitions(
    [
      Backoff::class => static function (ContainerInterface $container): Backoff {
        return new Backoff(10, new ExponentialStrategy(750), 10000, true);
      },
      ConfigurationInterface::class => static function (ContainerInterface $container): ConfigurationInterface {
        // config schema
        $config = new Configuration(
          [
            'authFile' => Expect::string()->required()
          ]
        );

        // actual values
        $config->merge(
           [
            'authFile' => sprintf(
              '%s/.config/kahu/auth.json',
              Path::getHomeDirectory()
            )
          ]
        );

        return $config->reader();
      },
      AccessTokenInterface::class => static function (ContainerInterface $container): AccessTokenInterface {
        $config = $container->get(ConfigurationInterface::class);

        $authFile = $config->get('authFile');
        if (is_string($authFile) === false) {
          throw new RuntimeException('Invalid authentication file path');
        }

        if (
          file_exists($authFile) === false ||
          is_readable($authFile) === false
        ) {
          return new AccessToken(['access_token' => 'unauthenticated']);
        }

        $json = Json::fromFile($authFile, true);

        return new AccessToken($json);
      },
      ClientInterface::class => static function (ContainerInterface $container): ClientInterface {
        $accessToken = $container->get(AccessTokenInterface::class);

        return new GuzzleHttp\Client(
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
              'Authorization' => 'Bearer ' . $accessToken->getToken() ?: 'unauthenticated',
              'User-Agent' => sprintf(
                'kahu-cli/%s (php/%s; %s)',
                __VERSION__,
                PHP_VERSION,
                PHP_OS_FAMILY
              )
            ]
          ]
        );
      },
      Kahu::class => static function (ContainerInterface $container): Kahu {
        // "cli.kahu.app" OAuth app
        return new Kahu(
          [
            'clientId' => 'fb738462e4c64c37a96c5488999a07a7',
            'clientSecret' => 'd5719d8ef4d011eda05b0242ac120003'
          ]
        );
      },
      RequestFactoryInterface::class => static function (ContainerInterface $container): RequestFactoryInterface {
        return new GuzzleHttp\Psr7\HttpFactory();
      },
      StreamFactoryInterface::class => static function (ContainerInterface $container): StreamFactoryInterface {
        return new GuzzleHttp\Psr7\HttpFactory();
      }
    ]
  );
};
