<?php

namespace Bherila\McpLaravelBridge\Tests\Feature;

use Bherila\McpLaravelBridge\Http\McpHttpEndpoint;
use Bherila\McpLaravelBridge\Http\McpHttpPolicy;
use Bherila\McpLaravelBridge\Http\PrevalidatedRequestMiddleware;
use Bherila\McpLaravelBridge\Http\SdkMiddlewareProfile;
use Bherila\McpLaravelBridge\Http\StreamableHttpResponder;
use Bherila\McpLaravelBridge\Testing\McpHttpConformanceAssertions;
use Bherila\McpLaravelBridge\Testing\SyntheticMcpServerFactory;
use Illuminate\Http\Request;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Server;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class McpHttpEndpointTest extends TestCase
{
    use McpHttpConformanceAssertions;

    #[DataProvider('handshakeProtocolProvider')]
    public function test_hardened_endpoint_initializes_and_lists_tools_through_the_official_sdk(string $protocol): void
    {
        $endpoint = new McpHttpEndpoint(new StreamableHttpResponder);
        $policy = new McpHttpPolicy(['https://client.example'], ['mcp.example']);
        $server = SyntheticMcpServerFactory::make();

        $initialization = $endpoint->run(
            $this->request([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => $protocol,
                    'capabilities' => [],
                    'clientInfo' => ['name' => 'consumer-test', 'version' => '1.0.0'],
                ],
            ], protocol: $protocol),
            $server,
            $policy,
        );

        self::assertSame(200, $initialization->getStatusCode());
        self::assertPrivateMcpResponse($initialization);
        self::assertAllowedMcpOrigin($initialization, 'https://client.example');
        $session = $initialization->headers->get('Mcp-Session-Id');
        self::assertIsString($session);

        $tools = $endpoint->run(
            $this->request(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []], $session, $protocol),
            $server,
            $policy,
        );
        self::assertSame(200, $tools->getStatusCode());
        $payload = json_decode((string) $tools->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('bridge.ping', $payload['result']['tools'][0]['name']);

        $called = $endpoint->run(
            $this->request([
                'jsonrpc' => '2.0',
                'id' => 3,
                'method' => 'tools/call',
                'params' => ['name' => 'bridge.ping', 'arguments' => (object) []],
            ], $session, $protocol),
            $server,
            $policy,
        );
        self::assertSame(200, $called->getStatusCode());
        $callPayload = json_decode((string) $called->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($callPayload['result']['structuredContent']['ok']);

        $unsupported = $endpoint->run(
            $this->request(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'resources/list', 'params' => []], $session, $protocol),
            $server,
            $policy,
        );
        $unsupportedPayload = json_decode((string) $unsupported->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue(
            array_key_exists('error', $unsupportedPayload)
            || isset($unsupportedPayload['result']['resources']),
            'Unsupported capabilities must produce a bounded protocol result or error.',
        );
    }

    /** @return iterable<string, array{string}> */
    public static function handshakeProtocolProvider(): iterable
    {
        yield '2025-06-18 consumer' => ['2025-06-18'];
        yield '2025-11-25 consumer' => ['2025-11-25'];
    }

    public function test_sdk_profile_does_not_put_handshake_protocol_middleware_at_the_modern_edge(): void
    {
        $profile = SdkMiddlewareProfile::forHardenedLaravelEdge();

        if (defined(ProtocolVersion::class.'::V2026_07_28')) {
            self::assertCount(1, $profile);
            self::assertInstanceOf(PrevalidatedRequestMiddleware::class, $profile[0]);

            return;
        }

        self::assertCount(1, $profile);
        self::assertInstanceOf(ProtocolVersionMiddleware::class, $profile[0]);
    }

    public function test_unsupported_handshake_protocol_is_rejected(): void
    {
        $response = (new McpHttpEndpoint(new StreamableHttpResponder))->run(
            $this->request([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '1900-01-01',
                    'capabilities' => [],
                    'clientInfo' => ['name' => 'consumer-test', 'version' => '1.0.0'],
                ],
            ], protocol: '1900-01-01'),
            SyntheticMcpServerFactory::make(),
            new McpHttpPolicy(['https://client.example'], ['mcp.example']),
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertPrivateMcpResponse($response);
    }

    public function test_preflight_does_not_construct_the_server(): void
    {
        $called = false;
        $request = Request::create('/mcp', 'OPTIONS', server: [
            'HTTP_HOST' => 'mcp.example',
            'HTTP_ORIGIN' => 'https://client.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Authorization, Mcp-Protocol-Version',
        ]);

        $response = (new McpHttpEndpoint(new StreamableHttpResponder))->run(
            $request,
            static function () use (&$called): Server {
                $called = true;

                return SyntheticMcpServerFactory::make();
            },
            new McpHttpPolicy(['https://client.example'], ['mcp.example']),
        );

        self::assertSame(204, $response->getStatusCode());
        self::assertFalse($called);
    }

    /** @param array<string, mixed> $message */
    private function request(array $message, ?string $session = null, string $protocol = '2025-11-25'): Request
    {
        $server = [
            'HTTP_HOST' => 'mcp.example',
            'HTTP_ORIGIN' => 'https://client.example',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_MCP_PROTOCOL_VERSION' => $protocol,
        ];
        if ($session !== null) {
            $server['HTTP_MCP_SESSION_ID'] = $session;
        }

        return Request::create('/mcp', 'POST', server: $server, content: json_encode($message, JSON_THROW_ON_ERROR));
    }
}
