<?php

declare(strict_types=1);

namespace Smartness\LaravelLogging\Processors;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Smartness\LaravelLogging\Context\JobContext;
use Smartness\LaravelLogging\Context\OctaneContext;
use Smartness\LaravelLogging\Context\RequestContext;
use Smartness\LaravelLogging\Context\TraceContext;
use Throwable;

final class ContextProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        // Handlers survive Octane operations. Resolve the active sandbox per record.
        /** @var Application $app */
        $app = Container::getInstance();
        $options = $app['config']['structured-logging'];
        $exception = $record->context['exception'] ?? null;
        $metadata = $options['job_context'] ? $app->make(JobContext::class)->current($exception instanceof Throwable ? $exception : null) : [];
        if ($options['request_context']) {
            // Octane runs under CLI and leaves the last request bound on its base app.
            // Only its HTTP sandbox may use that request, never a task, tick or boot log.
            $request = $app->bound(OctaneContext::class)
                ? $app->make(OctaneContext::class)->request
                : (! $app->runningInConsole() && $app->bound('request') ? $app['request'] : null);
            if ($request instanceof Request) {
                $metadata += RequestContext::metadata($request);
            }
        }
        if ($options['trace_context']) {
            $metadata += $app->make(TraceContext::class)->current();
        }

        return $record->with(extra: array_merge($record->extra, ['_structured_logging' => $metadata]));
    }
}
