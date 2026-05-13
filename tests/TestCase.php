<?php

declare(strict_types=1);

namespace QueueMonitor\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use QueueMonitor\QueueMonitorServiceProvider;

abstract class TestCase extends BaseTestCase
{
    /** @return class-string[] */
    protected function getPackageProviders($app): array
    {
        return [QueueMonitorServiceProvider::class];
    }
}
