<?php

namespace Bherila\McpLaravelBridge\Capabilities;

use Bherila\McpLaravelBridge\OpenApi\SchemaCatalog;

/**
 * A schema taken from a shipped OpenAPI document instead of declared inline,
 * so a spec-first application keeps its document as the source of truth.
 */
final readonly class SchemaRef
{
    private function __construct(
        public ?string $component = null,
        public ?string $requestOf = null,
        public ?string $responseOf = null,
    ) {}

    public static function openApi(string $component): self
    {
        return new self(component: $component);
    }

    /** The JSON request body of an operation in the document. */
    public static function requestOf(string $operationId): self
    {
        return new self(requestOf: $operationId);
    }

    /** The success response of an operation in the document. */
    public static function responseOf(string $operationId): self
    {
        return new self(responseOf: $operationId);
    }

    /** @return array<string, mixed> */
    public function resolve(SchemaCatalog $catalog): array
    {
        return match (true) {
            $this->component !== null => $catalog->schema($this->component),
            $this->requestOf !== null => $catalog->requestForOperation($this->requestOf),
            default => $catalog->forOperation((string) $this->responseOf),
        };
    }
}
