<?php

declare(strict_types=1);

namespace Smartness\LaravelLogging;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Log\Logger;
use Illuminate\Log\LogManager;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Monolog\Handler\StreamHandler;
use Smartness\LaravelLogging\Context\JobContext;
use Smartness\LaravelLogging\Context\TraceContext;
use Smartness\LaravelLogging\Http\CorrelateRequest;
use Smartness\LaravelLogging\Support\Configuration;

final class StructuredLoggingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/structured-logging.php', 'structured-logging');
        $this->app->singleton(JobContext::class);
        $this->app->singleton(TraceContext::class);
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/structured-logging.php' => config_path('structured-logging.php')], 'structured-logging-config');

        if (! $this->app['config']['structured-logging.enabled']) {
            return;
        }
        // Validate outside LogManager::get(), which catches tap failures and writes plaintext.
        Configuration::validate($this->app['config']['structured-logging']);
        $manager = $this->app->make('log');
        if (! $manager instanceof LogManager) {
            throw new LogicException('Structured logging requires the native Laravel LogManager. Disable any custom log service override before enabling it.');
        }
        $names = $this->app['config']['structured-logging.channels'];
        if (in_array('*', $names, true)) {
            $names = array_keys($this->app['config']->get('logging.channels', []));
        }
        foreach ($names as $name) {
            $channel = $this->app['config']->get('logging.channels.'.$name);
            $driver = $channel['driver'] ?? null;
            if (! in_array($driver, ['single', 'daily', 'monolog'], true)
                || ($driver === 'monolog' && ! is_a($channel['handler'] ?? '', StreamHandler::class, true))) {
                continue;
            }
            $tap = ConfigureJsonLogging::class.':'.$name;
            $channel['tap'] = array_values(array_unique([...($channel['tap'] ?? []), $tap]));
            $this->app['config']->set('logging.channels.'.$name, $channel);
            $resolved = $manager->getChannels()[$name] ?? null;
            if ($resolved instanceof Logger) {
                // Providers may have resolved a channel before this provider booted.
                $this->app->make(ConfigureJsonLogging::class)($resolved, $name);
            }
        }
        foreach (array_keys($manager->getChannels()) as $name) {
            if ($this->app['config']->get('logging.channels.'.$name.'.driver') === 'stack') {
                $manager->forgetChannel($name);
            }
        }
        if ($this->app['config']['structured-logging.request_context']) {
            $this->callAfterResolving(Kernel::class, static function ($kernel): void {
                $kernel->prependMiddleware(CorrelateRequest::class);
            });
        }

        if (! $this->app['config']['structured-logging.job_context']) {
            return;
        }
        $events = $this->app['events'];
        $events->listen(JobProcessing::class, function ($event): void {
            $this->app->make(JobContext::class)->begin($event->job, $event->connectionName);
        });
        foreach ([JobProcessed::class, JobExceptionOccurred::class, JobFailed::class] as $eventClass) {
            $events->listen($eventClass, function ($event): void {
                $this->app->make(JobContext::class)->end($event->job, $event->exception ?? null);
            });
        }
        $events->listen(Looping::class, function (): void {
            $this->app->make(JobContext::class)->reset();
        });
    }
}
