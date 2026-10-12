<?php

declare(strict_types=1);

namespace Smartness\LaravelLogging\Context;

use OpenTelemetry\API\Trace\Span;
use Throwable;

class TraceContext
{
    public function current(): array
    {
        if (! class_exists(Span::class)) {
            return [];
        }
        try {
            $context = Span::getCurrent()->getContext();

            return $context->isValid() ? [
                'trace_id' => $context->getTraceId(), 'span_id' => $context->getSpanId(),
            ] : [];
        } catch (Throwable) {
            return [];
        }
    }
}
