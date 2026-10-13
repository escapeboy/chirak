<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * With `artisan optimize`/`config:cache`, phpunit.xml's SQLite settings are ignored and RefreshDatabase
     * migrates the real database fresh. That wiped the local MariaDB on 2026-10-13; refuse before any trait runs.
     */
    public function createApplication()
    {
        $app = parent::createApplication();
        if ($app->configurationIsCached() || $app['config']->get('database.default') !== 'sqlite') {
            throw new \RuntimeException('Refusing to run tests against a non-SQLite database (config cached? run `php artisan config:clear`).');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }
}
