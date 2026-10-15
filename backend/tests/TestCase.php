<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use LogicException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        if ($app['config']->get('database.default') !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.database') !== ':memory:'
            || $app['config']->get('cache.default') !== 'array') {
            throw new LogicException('Tests require an in-memory SQLite database and array cache. Check phpunit.xml before running tests.');
        }

        return $app;
    }
}
