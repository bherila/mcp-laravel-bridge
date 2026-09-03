<?php

namespace Bherila\McpLaravelBridge\Tests\Feature;

use Bherila\McpLaravelBridge\Http\StreamableHttpResponder;
use Bherila\McpLaravelBridge\Mcp\ToolWithSecuritySchemes;
use Illuminate\Http\Request;
use Mcp\Capability\Registry;
use Mcp\Schema\ServerCapabilities;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Orchestra\Testbench\TestCase;

final class ToolSecuritySchemesTransportTest extends TestCase
{
    public function test_tools_list_emits_security_schemes_through_streamable_http(): void
    {
        $schemes = [['type' => 'oauth2', 'scopes' => ['mcp:use', 'things:read']]];
        $registry = new Registry;
        $registry->registerTool(new ToolWithSecuritySchemes(
            securitySchemes: $schemes,
            name: 'things.list',
            title: 'List things',
            inputSchema: ['type' => 'object', 'properties' => []],
            description: 'Lists things.',
            annotations: new ToolAnnotations(readOnlyHint: true),
            outputSchema: ['type' => 'object', 'properties' => []],
        ), static fn (): array => ['data' => []]);

        $server = Server::builder()
            ->setServerInfo('Bridge test', '1.0.0')
            ->setRegistry($registry)
            ->setCapabilities(new ServerCapabilities(tools: true))
            ->build();

        $initialization = $this->send($server, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => [],
                'clientInfo' => ['name' => 'bridge-test', 'version' => '1.0.0'],
            ],
        ]);
        self::assertSame(200, $initialization->getStatusCode());
        $sessionId = $initialization->headers->get('Mcp-Session-Id');
        self::assertIsString($sessionId);

        $tools = $this->send($server, [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => [],
        ], $sessionId);
        self::assertSame(200, $tools->getStatusCode());

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $tools->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($schemes, $payload['result']['tools'][0]['securitySchemes']);
        self::assertSame($schemes, $payload['result']['tools'][0]['_meta']['securitySchemes']);
    }

    /** @param array<string, mixed> $message */
    private function send(Server $server, array $message, ?string $sessionId = null): \Symfony\Component\HttpFoundation\Response
    {
        $serverParameters = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_MCP_PROTOCOL_VERSION' => '2025-11-25',
        ];
        if ($sessionId !== null) {
            $serverParameters['HTTP_MCP_SESSION_ID'] = $sessionId;
        }

        $request = Request::create(
            '/mcp',
            'POST',
            server: $serverParameters,
            content: json_encode($message, JSON_THROW_ON_ERROR),
        );

        return (new StreamableHttpResponder)->run($request, $server, [], 65_536);
    }
}
