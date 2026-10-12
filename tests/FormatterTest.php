<?php

declare(strict_types=1);

namespace Smartness\LaravelLogging\Tests;

use DateTimeImmutable;
use JsonSerializable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Smartness\LaravelLogging\Formatters\StructuredJsonFormatter;

final class FormatterTest extends TestCase
{
    private function formatter(array $options = []): StructuredJsonFormatter
    {
        return new StructuredJsonFormatter(array_replace(require __DIR__.'/../config/structured-logging.php', [
            'service' => 'test', 'environment' => 'testing',
        ], $options), 'payments');
    }

    private function record(string $message = 'example', array $context = [], array $extra = []): LogRecord
    {
        return new LogRecord(new DateTimeImmutable('2026-10-12T12:30:00+02:00'), 'test', Level::Info, $message, $context, $extra);
    }

    public function test_schema_utc_and_single_line_output(): void
    {
        $line = $this->formatter()->format($this->record("first\nsecond"));
        $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, substr_count($line, "\n"));
        self::assertSame('2026-10-12T10:30:00.000000Z', $data['timestamp']);
        self::assertSame('info', $data['level']);
        self::assertSame('payments', $data['component']);
        self::assertSame(1, $data['schema_version']);
        self::assertStringContainsString('"context":{}', $line);
    }

    public function test_bad_utf8_and_non_finite_numbers_preserve_the_event(): void
    {
        $line = $this->formatter()->format($this->record("bad \xB1", ['number' => INF]));
        $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('bad ', $data['message']);
        self::assertSame('[NON_FINITE_FLOAT]', $data['context']['number']);
    }

    public function test_exceptions_include_chain_and_stack_without_arguments(): void
    {
        $exception = new RuntimeException('password=TOP-SECRET', 7, new RuntimeException('previous failure'));
        $line = $this->formatter()->format($this->record('failure', ['exception' => $exception]));
        $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR)['context']['exception'];
        self::assertSame(RuntimeException::class, $data['class']);
        self::assertSame(7, $data['code']);
        self::assertSame('previous failure', $data['previous']['message']);
        self::assertNotEmpty($data['trace']);
        self::assertStringNotContainsString('TOP-SECRET', $line);
        self::assertStringNotContainsString('"args"', $line);
    }

    public function test_redaction_handles_objects_nested_json_and_message_tokens(): void
    {
        $line = $this->formatter()->format($this->record('Authorization: Bearer TOKEN-SECRET; ?token=URL-SECRET', [
            'object' => (object) ['Password' => 'OBJECT-SECRET'],
            'json' => '{"data":{"access_token":"JSON-SECRET"}}',
            'api_key_id' => 123, 'shipping' => 'express',
            'processorAcquirerCredentials' => ['secret' => 'CREDENTIAL-SECRET'],
        ], ['nested' => ['clientSecret' => 'EXTRA-SECRET']]));
        foreach (['TOKEN-SECRET', 'URL-SECRET', 'OBJECT-SECRET', 'JSON-SECRET', 'CREDENTIAL-SECRET', 'EXTRA-SECRET'] as $secret) {
            self::assertStringNotContainsString($secret, $line);
        }
        $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(123, $data['context']['api_key_id']);
        self::assertSame('express', $data['context']['shipping']);
        self::assertSame('[REDACTED]', $data['context']['object']['Password']);
    }

    public function test_message_prefixes_are_preserved_and_malformed_json_is_omitted(): void
    {
        $data = json_decode($this->formatter()->format($this->record('[MyChannel] Failed', [
            'body' => '{"password":"UNFINISHED-SECRET',
        ])), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('[MyChannel] Failed', $data['message']);
        self::assertSame('[OMITTED:unparseable-json]', $data['context']['body']);
    }

    public function test_application_serializers_are_not_executed(): void
    {
        $object = new class implements JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                throw new RuntimeException('must not run');
            }
        };
        $data = json_decode($this->formatter()->format($this->record(context: ['model' => $object])), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('_type', $data['context']['model']);
    }

    public function test_cycles_and_large_collections_have_bounded_traversal(): void
    {
        $cycle = [];
        $cycle['self'] = &$cycle;
        $line = $this->formatter(['max_depth' => 4, 'max_items' => 12])->format($this->record(context: [
            'cycle' => $cycle, 'many' => range(1, 10000),
        ]));
        self::assertStringContainsString('TRUNCATED', $line);
        self::assertLessThan(2000, strlen($line));
        json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_byte_budget_applies_after_json_escaping_and_includes_newline(): void
    {
        $formatter = $this->formatter(['max_line_bytes' => 1024, 'max_context_bytes' => 400]);
        $line = $formatter->format($this->record(str_repeat("\0", 3000), [
            'huge' => str_repeat('€', 2000), 'password' => 'SECRET',
        ], ['huge' => str_repeat('x', 7000)]));
        self::assertLessThanOrEqual(1024, strlen($line));
        self::assertStringNotContainsString('SECRET', $line);
        $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('_truncated', $data['context']);
    }

    public function test_oversized_strings_are_omitted_and_batches_are_json_lines(): void
    {
        $formatter = $this->formatter(['max_string_bytes' => 50]);
        $batch = $formatter->formatBatch([$this->record(str_repeat('a', 100)), $this->record('second')]);
        $lines = explode("\n", trim($batch));
        self::assertCount(2, $lines);
        self::assertSame('[TRUNCATED:string]', json_decode($lines[0], true)['message']);
        self::assertSame('second', json_decode($lines[1], true)['message']);
    }

    public function test_invalid_budgets_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->formatter(['max_line_bytes' => 100]);
    }
}
