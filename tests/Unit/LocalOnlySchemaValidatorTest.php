<?php

namespace Bherila\McpLaravelBridge\Tests\Unit;

use Bherila\McpLaravelBridge\Mcp\LocalOnlySchemaValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

final class LocalOnlySchemaValidatorTest extends TestCase
{
    public function test_local_definitions_are_supported(): void
    {
        $validator = new LocalOnlySchemaValidator;

        self::assertSame([], $validator->validateAgainstJsonSchema(
            ['value' => 'safe'],
            [
                'type' => 'object',
                'properties' => ['value' => ['$ref' => '#/$defs/value']],
                'required' => ['value'],
                '$defs' => ['value' => ['type' => 'string']],
            ],
        ));
    }

    public function test_remote_and_file_references_are_rejected_without_logging_the_reference_or_payload(): void
    {
        $logger = new SchemaRecordingLogger;
        $validator = new LocalOnlySchemaValidator($logger);
        $secret = 'secret-patient-value';
        $reference = 'file:///private/schema.json';

        $errors = $validator->validateAgainstJsonSchema(
            ['value' => $secret],
            ['type' => 'object', 'properties' => ['value' => ['$ref' => $reference]]],
        );

        self::assertSame('$ref', $errors[0]['keyword']);
        $encodedLogs = json_encode($logger->records, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($secret, $encodedLogs);
        self::assertStringNotContainsString($reference, $encodedLogs);
        self::assertStringContainsString('file', $encodedLogs);
    }
}

final class SchemaRecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string|Stringable, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = compact('level', 'message', 'context');
    }
}
