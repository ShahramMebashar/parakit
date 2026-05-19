<?php
declare(strict_types=1);

namespace Froshly\Parakit\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Froshly\Parakit\ParakitServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            \Barryvdh\DomPDF\ServiceProvider::class,
            ParakitServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
