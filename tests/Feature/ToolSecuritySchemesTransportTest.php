<?php

namespace Bherila\McpLaravelBridge\Tests\Feature;

use Bherila\McpLaravelBridge\Http\StreamableHttpResponder;
use Bherila\McpLaravelBridge\Mcp\ToolWithSecuritySchemes;
use Illuminate\Http\Request;
use Mcp\Capability\Registry;
use Mcp\Schema\Enum\ProtocolVersion;
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

    public function test_secure_default_transport_serves_the_modern_protocol_era(): void
    {
        if (! defined(ProtocolVersion::class.'::V2026_07_28')) {
            self::markTestSkipped('The modern protocol era requires mcp/sdk 0.8 or newer.');
        }

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

        $discovery = $this->sendModern($server, 'server/discover');
        self::assertSame(200, $discovery->getStatusCode());
        /** @var array<string, mixed> $discoveryPayload */
        $discoveryPayload = json_decode((string) $discovery->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['2026-07-28'], $discoveryPayload['result']['supportedVersions']);

        $tools = $this->sendModern($server, 'tools/list');
        self::assertSame(200, $tools->getStatusCode());
        self::assertNull($tools->headers->get('Mcp-Session-Id'));

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

        return (new StreamableHttpResponder)->run($request, $server, null, 65_536);
    }

    private function sendModern(Server $server, string $method): \Symfony\Component\HttpFoundation\Response
    {
        $request = Request::create(
            '/mcp',
            'POST',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json, text/event-stream',
                'HTTP_MCP_PROTOCOL_VERSION' => '2026-07-28',
                'HTTP_MCP_METHOD' => $method,
            ],
            content: json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => $method,
                'params' => [
                    '_meta' => [
                        'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                        'io.modelcontextprotocol/clientCapabilities' => (object) [],
                        'io.modelcontextprotocol/clientInfo' => [
                            'name' => 'bridge-test',
                            'version' => '1.0.0',
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        );

        return (new StreamableHttpResponder)->run($request, $server, null, 65_536);
    }
}
