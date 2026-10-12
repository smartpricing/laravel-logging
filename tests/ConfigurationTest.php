<?php

declare(strict_types=1);

namespace Smartness\LaravelLogging\Tests;

use Illuminate\Config\Repository;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Log\LogServiceProvider;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Smartness\LaravelLogging\StructuredLoggingServiceProvider;

final class ConfigurationTest extends TestCase
{
    #[DataProvider('invalidOptions')]
    public function test_invalid_options_abort_boot_before_any_channel_can_fall_back_to_plaintext(array $options, string $key): void
    {
        $app = new Application(__DIR__.'/..');
        $app->instance('config', new Repository([
            'structured-logging' => $options + ['enabled' => true],
            'logging' => ['default' => 'single', 'channels' => [
                'single' => ['driver' => 'single', 'path' => 'php://memory'],
            ]],
        ]));
        $app->instance('events', new Dispatcher($app));
        $app->register(LogServiceProvider::class);
        $app->register(StructuredLoggingServiceProvider::class);
        try {
            $app->boot();
            self::fail('Invalid options must not reach Laravel channel resolution.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString($key, $exception->getMessage());
            self::assertFalse($app->resolved('log'));
        } finally {
            $app->flush();
            Application::setInstance(null);
        }
    }

    public static function invalidOptions(): array
    {
        return [
            [['max_line_bytes' => 100], 'max_line_bytes'],
            [['max_context_bytes' => '4096'], 'max_context_bytes'],
            [['max_depth' => 33], 'max_depth'],
            [['max_items' => 0], 'max_items'],
            [['channels' => 'stdout'], 'channels'],
            [['channels' => [null]], 'channels'],
            [['request_context' => 'false'], 'request_context'],
            [['service' => []], 'service'],
            [['fallback_path' => []], 'fallback_path'],
            [['sensitive_fields_mode' => 'typo'], 'sensitive_fields_mode'],
            [['sensitive_fields' => 'password'], 'sensitive_fields'],
            [['sensitive_fields' => [null]], 'sensitive_fields'],
        ];
    }
}
