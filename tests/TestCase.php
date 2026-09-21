<?php

namespace NoriaLabs\Pay\Tests;

use NoriaLabs\Pay\Providers\PayServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PayServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('noria-pay.url', 'https://pay.noria.test');
        $app['config']->set('noria-pay.key', 'pay_test_abcdefghijklmnopqrstuvwx');
        $app['config']->set('noria-pay.webhook_secret', 'whsec_testsecret');
    }
}
