<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        foreach ([
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
        ] as $name => $value) {
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
            putenv("{$name}={$value}");
        }

        $app = parent::createApplication();
        $connection = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$connection}.database");
        $url = $app['config']->get("database.connections.{$connection}.url");

        if ($connection !== 'sqlite' || $database !== ':memory:' || filled($url)) {
            throw new \RuntimeException(
                'Tests must use an isolated in-memory SQLite database; refusing to run against another database.'
            );
        }

        return $app;
    }
}
