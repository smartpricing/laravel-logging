<?php

declare(strict_types=1);

namespace Smartness\LaravelLogging\Tests;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Log;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\Contracts\Client;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TickReceived;
use Laravel\Octane\Exceptions\TaskExceptionResult;
use Laravel\Octane\OctaneResponse;
use Laravel\Octane\OctaneServiceProvider;
use Laravel\Octane\RequestContext as OctaneRequestContext;
use Laravel\Octane\Worker;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use Smartness\LaravelLogging\Context\JobContext;
use Smartness\LaravelLogging\Context\TraceContext;
use Smartness\LaravelLogging\StructuredLoggingServiceProvider;
use Throwable;

final class OctaneTest extends TestCase
{
    private string $path;

    private array $responses = [];

    private array $errors = [];

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'octane-logging-');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unlink($this->path);
    }

    protected function getPackageProviders($app): array
    {
        return [OctaneServiceProvider::class, StructuredLoggingServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app->instance('env', 'production');
        $app['config']->set('structured-logging.enabled', true);
        $app['config']->set('structured-logging.channels', ['single', 'second']);
        $app['config']->set('logging.default', 'single');
        $app['config']->set('logging.channels.single', ['driver' => 'single', 'path' => $this->path]);
        $app['config']->set('logging.channels.second', ['driver' => 'single', 'path' => $this->path]);
        $app['config']->set('queue.default', 'sync');
    }

    private function worker(): Worker
    {
        // Simulate a provider warming both the logger and mutable context before boot.
        $this->app->make(JobContext::class);
        $this->app['log']->channel('single');
        $this->app['log']->channel('second');
        $factory = $this->createStub(ApplicationFactory::class);
        $factory->method('createApplication')->willReturn($this->app);
        $client = $this->createStub(Client::class);
        $client->method('respond')->willReturnCallback(function ($context, OctaneResponse $response): void {
            $this->responses[] = $response->response;
        });
        $client->method('error')->willReturnCallback(function (Throwable $error): void {
            $this->errors[] = $error;
        });
        $this->app->instance(Client::class, $client);
        $worker = new Worker($factory, $client);
        $worker->boot();

        return $worker;
    }

    private function request(Worker $worker, string $path, ?string $id = null): void
    {
        $worker->handle(Request::create($path, server: $id === null ? [] : ['HTTP_X_REQUEST_ID' => $id]), new OctaneRequestContext);
        self::assertSame([], $this->errors);
    }

    private function entries(): array
    {
        return array_map(static fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    public static function consoleModes(): array
    {
        return ['cli server' => [true], 'http server' => [false]];
    }

    #[DataProvider('consoleModes')]
    public function test_warmed_channels_follow_requests_and_clear_native_context(bool $console): void
    {
        (new ReflectionProperty($this->app, 'isRunningInConsole'))->setValue($this->app, $console);
        $channel = $this->app['log']->channel('second');
        $this->app['router']->get('/test/{step}', static function (Request $request, string $step) use ($channel) {
            if ($step === 'first') {
                Log::shareContext(['tenant' => 'first']);
                $channel->withContext(['local' => 'first']);
            }
            Log::info('request '.$step);
            $channel->info('channel '.$step);

            return response('ok');
        });
        $this->app['events']->listen(RequestTerminated::class, static function (): void {
            Log::info('terminated');
        });
        $worker = $this->worker();
        $this->request($worker, '/test/first', 'first-id');
        $this->request($worker, '/test/second', 'second-id');
        $channel->info('between operations');
        $entries = $this->entries();
        self::assertSame(['first-id', 'first-id', 'first-id', 'second-id', 'second-id', 'second-id'], array_column($entries, 'request_id'));
        self::assertSame('test/{step}', $entries[0]['route']);
        self::assertSame(['tenant' => 'first', 'local' => 'first'], $entries[1]['context']);
        self::assertSame([], $entries[3]['context']);
        self::assertSame([], $entries[4]['context']);
        self::assertArrayNotHasKey('request_id', $entries[6]);
        self::assertSame(['first-id', 'second-id'], array_map(static fn ($response) => $response->headers->get('X-Request-Id'), $this->responses));
        $worker->terminate();
    }

    public function test_nested_sync_jobs_and_failed_http_requests_do_not_contaminate_next_request(): void
    {
        $this->app['router']->get('/jobs', static function () {
            OctaneLoggingJob::dispatchSync('outer');
            Log::info('after jobs');

            return response('ok');
        });
        $this->app['router']->get('/failure', static function () {
            OctaneLoggingJob::dispatchSync('failure');
        });
        $this->app['router']->get('/next', static function () {
            Log::info('next request');

            return response('ok');
        });
        $worker = $this->worker();
        $this->request($worker, '/jobs', 'jobs-request');
        $this->request($worker, '/failure', 'failed-request');
        $this->request($worker, '/next', 'next-request');
        $entries = $this->entries();
        self::assertSame(['outer', 'inner', 'outer resumed', 'after jobs', 'failure', 'job failure', 'next request'], array_column($entries, 'message'));
        self::assertSame($entries[0]['job_id'], $entries[2]['job_id']);
        self::assertNotSame($entries[0]['job_id'], $entries[1]['job_id']);
        self::assertNotSame('', $entries[0]['job_id']);
        self::assertSame('jobs-request', $entries[0]['request_id']);
        self::assertSame('jobs-request', $entries[3]['request_id']);
        self::assertArrayNotHasKey('job_id', $entries[3]);
        self::assertSame($entries[4]['job_id'], $entries[5]['job_id']);
        self::assertSame('next-request', $entries[6]['request_id']);
        self::assertArrayNotHasKey('job_id', $entries[6]);
        self::assertSame(500, $this->responses[1]->getStatusCode());
        self::assertSame('failed-request', $this->responses[1]->headers->get('X-Request-Id'));
        self::assertSame([], $worker->application()->make(JobContext::class)->current());
        $worker->terminate();
    }

    public function test_tasks_ticks_and_worker_errors_never_reuse_an_http_request(): void
    {
        (new ReflectionProperty($this->app, 'isRunningInConsole'))->setValue($this->app, false);
        $this->app['router']->get('/http', static fn () => response('ok'));
        $worker = $this->worker();
        $this->request($worker, '/http', 'http-only');
        $result = $worker->handleTask(static function () {
            OctaneLoggingJob::dispatchSync('failure');
        });
        self::assertInstanceOf(TaskExceptionResult::class, $result);
        $worker->handleTask(static function () {
            Log::info('clean task');
        });
        $this->app['events']->listen(TickReceived::class, static function (): void {
            Log::info('clean tick');
        });
        $worker->handleTick();
        $worker->application()['log']->channel('second')->info('outside sandbox');
        $entries = $this->entries();
        self::assertSame(['failure', 'job failure', 'clean task', 'clean tick', 'outside sandbox'], array_column($entries, 'message'));
        foreach ($entries as $entry) {
            self::assertArrayNotHasKey('request_id', $entry);
        }
        self::assertSame($entries[0]['job_id'], $entries[1]['job_id']);
        foreach (array_slice($entries, 2) as $entry) {
            self::assertArrayNotHasKey('job_id', $entry);
        }
        $worker->terminate();
    }

    public function test_aborted_job_state_and_trace_binding_belong_only_to_the_current_sandbox(): void
    {
        $exception = new RuntimeException('operation aborted');
        $this->app['router']->get('/aborted', static function () use ($exception) {
            app()->instance(TraceContext::class, new class extends TraceContext
            {
                public function current(): array
                {
                    return ['trace_id' => 'trace-of-first-request', 'span_id' => 'first-span'];
                }
            });
            // A custom queue integration aborts without sending its completion event.
            $job = new SyncJob(app(), json_encode(['uuid' => 'unfinished-job']), 'sync', 'sync');
            app('events')->dispatch(new JobProcessing('sync', $job));
            Log::info('before abort');
            throw $exception;
        });
        $this->app['router']->get('/clean', static function () use ($exception) {
            Log::info('next request', ['exception' => $exception]);

            return response('ok');
        });
        $worker = $this->worker();
        $this->request($worker, '/aborted', 'aborted-request');
        $this->request($worker, '/clean', 'clean-request');
        $entries = $this->entries();
        self::assertSame('unfinished-job', $entries[0]['job_id']);
        self::assertSame('trace-of-first-request', $entries[0]['trace_id']);
        self::assertSame('clean-request', $entries[2]['request_id']);
        self::assertArrayNotHasKey('job_id', $entries[2]);
        self::assertArrayNotHasKey('trace_id', $entries[2]);
        self::assertSame([], $worker->application()->make(JobContext::class)->current());
        $worker->terminate();
    }
}

final class OctaneLoggingJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(public string $name) {}

    public function handle(): void
    {
        Log::info($this->name);
        if ($this->name === 'failure') {
            throw new RuntimeException('job failure');
        }
        if ($this->name === 'outer') {
            self::dispatchSync('inner');
            Log::info('outer resumed');
        }
    }
}
