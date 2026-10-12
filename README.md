# Smartness Laravel Logging

General-purpose structured JSON logging for Laravel 11, 12 and 13 using the native
Laravel LogManager and Monolog 3. Requires PHP 8.2 or later (8.3 for Laravel 13). Use it in HTTP APIs,
web applications, Artisan commands and queue workers, without application-specific
models, services or infrastructure.

Each event is a single JSON line (NDJSON), suitable for Grafana Loki or other
collectors that ingest JSON logs. The package writes to your existing Laravel
stream channels; it does not send logs to a backend or require Loki credentials.

Installation is opt-in: installing the package alone does not change channels,
replace the `log` service or enable collection of request bodies.

## Install

Install from [Packagist](https://packagist.org/packages/smartness/laravel-logging):

```shell
composer require smartness/laravel-logging:^0.1
```

No custom Composer repository or GitHub credentials are needed.

Use version 0.1.3 or later for the redaction and stack-routing corrections.

Laravel discovers the service provider automatically. Optionally publish config:

```shell
php artisan vendor:publish --tag=structured-logging-config
```

Enable the formatter and identify the service:

```dotenv
STRUCTURED_LOGGING_ENABLED=true
STRUCTURED_LOGGING_SERVICE=my-service
```

Select stream-backed leaf channels in `config/structured-logging.php`:

```php
'channels' => ['single', 'daily', 'stdout', 'stderr', 'payments'],
```

Use `['*']` to select all configured stream-backed channels. The package supports
Laravel's `single` and `daily` drivers, and `monolog` channels backed by
`StreamHandler` subclasses. Existing paths and level thresholds remain in effect.
Stacks inherit the configured leaf handlers; `null`, Slack and other transports
retain their native behavior and are not converted to JSON by this package.
Publishing configuration does not automatically add an absent channel.

Clear/rebuild Laravel's configuration cache and restart persistent workers when
changing configuration. Enable in staging first and check the collector and
existing dashboards before production rollout.

## Configure for your application

All package options live in `config/structured-logging.php`. Application-specific
channel definitions and routing stay in Laravel's `config/logging.php`.

| Option | Default | Purpose |
| --- | --- | --- |
| `enabled` | `false` | Opt in with `STRUCTURED_LOGGING_ENABLED=true` |
| `service` | `APP_NAME`, or `laravel` | Identify the application; override with `STRUCTURED_LOGGING_SERVICE` |
| `environment` | `APP_ENV`, or `production` | Identify the deployment environment |
| `channels` | `single`, `daily`, `stdout`, `stderr` | Select existing stream channels, or use `['*']` |
| `request_context` | `true` | Add HTTP request correlation and response headers |
| `job_context` | `true` | Add correlation through Laravel queue events |
| `trace_context` | `true` | Include an active OpenTelemetry span when available |
| `fallback_path` | `php://stderr` | Fallback stream; set to `null` to propagate primary write failures |

Channels that do not exist are skipped. For container logging, define `stdout`
in `config/logging.php` if your application does not already have it:

```php
'stdout' => [
    'driver' => 'monolog',
    'handler' => \Monolog\Handler\StreamHandler::class,
    'with' => ['stream' => 'php://stdout'],
    'level' => env('LOG_LEVEL', 'debug'),
],
```

Then set `LOG_CHANNEL=stdout`. File-based applications can keep their existing
`single` or `daily` channel. HTTP, queue and trace enrichment can each be disabled
independently; CLI logging does not require an HTTP request or a queue connection.

When enabled, the package validates its configuration at application boot, before
Laravel resolves logging channels. Invalid bounds, option types or redaction modes
stop startup with an explicit error. This prevents a bad formatter configuration
from silently selecting Laravel's plaintext emergency logger. Keep package options
fixed after boot; rebuild `config:cache` and restart workers when changing them.
Errors in unrelated Laravel channel definitions or other packages' taps remain
subject to Laravel's own emergency logging behavior.

## Use Laravel logging

```php
use Illuminate\Support\Facades\Log;

Log::info('Import completed', ['batch_id' => 123]);
Log::channel('payments')->warning('Payment retry scheduled', ['attempt' => 2]);
Log::error('Payment failed', ['exception' => $exception]);
Log::withContext(['tenant_id' => 456])->info('Import started');
```

Use context for application-specific identifiers and timer durations. There is no
replacement facade, timer log level or mutable channel selector. Native Laravel
context APIs retain their semantics: the application remains responsible for the
lifecycle of context it adds itself with `withContext()` or `shareContext()`.

## JSON contract

```json
{"schema_version":1,"timestamp":"2026-10-12T10:30:00.000000Z","level":"info","message":"Import completed","service":"my-service","environment":"production","component":"stdout","process_id":42,"hostname":"app-worker","context":{"batch_id":123},"job_id":"job-456"}
```

- Timestamps are converted to UTC; one event occupies exactly one physical line.
- `component` is the configured leaf channel. A stack writes to each eligible
  destination and preserves each destination's component and threshold.
- HTTP logs receive one validated/generated request ID per request, reused in
  `X-Request-Id` on successful middleware responses. The ID is stored on the request
  object. Method, route template and elapsed time are optional metadata.
- Job logs include job ID, queue, connection and attempt. Context resets on
  completion, failure and worker loop boundaries; nested synchronous jobs restore
  their parent. Exceptions reported after job events retain correlation through
  a weak map keyed by the exception object.
- If `open-telemetry/api` is installed and an active valid span exists, `trace_id`
  and `span_id` are included. The package never creates spans or exporters. Bind
  your own `Context\TraceContext` implementation for another tracing system.
- Request bodies, concrete URLs, query strings, headers, user lookups and CLI
  argument lists are not collected automatically. Existing extra fields from
  other processors are preserved and normalized under `extra`.

`schema_version` identifies the envelope contract. Optional enrichment may add
fields. Consumers should tolerate unknown fields and absent optional metadata.
Very large events contain explicit truncation markers; the final minimal fallback
can omit optional fields to honor `max_line_bytes`.

## Redaction and bounds

Known credential keys are replaced completely with `[REDACTED]`. Matching ignores
case, underscores and hyphens but requires a complete key, so `api_key_id` and
`shipping` remain useful. Configure `sensitive_fields` for your application's
additional credential names. By default, the list **extends** the built-in fields,
including when an older published config overrides the list. For example:

```php
'sensitive_fields' => ['business_secret', 'integration_credentials'],
'sensitive_fields_mode' => 'extend',
```

Set `sensitive_fields_mode` to `replace` only when deliberately replacing the
entire built-in list. In that mode you must include every key you need to protect.
Case, underscores and hyphens are ignored for both structured keys and textual
assignments. Quoted values support escaped quotes and newlines; an unclosed quoted
secret consumes the remainder of the string so a partial value cannot leak.

Arrays, plain objects, nested JSON strings, exception messages and known key/value
and Bearer/Basic patterns in text are covered.

This is not a detector for arbitrary secrets in free text, custom encodings or
unknown field names. Callers must avoid logging credentials. Malformed JSON-like
strings and oversized strings are omitted instead of retaining unsafe fragments.
Domain object serializers/getters are never invoked: arbitrary objects are
represented by their type. Pass an explicit array of safe fields when needed.
Exception traces omit arguments and objects, and include bounded previous chains.
Redaction applies to this package's outputs; Laravel events and unrelated handlers
may still receive the original context.

Defaults: 32 KiB per encoded line (including newline), 16 KiB per context, 8 KiB
per string, depth 8, 1,000 items per normalization and 30 exception trace frames.
Oversized contexts are replaced with a marker. Invalid UTF-8 is substituted;
cycles, resources and non-finite numbers have safe representations. Configure
budgets to match your collector's limits. Truncation bounds traversal and output,
not the memory already allocated by the caller before logging.

## Delivery and worker lifecycle

Monolog writes each record immediately. Failed primary stream writes fall back
to `php://stderr` with the same formatter and level threshold. Set `fallback_path`
to another stream or `null` to retain the primary handler's exception behavior.
If both streams fail, Monolog's fallback group drops the event without recursively
logging the failure. Delivery is best effort; process termination or collector
failures require infrastructure-level monitoring.

The package retains Laravel's native manager and handler close/reset APIs, including
compatibility with workers that forget channels before forking. Tests cover
sequential workers and nested synchronous jobs. Octane/Swoole coroutine concurrency
is not currently certified. Queue integrations must emit Laravel job lifecycle
events to receive job metadata.

## Adopt in an existing Laravel application

If another library replaces Laravel's `log` binding, restore the native
`Illuminate\Log\LogManager` before enabling this package. Non-native managers
are rejected with an explicit configuration error. Keep any application-specific
migration switches and rollback procedure in the consuming application.

Select the stream channels to convert and retain their existing levels and
destinations. Validate timestamp parsing, component names, context fields, alerts
and byte volume with your collector before rollout. The output schema is defined
above; this package does not translate an application's previous log schema.

To disable the formatter, set `STRUCTURED_LOGGING_ENABLED=false`, rebuild the
configuration cache and restart persistent workers. Laravel resumes the normal
formatting configured for each channel. Restoring a previous custom logger, if
present, is a separate application configuration change.

## Development

```shell
composer install
composer test
composer validate --strict
```

Tests use Orchestra Testbench with real Laravel/Monolog channel routing and
temporary streams. No database, Redis or Loki service is required. CI runs a
PHP 8.2–8.5 / Laravel 11–13 compatibility matrix, respecting each framework's PHP
requirements, plus minimum-dependency coverage. Laravel 11 compatibility does not
imply upstream security support; only its isolated compatibility jobs permit installation
of dependencies with advisories. Consumer security policies are unchanged.

The application smoke tests install the package into a fresh Laravel application,
publish and cache its configuration, and check CLI, synchronous queue jobs and
successive HTTP requests. Pass a new disposable directory:

```shell
bash tests/smoke/install.sh /tmp/logging-app 13
bash tests/smoke/loki.sh /tmp/logging-app
```

The second command requires Docker Compose and starts isolated Alloy and Loki
containers, then removes them. It verifies real file collection, JSON queries,
original timestamps, redaction and low-cardinality labels. The Alloy configuration
in `tests/smoke/alloy.config` is an example for raw JSON log files. Container stdout
collection additionally needs the appropriate Docker/CRI decoding stage. Adapt
endpoints, authentication and discovery to your infrastructure before staging.
This smoke test does not certify Octane or a production collector deployment.

References: [Laravel logging](https://laravel.com/docs/11.x/logging),
[Laravel packages](https://laravel.com/docs/11.x/packages), and
[Loki labels](https://grafana.com/docs/loki/latest/get-started/labels/).
Keep request IDs, trace IDs and user IDs in the log body or structured metadata;
use stable, low-cardinality stream labels in the collector.
