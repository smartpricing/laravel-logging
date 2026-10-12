<?php

declare(strict_types=1);

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$mode = $argv[2];

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

final class LoggingSmokeJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Log::warning('smoke job', ['password' => 'SYNTHETIC_SECRET']);
    }
}

if ($mode === 'http') {
    $kernel = $app->make(HttpKernel::class);
    $kernel->bootstrap();
    $app['router']->get('/logging-probe', static function () {
        Log::info('smoke http', ['api_key' => 'SYNTHETIC_SECRET']);

        return response('ok');
    });
    foreach (['first-request', 'second-request'] as $requestId) {
        $request = Request::create('/logging-probe', server: ['HTTP_X_REQUEST_ID' => $requestId]);
        $response = $kernel->handle($request);
        check($response->getStatusCode() === 200, 'HTTP route did not succeed.');
        check($response->headers->get('X-Request-Id') === $requestId, 'Response lost its request ID.');
        $kernel->terminate($request, $response);
    }
} else {
    $app->make(ConsoleKernel::class)->bootstrap();
    if ($mode === 'disabled') {
        check(! config('structured-logging.enabled'), 'Installation enabled logging unexpectedly.');
        check(config('logging.channels.single.tap') === null, 'Disabled package changed a channel.');
    } elseif ($mode === 'cli') {
        check($app->configurationIsCached(), 'Configuration cache is not active.');
        Log::info('smoke cli api-key=SYNTHETIC_SECRET');
        $app->make(Dispatcher::class)->dispatchSync(new LoggingSmokeJob);
        Log::info('smoke after job');
    } elseif ($mode === 'verify') {
        $raw = file_get_contents($app->storagePath('logs/laravel.log'));
        check(! str_contains($raw, 'SYNTHETIC_SECRET'), 'A credential reached the log file.');
        $entries = array_map(static fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($app->storagePath('logs/laravel.log'), FILE_IGNORE_NEW_LINES));
        check(count($entries) === 5, 'Unexpected log count.');
        check(array_unique(array_column($entries, 'service')) === ['smoke-app'], 'Service metadata missing.');
        check($entries[1]['connection'] === 'sync' && isset($entries[1]['job_id']), 'Queue correlation missing.');
        check(! isset($entries[2]['job_id']), 'Queue correlation leaked after completion.');
        check(array_column($entries, 'request_id') === ['first-request', 'second-request'], 'HTTP correlation missing or leaked.');
        echo 'Clean application smoke test passed (cached configuration, CLI, queue, HTTP, redaction).'.PHP_EOL;
    }
}
