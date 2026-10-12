# Changelog

## 0.1.4

- Resolve the active container per record and per queue event instead of capturing the worker's original application.
- Isolate request and job context in each Octane HTTP/task/tick sandbox, including pre-warmed channels and failed operations.
- Keep HTTP correlation during synchronous jobs and exception reporting; include request IDs on Laravel's Octane error responses.
- Use the payload UUID for synchronous jobs without a driver job ID, and clear failed-job associations at queue loop boundaries.
- Test real Octane workers and two persistent FrankenPHP container replicas, including concurrent clients, error recovery and worker reload.
- Document Kubernetes collection, cross-service propagation boundaries, and Octane's stderr/native-output requirements.

## 0.1.3

- Redact hyphenated and underscored key variants consistently in messages, including escaped and unclosed quoted values.
- Extend built-in sensitive fields by default; require `sensitive_fields_mode=replace` to opt out explicitly.
- Preserve per-channel levels and bubbling when fallback handlers are used in stacks.
- Validate enabled package configuration at boot, before Laravel can catch formatter errors and switch to plaintext emergency logging.
- Support Laravel 13 and test the supported PHP 8.2–8.5 combinations and minimum dependencies.
- Add fresh-application checks for discovery, published/cached configuration, HTTP and queue correlation, plus real Alloy/Loki ingestion checks.

## 0.1.2

- Document public installation from Packagist and configuration for any Laravel application.
- Replace application-specific migration notes and examples with generic adoption guidance.
- Add package discovery keywords and public support links; runtime behavior is unchanged.

## 0.1.1

- Use the organization's approved PHP setup action in the compatibility matrix.

## 0.1.0

- Opt-in Laravel 11/12 integration using the native log manager and Monolog streams.
- Versioned JSON lines, UTC timestamps, exception normalization and bounded output.
- Configurable credential redaction with constant replacement values.
- Request, job and optional OpenTelemetry correlation.
- Stream fallback, independent tests and CI compatibility matrix.
