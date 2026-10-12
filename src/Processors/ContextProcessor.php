<?php

declare(strict_types=1);

namespace Smartness\LaravelLogging\Processors;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Smartness\LaravelLogging\Context\JobContext;
use Smartness\LaravelLogging\Context\RequestContext;
use Smartness\LaravelLogging\Context\TraceContext;
use Throwable;

final class ContextProcessor implements ProcessorInterface
{
    public function __construct(private readonly Application $app) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $options = $this->app['config']['structured-logging'];
        $exception = $record->context['exception'] ?? null;
        $metadata = $options['job_context'] ? $this->app->make(JobContext::class)->current($exception instanceof Throwable ? $exception : null) : [];
        if ($metadata === [] && $options['request_context'] && ! $this->app->runningInConsole() && $this->app->bound('request')) {
            $request = $this->app['request'];
            if ($request instanceof Request) {
                $metadata = RequestContext::metadata($request);
            }
        }
        if ($options['trace_context']) {
            $metadata += $this->app->make(TraceContext::class)->current();
        }

        return $record->with(extra: array_merge($record->extra, ['_structured_logging' => $metadata]));
    }
}
