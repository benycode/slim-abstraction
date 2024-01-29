<?php

declare(strict_types=1);

namespace BenyCode\Slim\Abstraction;

use Slim\App;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Slim\Factory\AppFactory;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use BenyCode\Slim\Middleware\ExceptionMiddleware;
use BenyCode\Slim\Middleware\SettingsUpMiddleware;
use Psr\Http\Message\ServerRequestFactoryInterface;
use BenyCode\Slim\Middleware\InfoEndpointMiddleware;
use BenyCode\Slim\Middleware\APISIXRegisterMiddleware;
use BenyCode\Slim\Middleware\LeaderElectionMiddleware;
use BenyCode\Slim\Middleware\HealthCheckEndpointMiddleware;
use BenyCode\Slim\Middleware\OnePathXApiTokenProtectionMiddleware as HealthCheckEndpointProtectionMiddleware;

final class DI
{
    public static function create(string $configPath) : array
    {
        return [
            'settings' => fn () => require $configPath . '/settings.php',

            App::class => function (ContainerInterface $container) use ($configPath) {

                $app = AppFactory::createFromContainer($container);

                if (file_exists($configPath . '/routes.php')) {
                    (require $configPath . '/routes.php')($app);
                }

                $app
                    ->get(
                        '/{any:.*}',
                        function (Request $request, Response $response) {
                            throw new HttpNotFoundException($request);
                        },
                    )
                    ->add(HealthCheckEndpointMiddleware::class)
                    ->add(InfoEndpointMiddleware::class)
                    ->add(APISIXRegisterMiddleware::class)
                    ->add(LeaderElectionMiddleware::class)
                    ->add(HealthCheckEndpointProtectionMiddleware::class)
                    ->setName('any')
                ;

                if (file_exists($configPath . '/before.middleware.php')) {
                    (require $configPath . '/before.middleware.php')($app);
                }

                if (file_exists($configPath . '/middleware.php')) {
                    (require $configPath . '/middleware.php')($app);
                } else {
                    $app->addBodyParsingMiddleware();
                    $app->addRoutingMiddleware();
                    $app->add(SettingsUpMiddleware::class);
                    $app->add(ExceptionMiddleware::class);
                }

                if (file_exists($configPath . '/after.middleware.php')) {
                    (require $configPath . '/after.middleware.php')($app);
                }

                return $app;
            },

            // HTTP factories
            ResponseFactoryInterface::class => fn (ContainerInterface $container) => $container->get(Psr17Factory::class),

            ServerRequestFactoryInterface::class => fn (ContainerInterface $container) => $container->get(Psr17Factory::class),

            StreamFactoryInterface::class => fn (ContainerInterface $container) => $container->get(Psr17Factory::class),

            UriFactoryInterface::class => fn (ContainerInterface $container) => $container->get(Psr17Factory::class),

            LoggerInterface::class => function (ContainerInterface $container) {
                $settings = $container->get('settings')['logger'];
                $logger = new Logger('app');

                $level = $settings['level'];
                $streamHandler  = new StreamHandler('php://stdout', $level);
                $streamHandler ->setFormatter(new LineFormatter(null, null, false, true));
                $logger->pushHandler($streamHandler);

                return $logger;
            },
            ExceptionMiddleware::class => function (ContainerInterface $container) {
                $settings = $container->get('settings')['error'];

                return new ExceptionMiddleware(
                    $container->get(ResponseFactoryInterface::class),
                    $container->get(LoggerInterface::class),
                    (bool)$settings['display_error_details'],
                );
            },
            SettingsUpMiddleware::class => function (ContainerInterface $container) {
                $settings = $container
                    ->get('settings')
                ;

                return new SettingsUpMiddleware(
                    $settings,
                );
            },
            InfoEndpointMiddleware::class => function (ContainerInterface $container) {
                $settings = $container
                    ->get('settings')
                ;

                return new InfoEndpointMiddleware(
                    [
                        'info_endpoint' => $settings['info']['endpoint'],
                    ],
                    $settings['info']['version'],
                );
            },
            HealthCheckEndpointMiddleware::class => function (ContainerInterface $container) {
                $settings = $container
                    ->get('settings')
                ;

                return new HealthCheckEndpointMiddleware(
                    [
                        'health_endpoint' => $settings['health']['endpoint'],
                    ],
                    $container->get(LoggerInterface::class),
                );
            },
            HealthCheckEndpointProtectionMiddleware::class => function (ContainerInterface $container) {
                $settings = $container
                    ->get('settings')
                ;

                return new HealthCheckEndpointProtectionMiddleware(
                    [
                        'path' => $settings['health']['endpoint'],
                        'x-api-token' => $settings['health']['protection_token'],
                    ],
                    $container->get(LoggerInterface::class),
                );
            },
            LeaderElectionMiddleware::class => function (ContainerInterface $container) {
                $settings = $container
                    ->get('settings')
                ;

                return new LeaderElectionMiddleware(
                    [
                        'leader_election_endpoint' => $settings['health']['endpoint'],
                        'etcd_endpoint' => $settings['leadership']['etcd_endpoint'],
                        'alection_frequency' => $settings['leadership']['alection_frequency'],
                    ],
                    $container->get(LoggerInterface::class),
                );
            },
            APISIXRegisterMiddleware::class => function (ContainerInterface $container) {

                $settings = $container
                    ->get('settings')
                ;

                return new APISIXRegisterMiddleware(
                    [
                        'register_endpoint' => $settings['health']['endpoint'],
                        'service_id' => $settings['APISIX']['service_id'],
                        'service' => $settings['APISIX']['service'],
                        'route' => $settings['APISIX']['route'],
                        'api_admin_secret' => $settings['APISIX']['api_admin_secret'],
                        'api_endpoint' => $settings['APISIX']['api_endpoint'],
                    ],
                    $container->get(LoggerInterface::class),
                );
            },
        ];
    }
}
