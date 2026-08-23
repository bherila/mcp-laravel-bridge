<?php

namespace Bherila\McpLaravelBridge\Mcp;

use Closure;
use Mcp\Capability\Discovery\DocBlockParser;
use Mcp\Capability\Discovery\HandlerResolver;
use Mcp\Capability\Discovery\SchemaGenerator;
use Psr\Log\NullLogger;

final class ReflectedInputSchemaFactory
{
    /** @param array{0: object|string, 1: string}|Closure $handler @return array<string, mixed> */
    public function for(array|Closure $handler): array
    {
        $generator = new SchemaGenerator(new DocBlockParser(logger: new NullLogger));
        $schema = $generator->generate(HandlerResolver::resolve($handler));
        $schema['additionalProperties'] = false;

        return $schema;
    }
}
