<?php

namespace Bherila\McpLaravelBridge\Capabilities;

use Closure;

final readonly class McpBinding
{
    /**
     * @param  array{0: object|string, 1: string}|Closure|null  $handler
     * @param  list<string>  $legacyAliases  former wire names still accepted for compatibility
     */
    public function __construct(
        public ?string $name = null,
        public array|Closure|null $handler = null,
        public McpKind $kind = McpKind::Tool,
        public ?string $uri = null,
        public array $legacyAliases = [],
    ) {}
}
