<?php

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

    // Matching ignores case, underscores and hyphens, but matches complete keys.
    'sensitive_fields' => [
        'password', 'password_confirmation', 'current_password', 'new_password',
        'old_password', 'api_key', 'secret', 'client_secret', 'token',
        'access_token', 'refresh_token', 'authorization', 'auth_token',
        'channel_token', 'private_key', 'credentials', 'processorAcquirerCredentials',
        'credit_card', 'card_number', 'cvv', 'ssn', 'social_security_number',
        'pin', 'otp', 'verification_code', 'cookie', 'set-cookie',
    ],
];
