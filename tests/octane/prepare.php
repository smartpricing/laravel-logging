<?php

// Modifies only a disposable application created by tests/smoke/install.sh.
$directory = $argv[1];
$manifest = json_decode(file_get_contents($directory.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
$manifest['require']['laravel/octane'] = '^2.17';
file_put_contents($directory.'/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
file_put_contents($directory.'/bootstrap/providers.php', <<<'PROVIDERS'
<?php
return [App\Providers\AppServiceProvider::class, App\Providers\LoggingSmokeServiceProvider::class];
PROVIDERS);
file_put_contents($directory.'/app/Providers/LoggingSmokeServiceProvider.php', <<<'PROVIDER'
<?php
namespace App\Providers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Smartness\LaravelLogging\Context\JobContext;

class LoggingSmokeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Resolve before WorkerStarting, as real providers often do.
        Log::channel(config('logging.default'));
        $this->app->make(JobContext::class);
        $this->app->instance('smoke.worker_id', bin2hex(random_bytes(16)));
        Route::get('/ready', fn () => response('ready'));
        Route::get('/probe/{step}', function (string $step) {
            if ($step === 'first') {
                Log::shareContext(['tenant' => 'first-only']);
            }
            Log::info('start', ['step' => $step, 'password' => 'SYNTHETIC_SECRET', 'worker_id' => app('smoke.worker_id')]);
            if ($step === 'failure') {
                throw new \RuntimeException('intentional failure password=SYNTHETIC_SECRET');
            }
            if ($step === 'jobs') {
                \App\LoggingSmokeJob::dispatchSync('outer');
            }
            Log::info('end', ['step' => $step, 'worker_id' => app('smoke.worker_id')]);
            return response()->json(['worker_id' => app('smoke.worker_id'), 'hostname' => gethostname()]);
        });
    }
}
PROVIDER);
file_put_contents($directory.'/app/LoggingSmokeJob.php', <<<'JOB'
<?php
namespace App;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

class LoggingSmokeJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public string $name) {}

    public function handle(): void
    {
        Log::info($this->name, ['worker_id' => app('smoke.worker_id')]);
        if ($this->name === 'outer') {
            self::dispatchSync('inner');
            Log::info('outer resumed', ['worker_id' => app('smoke.worker_id')]);
        }
    }
}
JOB);
// FrankenPHP's Artisan launcher only forwards stderr; stdout is not a log transport.
file_put_contents($directory.'/config/logging.php', <<<'CONFIG'
<?php
return [
    'default' => env('LOG_CHANNEL', 'stderr'),
    'channels' => [
        'stderr' => ['driver' => 'monolog', 'handler' => \Monolog\Handler\StreamHandler::class, 'with' => ['stream' => 'php://stderr']],
    ],
];
CONFIG);
