<?php

namespace Bherila\McpLaravelBridge\Tests\Unit;

use Bherila\McpLaravelBridge\Mcp\ToolDefinition;
use PHPUnit\Framework\TestCase;

final class ToolDefinitionTest extends TestCase
{
    public function test_operation_names_default_without_weakening_explicit_mappings(): void
    {
        $handler = static fn (): array => [];

        $default = new ToolDefinition('things.list', 'List things', 'List.', $handler);
        self::assertSame('things.list', $default->operationId());
        self::assertSame('things.list', $default->responseOperationId());

        $mapped = new ToolDefinition(
            'things.alias',
            'List things',
            'List.',
            $handler,
            operationId: 'things.request',
            responseOperationId: 'things.response',
        );
        self::assertSame('things.request', $mapped->operationId());
        self::assertSame('things.response', $mapped->responseOperationId());
    }
}
