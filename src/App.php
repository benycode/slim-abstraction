<?php

declare(strict_types=1);

namespace Slim\Abstraction;

use DI\ContainerBuilder;
use Slim\App as SlimApp;
use React\EventLoop\Loop;
use React\Socket\SocketServer;
use React\Http\Server as HttpServer;
use Psr\Http\Message\ServerRequestInterface as Request;

final class App
{
    public static function run(array $actionPath, array $dependencies) : void
    {
        $container = (new ContainerBuilder)
            ->addDefinitions(
                \array_merge(
                    DI::create($actionPath),
                    $dependencies,
                ),
            )
            ->build();

        $app = $container
            ->get(SlimApp::class);

        $loop = Loop::get();

        $server = new HttpServer(
            function (Request $request) use ($app) {
                return $app
                    ->handle($request);
            }
        );

        $uri = \getenv('HTTP_SERVER_URI') ? \getenv('HTTP_SERVER_URI') : '0.0.0.0:80';

        $socket = new SocketServer($uri);

        $server
            ->listen($socket);

        echo \sprintf('Server running at http://%s', $uri) . PHP_EOL;

        $loop
            ->run();
    }
}
