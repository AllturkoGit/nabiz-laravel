<?php

namespace Allturko\Nabiz\Tests;

use Allturko\Nabiz\NabizServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [NabizServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('nabiz.url', 'https://monitor.example.test');
        $app['config']->set('nabiz.key', 'test-proje');
        $app['config']->set('nabiz.secret', str_repeat('s', 64));
    }
}
