<?php

namespace Bherila\McpLaravelBridge\Mcp;

use Closure;

/** Domain-neutral metadata for one explicitly allow-listed MCP tool. */
final readonly class ToolDefinition
{
    /** @param array{0: object|string, 1: string}|Closure $handler */
    public function __construct(
        public string $name,
        public string $title,
        public string $description,
        public array|Closure $handler,
        public ?string $operationId = null,
        public bool $readOnly = true,
        public bool $destructive = false,
        public bool $idempotent = true,
        public ?string $responseOperationId = null,
    ) {}

    public function operationId(): string
    {
        return $this->operationId ?? $this->name;
    }

    public function responseOperationId(): string
    {
        return $this->responseOperationId ?? $this->operationId();
    }
}
