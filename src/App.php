<?php

declare(strict_types=1);

namespace BenyCode\Slim\Abstraction;

use DI\ContainerBuilder;
use Slim\App as SlimApp;

final class App
{
    public static function run(array $actionPath, array $dependencies) : SlimApp
    {
        $container = (new ContainerBuilder)
            ->addDefinitions(
                \array_merge(
                    DI::create($actionPath),
                    $dependencies,
                ),
            )
            ->build()
        ;

        $app = $container
            ->get(SlimApp::class)
        ;

        $app
            ->run()
        ;

        return $app;
    }
}
