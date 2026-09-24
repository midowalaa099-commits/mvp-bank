<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected $seed = true;

    public function createApplication(): Application
    {
        $app = parent::createApplication();

        if ($app->configurationIsCached()
            || $app['config']->get('database.default') !== 'mysql'
            || $app['config']->get('database.connections.mysql.database') !== 'mvp_bank_test'
            || $app['config']->get('database.connections.mysql.url')) {
            throw new RuntimeException('Tests require uncached configuration and the dedicated mvp_bank_test MySQL database.');
        }

        return $app;
    }
}
