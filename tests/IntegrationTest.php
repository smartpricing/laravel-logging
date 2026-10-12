<?php

declare(strict_types=1);

namespace Smartness\LaravelLogging\Tests;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Orchestra\Testbench\TestCase;
use Psr\Log\InvalidArgumentException;
use Psr\Log\NullLogger;
use ReflectionProperty;
use RuntimeException;
use Smartness\LaravelLogging\Context\RequestContext;
use Smartness\LaravelLogging\Context\TraceContext;
use Smartness\LaravelLogging\Http\CorrelateRequest;
use Smartness\LaravelLogging\StructuredLoggingServiceProvider;
use Symfony\Component\HttpFoundation\Response;

final class IntegrationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/structured-logging-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (glob($this->directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    protected function getPackageProviders($app): array
    {
        return [StructuredLoggingServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('structured-logging', array_replace($app['config']->get('structured-logging', []), [
            'enabled' => true, 'service' => 'test-service', 'environment' => 'testing',
            'channels' => ['file', 'second', 'null', 'stack'],
            'fallback_path' => $this->directory.'/fallback.log',
        ]));
        $app['config']->set('logging', [
            'default' => 'file',
            'channels' => [
                'file' => ['driver' => 'monolog', 'handler' => StreamHandler::class, 'level' => 'debug', 'with' => ['stream' => $this->directory.'/file.log']],
                'second' => ['driver' => 'monolog', 'handler' => StreamHandler::class, 'level' => 'warning', 'with' => ['stream' => $this->directory.'/second.log']],
                'stack' => ['driver' => 'stack', 'channels' => ['file', 'second']],
                'null' => ['driver' => 'monolog', 'handler' => NullHandler::class],
            ],
        ]);
    }

    private function entries(string $channel = 'file'): array
    {
        $path = $this->directory.'/'.$channel.'.log';

        return ! is_file($path) ? [] : array_map(
            static fn ($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
        );
    }

    public function test_native_manager_non_stack_default_and_reusable_channels(): void
    {
        self::assertInstanceOf(LogManager::class, Log::getFacadeRoot());
        Log::info('default');
        $channel = Log::channel('second');
        $channel->warning('one');
        $channel->warning('two');
        self::assertSame(['default'], array_column($this->entries(), 'message'));
        self::assertSame(['one', 'two'], array_column($this->entries('second'), 'message'));
        self::assertSame(['second', 'second'], array_column($this->entries('second'), 'component'));
    }

    public function test_filtering_null_and_stack_routing(): void
    {
        Log::channel('second')->info('filtered');
        Log::channel('null')->error('discard');
        Log::info('default');
        Log::stack(['file', 'second'])->warning('both');
        self::assertSame(['default', 'both'], array_column($this->entries(), 'message'));
        self::assertSame(['both'], array_column($this->entries('second'), 'message'));
    }

    public function test_native_context_and_stringable_message(): void
    {
        Log::shareContext(['correlation' => 'shared']);
        Log::channel('file')->withContext(['scope' => 'specific'])->info(new class implements \Stringable
        {
            public function __toString(): string
            {
                return 'stringable';
            }
        });
        Log::channel('second')->warning('other');
        self::assertSame('stringable', $this->entries()[0]['message']);
        self::assertSame(['correlation' => 'shared', 'scope' => 'specific'], $this->entries()[0]['context']);
        self::assertSame(['correlation' => 'shared'], $this->entries('second')[0]['context']);
    }

    public function test_monolog_rejects_unsupported_levels(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Log::channel('file')->getLogger()->log('banana', 'invalid');
    }

    public function test_request_id_is_stable_and_different_for_next_request(): void
    {
        (new ReflectionProperty($this->app, 'isRunningInConsole'))->setValue($this->app, false);
        $first = Request::create('/first?password=SECRET', 'POST', ['password' => 'SECRET']);
        $this->app->instance('request', $first);
        $response = (new CorrelateRequest)->handle($first, static function () {
            Log::info('first');
            Log::info('second');

            return new Response('ok');
        });
        $second = Request::create('/second');
        $this->app->instance('request', $second);
        Log::info('next request');
        $entries = $this->entries();
        self::assertSame($entries[0]['request_id'], $entries[1]['request_id']);
        self::assertSame($entries[0]['request_id'], $response->headers->get('X-Request-Id'));
        self::assertNotSame($entries[0]['request_id'], $entries[2]['request_id']);
        self::assertStringNotContainsString('SECRET', json_encode($entries));
        self::assertArrayNotHasKey('request_payload', $entries[0]);
    }

    public function test_request_header_validation_and_optional_trace_provider(): void
    {
        $request = Request::create('/');
        $request->headers->set('X-Request-Id', 'valid-id_123');
        self::assertSame('valid-id_123', RequestContext::id($request));
        $bad = Request::create('/');
        $bad->headers->set('X-Request-Id', str_repeat('x', 200));
        self::assertSame(32, strlen(RequestContext::id($bad)));
        $this->app->instance(TraceContext::class, new class extends TraceContext
        {
            public function current(): array
            {
                return ['trace_id' => str_repeat('a', 32), 'span_id' => str_repeat('b', 16)];
            }
        });
        Log::info('traced');
        self::assertSame(str_repeat('a', 32), $this->entries()[0]['trace_id']);
    }

    public function test_provider_registers_http_middleware_in_the_kernel(): void
    {
        (new ReflectionProperty($this->app, 'isRunningInConsole'))->setValue($this->app, false);
        $this->app['router']->get('/logging-probe', static function () {
            Log::info('http route');

            return new Response('ok');
        });
        $response = $this->get('/logging-probe', ['X-Request-Id' => 'from-ingress']);
        $response->assertOk()->assertHeader('X-Request-Id', 'from-ingress');
        self::assertSame('from-ingress', $this->entries()[0]['request_id']);
        self::assertSame('logging-probe', $this->entries()[0]['route']);
    }

    public function test_wildcard_configuration_keeps_null_and_custom_channels_untouched(): void
    {
        $this->app['config']->set('structured-logging.channels', ['*']);
        $this->app['config']->set('logging.channels.custom', ['driver' => 'custom']);
        (new StructuredLoggingServiceProvider($this->app))->boot();
        self::assertNull($this->app['config']->get('logging.channels.custom.tap'));
        self::assertNull($this->app['config']->get('logging.channels.null.tap'));
        Log::info('one');
        self::assertCount(1, $this->entries());
    }

    public function test_enabled_package_rejects_a_replacement_log_manager(): void
    {
        $this->app->instance('log', new NullLogger);
        $this->expectException(\LogicException::class);
        (new StructuredLoggingServiceProvider($this->app))->boot();
    }

    private function job(string $id): Job
    {
        $job = $this->createStub(Job::class);
        $job->method('getJobId')->willReturn($id);
        $job->method('getQueue')->willReturn('test-queue');
        $job->method('attempts')->willReturn(2);

        return $job;
    }

    public function test_nested_jobs_restore_parent_and_reset_after_success(): void
    {
        $outer = $this->job('outer');
        $inner = $this->job('inner');
        $events = $this->app['events'];
        $events->dispatch(new JobProcessing('sync', $outer));
        Log::info('outer');
        $events->dispatch(new JobProcessing('sync', $inner));
        Log::info('inner');
        $events->dispatch(new JobProcessed('sync', $inner));
        Log::info('outer again');
        $events->dispatch(new JobProcessed('sync', $outer));
        Log::info('outside');
        $entries = $this->entries();
        self::assertSame(['outer', 'inner', 'outer'], array_column(array_slice($entries, 0, 3), 'job_id'));
        self::assertArrayNotHasKey('job_id', $entries[3]);
        self::assertSame(2, $entries[0]['attempt']);
    }

    public function test_failed_jobs_and_loop_boundaries_do_not_leak_context(): void
    {
        $events = $this->app['events'];
        $job = $this->job('failed');
        $events->dispatch(new JobProcessing('sync', $job));
        $events->dispatch(new JobFailed('sync', $job, new RuntimeException('failed')));
        $events->dispatch(new JobExceptionOccurred('sync', $job, new RuntimeException('failed')));
        Log::info('after failure');
        $events->dispatch(new JobProcessing('sync', $this->job('stale')));
        $events->dispatch(new Looping('sync', 'test'));
        Log::info('after loop');
        foreach ($this->entries() as $entry) {
            self::assertArrayNotHasKey('job_id', $entry);
        }
    }

    public function test_primary_write_failure_falls_back_with_valid_redacted_json(): void
    {
        $this->app['config']->set('logging.channels.file.with.stream', $this->directory);
        Log::error('write failure', ['password' => 'SECRET']);
        $entries = $this->entries('fallback');
        self::assertCount(1, $entries);
        self::assertSame('[REDACTED]', $entries[0]['context']['password']);
        self::assertSame('file', $entries[0]['component']);
    }

    public function test_exception_reported_after_job_event_keeps_its_job_id(): void
    {
        $job = $this->job('failed-job');
        $exception = new RuntimeException('failure');
        $this->app['events']->dispatch(new JobProcessing('sync', $job));
        $this->app['events']->dispatch(new JobExceptionOccurred('sync', $job, $exception));
        Log::error('reported later', ['exception' => $exception]);
        Log::info('unrelated');
        self::assertSame('failed-job', $this->entries()[0]['job_id']);
        self::assertArrayNotHasKey('job_id', $this->entries()[1]);
    }

    public function test_unknown_or_missing_trace_sdk_does_not_block_logging(): void
    {
        Log::info('without tracing');
        self::assertArrayNotHasKey('trace_id', $this->entries()[0]);
    }

    public function test_handler_can_be_forgotten_and_reopened_after_prefork(): void
    {
        Log::info('before');
        foreach (Log::getChannels() as $name => $channel) {
            $channel->getLogger()->close();
            Log::forgetChannel($name);
        }
        Log::info('after');
        self::assertSame(['before', 'after'], array_column($this->entries(), 'message'));
    }

    public function test_filtered_non_bubbling_channel_does_not_block_later_stack_handlers(): void
    {
        $this->app['config']->set('logging.channels.critical', [
            'driver' => 'single', 'path' => $this->directory.'/critical.log',
            'level' => 'critical', 'bubble' => false,
        ]);
        $this->app['config']->set('structured-logging.channels', ['*']);
        (new StructuredLoggingServiceProvider($this->app))->boot();
        $stack = Log::stack(['file', 'critical', 'second']);
        $stack->warning('pass the filtered channel');
        $stack->critical('stop at the critical channel');
        self::assertSame(['pass the filtered channel'], array_column($this->entries('second'), 'message'));
        self::assertSame(['stop at the critical channel'], array_column($this->entries('critical'), 'message'));
        self::assertCount(2, $this->entries());
    }
}
