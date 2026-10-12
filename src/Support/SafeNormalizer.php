<?php

declare(strict_types=1);

namespace Smartness\LaravelLogging\Support;

use DateTimeInterface;
use stdClass;
use Throwable;

/** Bounded traversal without invoking model serializers, getters or __toString. */
final class SafeNormalizer
{
    private array $sensitive;

    private string $keyPattern;

    private int $remaining;

    public function __construct(private readonly array $options)
    {
        $fields = Configuration::sensitiveFields($options);
        $this->sensitive = array_fill_keys(array_map(self::canonicalKey(...), $fields), true);
        $patterns = [];
        foreach ($fields as $field) {
            $canonical = self::canonicalKey($field);
            if ($canonical !== '') {
                $patterns[] = '[-_]*'.implode('[-_]*', array_map(static fn ($char) => preg_quote($char, '~'), str_split($canonical))).'[-_]*';
            }
        }
        $this->keyPattern = implode('|', array_unique($patterns));
    }

    public function normalize(mixed $value): mixed
    {
        $this->remaining = $this->options['max_items'];

        return $this->walk($value, 0);
    }

    private static function canonicalKey(string $key): string
    {
        return strtolower(str_replace(['_', '-'], '', $key));
    }

    private function walk(mixed $value, int $depth): mixed
    {
        if ($depth > $this->options['max_depth'] || --$this->remaining < 0) {
            return '[TRUNCATED]';
        }
        if (is_string($value)) {
            return $this->string($value, $depth);
        }
        if (is_float($value) && ! is_finite($value)) {
            return '[NON_FINITE_FLOAT]';
        }
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        if ($value instanceof Throwable) {
            $trace = [];
            foreach (array_slice($value->getTrace(), 0, $this->options['max_trace_frames']) as $frame) {
                // Arguments and objects can contain credentials and large object graphs.
                $trace[] = array_intersect_key($frame, array_flip(['file', 'line', 'class', 'type', 'function']));
            }

            return $this->walk([
                'class' => $value::class,
                'message' => $value->getMessage(),
                'code' => $value->getCode(),
                'file' => $value->getFile(),
                'line' => $value->getLine(),
                'trace' => $trace,
                'previous' => $value->getPrevious(),
            ], $depth + 1);
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                if ($this->remaining <= 0) {
                    $result['_truncated'] = true;
                    break;
                }
                if (strlen((string) $key) > 256) {
                    $result['_truncated_key'] = true;
                    $this->remaining--;

                    continue;
                }
                if (isset($this->sensitive[self::canonicalKey((string) $key)])) {
                    $result[$key] = '[REDACTED]';
                    $this->remaining--;
                } else {
                    $result[$key] = $this->walk($item, $depth + 1);
                }
            }

            return $result;
        }

        return is_object($value) ? ['_type' => $value::class] : '[RESOURCE]';
    }

    private function string(string $value, int $depth): string
    {
        // Omit, rather than partially expose, a value that exceeds the work budget.
        if (strlen($value) > $this->options['max_string_bytes']) {
            return '[TRUNCATED:string]';
        }
        $trimmed = ltrim($value);
        if ($trimmed !== '' && ($trimmed[0] === '{' || preg_match('/\A\[\s*(?:[\[\{"\d\-\]]|true\b|false\b|null\b)/', $trimmed))) {
            $decoded = json_decode($value, true, $this->options['max_depth'] + 2);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return json_encode($this->walk($decoded, $depth + 1), JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            // Malformed serialized data cannot be reliably redacted by key.
            return '[OMITTED:unparseable-json]';
        }

        $value = preg_replace('~\b(Bearer|Basic)\s+[^\s,;]+~i', '$1 [REDACTED]', $value) ?? '[REDACTED]';
        if ($this->keyPattern !== '') {
            // Consume escaped delimiters and, for unclosed quotes, the rest of the value.
            $quotedOrPlain = <<<'REGEX'
"(?:\\(?:.|\z)|[^"\\])*(?:"|\z)|'(?:\\(?:.|\z)|[^'\\])*(?:'|\z)|[^\s&,;}\]]+
REGEX;
            $pattern = '~(?<![a-zA-Z0-9_.-])(["\']?(?:'.$this->keyPattern.')["\']?\s*[:=]\s*)(?:'.$quotedOrPlain.')~is';
            $value = preg_replace($pattern, '$1[REDACTED]', $value) ?? '[REDACTED]';
        }

        return $value;
    }
}
