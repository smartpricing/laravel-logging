<?php

declare(strict_types=1);

namespace Smartness\LaravelLogging\Context;

use Illuminate\Http\Request;

final class RequestContext
{
    private const ATTRIBUTE = '_smartness_logging_request_id';

    public static function id(Request $request): string
    {
        $id = $request->attributes->get(self::ATTRIBUTE);
        if (! is_string($id)) {
            $candidate = $request->header('X-Request-Id');
            $id = is_string($candidate) && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}\z/', $candidate)
                ? $candidate : bin2hex(random_bytes(16));
            $request->attributes->set(self::ATTRIBUTE, $id);
        }

        return $id;
    }

    public static function metadata(Request $request): array
    {
        $start = $request->server('REQUEST_TIME_FLOAT');
        $route = $request->route();

        return array_filter([
            'request_id' => self::id($request),
            'method' => $request->method(),
            // No query strings, concrete paths, request bodies, headers or user lookup.
            'route' => is_object($route) && method_exists($route, 'uri') ? $route->uri() : null,
            'duration_ms' => is_numeric($start) ? max(0, round((microtime(true) - (float) $start) * 1000, 2)) : null,
        ], static fn ($value) => $value !== null);
    }
}
