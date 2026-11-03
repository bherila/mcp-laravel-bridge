<?php

namespace Bherila\McpLaravelBridge\Tests\Unit;

use Bherila\McpLaravelBridge\Mcp\ValidatedCallToolHandler;
use Mcp\Capability\Discovery\SchemaValidator;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Tool;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Stringable;

final class ValidatedCallToolHandlerTest extends TestCase
{
    public function test_invalid_output_is_refused_with_privacy_safe_drift_logging(): void
    {
        $registry = new Registry;
        $registry->registerTool(new Tool(
            name: 'things.get',
            title: 'Get thing',
            inputSchema: ['type' => 'object', 'additionalProperties' => false],
            description: null,
            annotations: null,
            outputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['id'],
                'properties' => ['id' => ['type' => 'integer']],
            ],
        ), static fn (): array => ['id' => 'caller-12345', 'private_note' => 'sensitive prose']);
        $logger = new RecordingLogger;
        $referenceHandler = new ReferenceHandler;
        $handler = new ValidatedCallToolHandler(
            new CallToolHandler($registry, $referenceHandler, new NullLogger),
            $registry,
            new SchemaValidator(logger: new NullLogger),
            ['things.get' => 'ThingEnvelope'],
            $logger,
            'Synthetic contract failure.',
        );
        $request = (new CallToolRequest('things.get', []))->withId(9);

        $response = $handler->handle($request, $this->createStub(SessionInterface::class));

        self::assertInstanceOf(Error::class, $response);
        self::assertSame('Synthetic contract failure.', $response->message);
        self::assertCount(1, $logger->warnings);
        $encoded = json_encode($logger->warnings, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('ThingEnvelope', $encoded);
        self::assertStringNotContainsString('caller-12345', $encoded);
        self::assertStringNotContainsString('private_note', $encoded);
        self::assertStringNotContainsString('sensitive prose', $encoded);
        self::assertStringNotContainsString('pointer', $encoded);
        $answered = json_encode($response, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('things.get', $answered);
        self::assertStringNotContainsString('/id', $answered);
        self::assertStringNotContainsString('private_note', $answered);
    }

    public function test_a_tools_own_error_reaches_the_client_and_is_not_treated_as_contract_drift(): void
    {
        $registry = new Registry;
        $registry->registerTool(new Tool(
            name: 'things.get',
            title: 'Get thing',
            inputSchema: ['type' => 'object', 'additionalProperties' => false],
            description: null,
            annotations: null,
            outputSchema: ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]],
        ), static function (): never {
            throw new ToolCallException('No such thing.');
        });
        $logger = new RecordingLogger;
        $handler = new ValidatedCallToolHandler(
            new CallToolHandler($registry, new ReferenceHandler, new NullLogger),
            $registry,
            new SchemaValidator(logger: new NullLogger),
            ['things.get' => 'ThingEnvelope'],
            $logger,
            'Synthetic contract failure.',
        );

        $response = $handler->handle(
            (new CallToolRequest('things.get', []))->withId(9),
            $this->createStub(SessionInterface::class),
        );

        self::assertInstanceOf(Response::class, $response);
        self::assertTrue($response->result->isError);
        self::assertSame('No such thing.', $response->result->content[0]->text);
        self::assertSame([], $logger->warnings);
    }
}

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{message: string, context: array<string, mixed>}> */
    public array $warnings = [];

    /** @param array<string, mixed> $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if ($level === 'warning') {
            $this->warnings[] = ['message' => (string) $message, 'context' => $context];
        }
    }
}
