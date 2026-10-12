<?php

declare(strict_types=1);

namespace Smartness\LaravelLogging\Context;

use Illuminate\Contracts\Queue\Job;
use Throwable;
use WeakMap;

/** Job objects are retained until completion, including nested synchronous jobs. */
final class JobContext
{
    private array $jobs = [];

    private WeakMap $exceptions;

    public function __construct()
    {
        $this->exceptions = new WeakMap;
    }

    public function begin(Job $job, string $connection): void
    {
        $this->jobs[spl_object_id($job)] = [
            'job' => $job,
            'metadata' => [
                'job_id' => $job->getJobId(), 'queue' => $job->getQueue(),
                'attempt' => $job->attempts(), 'connection' => $connection,
            ],
        ];
    }

    public function end(Job $job, ?Throwable $exception = null): void
    {
        if ($exception !== null && isset($this->jobs[spl_object_id($job)])) {
            // Laravel reports the exception after its job completion events.
            $this->exceptions[$exception] = $this->jobs[spl_object_id($job)]['metadata'];
        }
        unset($this->jobs[spl_object_id($job)]);
    }

    public function current(?Throwable $exception = null): array
    {
        for ($depth = 0; $exception !== null && $depth < 20; ++$depth, $exception = $exception->getPrevious()) {
            if (isset($this->exceptions[$exception])) {
                return $this->exceptions[$exception];
            }
        }

        return $this->jobs === [] ? [] : end($this->jobs)['metadata'];
    }

    public function reset(): void
    {
        $this->jobs = [];
    }
}
