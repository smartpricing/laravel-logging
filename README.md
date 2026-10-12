# Smartness Laravel Logging

Structured JSON logs for Laravel 11 and 12 using the native Laravel LogManager
and Monolog 3. Each event is a single JSON line suitable for collection into
Grafana Loki. Requires PHP 8.2 or later. The package does not call Loki directly.

Installation is opt-in: installing the package alone does not change channels,
replace the `log` service or enable collection of request bodies.

## Install

Add the private repository and package to your application's `composer.json`:

```json
{
  "repositories": [
    {"type": "vcs", "url": "https://github.com/smartpricing/laravel-logging.git"}
  ],
  "require": {"smartness/laravel-logging": "^0.1.0"}
}
```

Run `composer update smartness/laravel-logging`. Private repository access is
required locally and in CI; supply Composer credentials through your existing
secret management. Do not commit credentials to this repository.

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

## Use Laravel logging

```php
use Illuminate\Support\Facades\Log;

Log::info('Reservation updated', ['reservation_id' => 123]);
Log::channel('payments')->warning('Payment retry scheduled', ['attempt' => 2]);
Log::error('Payment failed', ['exception' => $exception]);
Log::withContext(['company_id' => 456])->info('Import started');
```

Use context for application-specific identifiers and timer durations. There is no
replacement facade, timer log level or mutable channel selector. Native Laravel
context APIs retain their semantics: the application remains responsible for the
lifecycle of context it adds itself with `withContext()` or `shareContext()`.

## JSON contract

```json
{"schema_version":1,"timestamp":"2026-10-12T10:30:00.000000Z","level":"info","message":"Reservation updated","service":"pms","environment":"production","component":"stdout","process_id":42,"hostname":"pms-pod","context":{"reservation_id":123},"request_id":"abc123"}
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
additional credential names. Arrays, plain objects, nested JSON strings, exception
messages and known key/value and Bearer/Basic patterns in text are covered.

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

## Migration from the PMS logger

The existing `Logging\Support\LogManager` overrides Laravel's `log` binding. Disable
that override before enabling this package; an enabled package rejects non-native
managers instead of silently competing with them. The old library can remain
installed for rollback.

The PMS adapter selects `legacy`, `structured` or `laravel` through
`LOGGING_IMPLEMENTATION`. Its default preserves the existing
`JSON_LOGGING_ENABLED` behavior. Switching to `structured` enables this package
and disables the legacy override through application configuration. No code in
the legacy implementation needs to change.

Keep existing `context.profile.traceID` links while adding top-level `trace_id`.
Validate labels, timestamp parsing, component names, alerts and byte volume before
switching. Request payloads and partially visible credentials intentionally do
not carry over into the new output. Restore the previous implementation setting,
rebuild config cache and restart workers for rollback.

## Development

```shell
composer install
composer test
composer validate --strict
```

Tests use Orchestra Testbench with real Laravel/Monolog channel routing and
temporary streams. No database, Redis or Loki service is required. CI runs a
PHP 8.2–8.4 / Laravel 11–12 compatibility matrix. Laravel 11 compatibility does not
imply upstream security support; only its isolated test jobs permit installation
of dependencies with advisories. Consumer security policies are unchanged.

References: [Laravel logging](https://laravel.com/docs/11.x/logging),
[Laravel packages](https://laravel.com/docs/11.x/packages), and
[Loki labels](https://grafana.com/docs/loki/latest/get-started/labels/).
Keep request IDs, trace IDs and user IDs in the log body or structured metadata;
use stable, low-cardinality stream labels in the collector.
