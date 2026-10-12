<?php

declare(strict_types=1);

namespace Smartness\LaravelLogging\Listeners;

use Laravel\Octane\Events\RequestReceived;
use Smartness\LaravelLogging\Context\JobContext;
use Smartness\LaravelLogging\Context\OctaneContext;

final class PrepareOctaneContext
{
    public function handle(object $event): void
    {
        $app = $event->sandbox ?? $event->app;
        $app->instance(OctaneContext::class, new OctaneContext(
            $event instanceof RequestReceived ? $event->request : null,
        ));
        // A shallow container clone can inherit even a pre-resolved scoped service.
        // Replace it instead of mutating state shared with the base worker.
        $app->instance(JobContext::class, new JobContext);
    }
}
