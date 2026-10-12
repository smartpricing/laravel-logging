<?php

declare(strict_types=1);

namespace Smartness\LaravelLogging\Http;

use Closure;
use Illuminate\Http\Request;
use Smartness\LaravelLogging\Context\RequestContext;

final class CorrelateRequest
{
    public function handle(Request $request, Closure $next): mixed
    {
        $id = RequestContext::id($request);
        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
