<?php

declare(strict_types=1);

namespace Smartness\LaravelLogging\Formatters;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;
use Smartness\LaravelLogging\Support\SafeNormalizer;

final class StructuredJsonFormatter implements FormatterInterface
{
    private SafeNormalizer $normalizer;

    public function __construct(private readonly array $options, private readonly ?string $component = null)
    {
        foreach (['max_line_bytes', 'max_context_bytes', 'max_string_bytes', 'max_depth', 'max_items', 'max_trace_frames'] as $key) {
            if (! isset($options[$key]) || ! is_int($options[$key]) || $options[$key] < 1) {
                throw new InvalidArgumentException("{$key} must be a positive integer.");
            }
        }
        if ($options['max_line_bytes'] < 1024) {
            throw new InvalidArgumentException('max_line_bytes must be at least 1024.');
        }
        if ($options['max_depth'] > 32) {
            throw new InvalidArgumentException('max_depth must not exceed 32.');
        }
        $this->normalizer = new SafeNormalizer($options);
    }

    public function format(LogRecord $record): string
    {
        $extra = $record->extra;
        $metadata = $extra['_structured_logging'] ?? [];
        unset($extra['_structured_logging']);
        $context = $this->normalizer->normalize($record->context);
        if (strlen($this->encode($context)) > $this->options['max_context_bytes']) {
            $context = ['_truncated' => 'context exceeded byte budget'];
        }
        $data = [
            'schema_version' => 1,
            'timestamp' => DateTimeImmutable::createFromInterface($record->datetime)
                ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            'level' => strtolower($record->level->getName()),
            'message' => $this->normalizer->normalize($record->message),
            'service' => substr((string) $this->options['service'], 0, 128),
            'environment' => substr((string) $this->options['environment'], 0, 128),
            'component' => substr($this->component ?? $record->channel, 0, 128),
            'process_id' => getmypid(),
            'hostname' => substr(gethostname() ?: 'unknown', 0, 128),
            'context' => $context ?: (object) [],
        ];
        foreach (['request_id', 'trace_id', 'span_id', 'duration_ms', 'job_id', 'queue', 'attempt', 'connection', 'method', 'route'] as $key) {
            if (isset($metadata[$key])) {
                $data[$key] = $this->normalizer->normalize($metadata[$key]);
            }
        }
        if ($extra !== []) {
            $data['extra'] = $this->normalizer->normalize($extra);
        }

        // Bound the fully escaped JSON, including its newline. Never slice JSON bytes.
        $encoded = $this->encode($data);
        if (strlen($encoded) + 1 > $this->options['max_line_bytes']) {
            $data['context'] = ['_truncated' => 'record exceeded byte budget'];
            unset($data['extra'], $data['route']);
            $encoded = $this->encode($data);
        }
        if (strlen($encoded) + 1 > $this->options['max_line_bytes']) {
            $data['message'] = '[TRUNCATED:record]';
            // Correlation values may come from applications or external queue drivers.
            foreach (['request_id', 'trace_id', 'span_id', 'job_id', 'queue', 'connection', 'method'] as $key) {
                if (isset($data[$key])) {
                    $data[$key] = substr((string) $data[$key], 0, 64);
                }
            }
            $encoded = $this->encode($data);
        }
        if (strlen($encoded) + 1 > $this->options['max_line_bytes']) {
            $encoded = $this->encode([
                'schema_version' => 1, 'timestamp' => $data['timestamp'], 'level' => $data['level'],
                'message' => '[TRUNCATED:record]', 'context' => ['_truncated' => true],
            ]);
        }

        return $encoded."\n";
    }

    public function formatBatch(array $records): string
    {
        return implode('', array_map($this->format(...), $records));
    }

    private function encode(mixed $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
