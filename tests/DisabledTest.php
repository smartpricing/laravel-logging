<?php

declare(strict_types=1);

namespace Smartness\LaravelLogging\Tests;

use Illuminate\Log\LogManager;
use Orchestra\Testbench\TestCase;
use Smartness\LaravelLogging\StructuredLoggingServiceProvider;

final class DisabledTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [StructuredLoggingServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('structured-logging.enabled', false);
    }

    public function test_installation_does_not_enable_or_change_logging(): void
    {
        self::assertInstanceOf(LogManager::class, $this->app->make('log'));
        self::assertNull($this->app['config']->get('logging.channels.single.tap'));
        self::assertFalse($this->app['config']->get('structured-logging.enabled'));
    }
}
