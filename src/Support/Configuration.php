<?php

declare(strict_types=1);

namespace Smartness\LaravelLogging\Support;

use InvalidArgumentException;

final class Configuration
{
    public const SENSITIVE_FIELDS = [
        'password', 'password_confirmation', 'current_password', 'new_password',
        'old_password', 'api_key', 'secret', 'client_secret', 'token',
        'access_token', 'refresh_token', 'authorization', 'auth_token',
        'channel_token', 'private_key', 'credentials', 'processorAcquirerCredentials',
        'credit_card', 'card_number', 'cvv', 'ssn', 'social_security_number',
        'pin', 'otp', 'verification_code', 'cookie', 'set-cookie',
    ];

    public static function validate(array $options): void
    {
        foreach (['max_line_bytes', 'max_context_bytes', 'max_string_bytes', 'max_depth', 'max_items', 'max_trace_frames'] as $key) {
            if (! isset($options[$key]) || ! is_int($options[$key]) || $options[$key] < 1) {
                throw new InvalidArgumentException("structured-logging.{$key} must be a positive integer.");
            }
        }
        if ($options['max_line_bytes'] < 1024) {
            throw new InvalidArgumentException('structured-logging.max_line_bytes must be at least 1024.');
        }
        if ($options['max_depth'] > 32) {
            throw new InvalidArgumentException('structured-logging.max_depth must not exceed 32.');
        }
        foreach (['enabled', 'request_context', 'job_context', 'trace_context'] as $key) {
            if (! isset($options[$key]) || ! is_bool($options[$key])) {
                throw new InvalidArgumentException("structured-logging.{$key} must be a boolean.");
            }
        }
        foreach (['service', 'environment'] as $key) {
            if (! isset($options[$key]) || ! is_string($options[$key]) || trim($options[$key]) === '') {
                throw new InvalidArgumentException("structured-logging.{$key} must be a non-empty string.");
            }
        }
        if (($options['fallback_path'] ?? null) !== null
            && (! is_string($options['fallback_path']) || trim($options['fallback_path']) === '' || str_contains($options['fallback_path'], "\0"))) {
            throw new InvalidArgumentException('structured-logging.fallback_path must be a non-empty path or null.');
        }
        foreach (['channels', 'sensitive_fields'] as $key) {
            if (! isset($options[$key]) || ! is_array($options[$key]) || ! array_is_list($options[$key])) {
                throw new InvalidArgumentException("structured-logging.{$key} must be a list of non-empty strings.");
            }
            foreach ($options[$key] as $value) {
                if (! is_string($value) || trim($value) === '' || strlen($value) > 256) {
                    throw new InvalidArgumentException("structured-logging.{$key} entries must be non-empty strings of at most 256 bytes.");
                }
            }
        }
        if (! in_array($options['sensitive_fields_mode'] ?? 'extend', ['extend', 'replace'], true)) {
            throw new InvalidArgumentException('structured-logging.sensitive_fields_mode must be extend or replace.');
        }
    }

    public static function sensitiveFields(array $options): array
    {
        return array_values(array_unique([
            ...(($options['sensitive_fields_mode'] ?? 'extend') === 'extend' ? self::SENSITIVE_FIELDS : []),
            ...$options['sensitive_fields'],
        ]));
    }
}
