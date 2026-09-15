<?php

namespace Bherila\McpLaravelBridge\Testing;

use Mcp\Capability\Registry;
use Mcp\Schema\ServerCapabilities;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;

/** Small domain-free fixture for consumer transport conformance tests. */
final class SyntheticMcpServerFactory
{
    /** @param callable(): array{ok: bool} $applicationService */
    public static function make(?callable $applicationService = null): Server
    {
        $registry = new Registry;
        $registry->registerTool(new Tool(
            name: 'bridge.ping',
            title: 'Bridge ping',
            inputSchema: [
                'type' => 'object',
                'properties' => [],
                'additionalProperties' => false,
            ],
            description: 'Calls a synthetic transport-independent application service.',
            annotations: new ToolAnnotations(readOnlyHint: true),
            outputSchema: [
                'type' => 'object',
                'properties' => ['ok' => ['type' => 'boolean']],
                'required' => ['ok'],
                'additionalProperties' => false,
            ],
        ), $applicationService ?? static fn (): array => ['ok' => true]);

        return Server::builder()
            ->setServerInfo('MCP Laravel Bridge conformance fixture', '1.0.0')
            ->setRegistry($registry)
            ->setCapabilities(new ServerCapabilities(tools: true))
            ->build();
    }
}
