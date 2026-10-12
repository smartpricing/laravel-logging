<?php

use Smartness\LaravelLogging\Support\Configuration;

return [
    // Installation alone never changes an application's logging configuration.
    'enabled' => env('STRUCTURED_LOGGING_ENABLED', false),
    'service' => env('STRUCTURED_LOGGING_SERVICE', env('APP_NAME', 'laravel')),
    'environment' => env('APP_ENV', 'production'),

    // Only stream-backed leaf channels. Stacks inherit their handlers.
    'channels' => ['single', 'daily', 'stdout', 'stderr'],
    'fallback_path' => 'php://stderr',
    'request_context' => true,
    'job_context' => true,
    'trace_context' => true,

    // Byte budgets include JSON escaping; oversized context is marked explicitly.
    'max_line_bytes' => 32768,
    'max_context_bytes' => 16384,
    'max_string_bytes' => 8192,
    'max_depth' => 8,
    'max_items' => 1000,
    'max_trace_frames' => 30,

    // Custom fields extend the built-in protection, including in previously published configs.
    // Use 'replace' only to explicitly opt out of the built-in list.
    'sensitive_fields_mode' => 'extend',
    'sensitive_fields' => Configuration::SENSITIVE_FIELDS,
];
