<?php

declare(strict_types=1);

namespace BenyCode\Slim\Abstraction;

use Slim\App;
use Monolog\Logger;
use Slim\CallableResolver;
use Psr\Log\LoggerInterface;
use Slim\Factory\AppFactory;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriFactoryInterface;
use Slim\Exception\HttpNotFoundException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Slim\Interfaces\RouteCollectorInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Slim\Http\Factory\DecoratedResponseFactory;
use BenyCode\Slim\Middleware\ExceptionMiddleware;
use BenyCode\Slim\Middleware\SettingsUpMiddleware;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Slim\AnnotationRouter\AnnotationRouteCollector;
use BenyCode\Slim\Middleware\InfoEndpointMiddleware;
use BenyCode\Slim\Middleware\APISIXRegisterMiddleware;
use BenyCode\Slim\Middleware\LeaderElectionMiddleware;
use BenyCode\Slim\Middleware\HealthCheckEndpointMiddleware;
use BenyCode\Slim\Middleware\OnePathXApiTokenProtectionMiddleware as HealthCheckEndpointProtectionMiddleware;

final class DI
{
    public static function create(array $actionPath) : array
    {
        return [
            'settings' => function (ContainerInterface $container) {
                $env = [];

                foreach ($_ENV as $key => $value) {
                    if (is_string($value) && null !== json_decode($value)) {
                        $decodedValue = json_decode($value, true);
                        $env[$key] = $decodedValue;
                    } else {
                        $env[$key] = $value;
                    }
                }

                return $env;
            },

            CallableResolver::class => fn (ContainerInterface $container) => new CallableResolver($container),

            RouteCollectorInterface::class => function (ContainerInterface $container) use ($actionPath) {

                $debug = $container->get('settings')['api_debug'];

                $collector = new AnnotationRouteCollector($container->get(DecoratedResponseFactory::class), $container->get(CallableResolver::class));
                $collector->setDefaultControllersPath(...$actionPath);
                $collector->collectRoutes($debug);

                return $collector;
            },

            App::class => function (ContainerInterface $container) {

                $app = AppFactory::createFromContainer($container);

                $app
                    ->get(
                        '/{any:.*}',
                        function (ServerRequestInterface $request, ResponseInterface $response) {
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

                $app->addBodyParsingMiddleware();
                $app->addRoutingMiddleware();
                $app->add(SettingsUpMiddleware::class);
                $app->add(ExceptionMiddleware::class);

                return $app;
            },

            // HTTP factories
            ResponseFactoryInterface::class => fn (ContainerInterface $container) => $container->get(Psr17Factory::class),

            ServerRequestFactoryInterface::class => fn (ContainerInterface $container) => $container->get(Psr17Factory::class),

            StreamFactoryInterface::class => fn (ContainerInterface $container) => $container->get(Psr17Factory::class),

            UriFactoryInterface::class => fn (ContainerInterface $container) => $container->get(Psr17Factory::class),

            LoggerInterface::class => function (ContainerInterface $container) {
                $settings = $container->get('settings')['api_logger'];
                $logger = new Logger('app');

                $level = $settings['level'];
                $streamHandler  = new StreamHandler('php://stdout', $level);
                $streamHandler ->setFormatter(new LineFormatter(null, null, false, true));
                $logger->pushHandler($streamHandler);

                return $logger;
            },
            ExceptionMiddleware::class => function (ContainerInterface $container) {
                $settings = $container->get('settings')['api_error'];

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
                        'info_endpoint' => $settings['api_info']['endpoint'],
                    ],
                    $settings['api_info']['version'],
                );
            },
            HealthCheckEndpointMiddleware::class => function (ContainerInterface $container) {
                $settings = $container
                    ->get('settings')
                ;

                return new HealthCheckEndpointMiddleware(
                    [
                        'health_endpoint' => $settings['api_health']['endpoint'],
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
                        'path' => $settings['api_health']['endpoint'],
                        'x-api-token' => $settings['api_health']['protection_token'],
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
                        'leader_election_endpoint' => $settings['api_health']['endpoint'],
                        'etcd_endpoint' => $settings['api_leadership']['etcd_endpoint'],
                        'alection_frequency' => $settings['api_leadership']['alection_frequency'],
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
                        'register_endpoint' => $settings['api_health']['endpoint'],
                        'service_id' => $settings['api_apisix']['service_id'],
                        'service' => $settings['api_apisix']['service'],
                        'route' => $settings['api_apisix']['route'],
                        'api_admin_secret' => $settings['api_apisix']['api_admin_secret'],
                        'api_endpoint' => $settings['api_apisix']['api_endpoint'],
                    ],
                    $container->get(LoggerInterface::class),
                );
            },
        ];
    }
}
