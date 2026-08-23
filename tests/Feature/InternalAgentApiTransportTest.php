<?php

namespace Bherila\McpLaravelBridge\Tests\Feature;

use Bherila\McpLaravelBridge\Http\AgentApiFile;
use Bherila\McpLaravelBridge\Http\AgentApiMultipart;
use Bherila\McpLaravelBridge\Http\InternalAgentApiTransport;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase;
use RuntimeException;

final class InternalAgentApiTransportTest extends TestCase
{
    public function test_it_forwards_only_bearer_and_allowlisted_headers_then_restores_the_outer_request(): void
    {
        $router = $this->app->make(Router::class);
        $router->post('/api/v1/probe', fn (Request $request) => response()->json([
            'authorization' => $request->header('Authorization'),
            'idempotency' => $request->header('Idempotency-Key'),
            'denied' => $request->header('X-Denied'),
            'cookie' => $request->header('Cookie'),
            'remote' => $request->ip(),
            'payload' => $request->json()->all(),
            'bound' => request() === $request,
        ]));
        $outer = Request::create('/api/v1/mcp', 'POST', server: [
            'HTTP_AUTHORIZATION' => 'Bearer synthetic-token',
            'HTTP_COOKIE' => 'session=must-not-cross',
            'REMOTE_ADDR' => '192.0.2.10',
        ]);
        $this->app->instance('request', $outer);

        $response = $this->transport($outer, ['Idempotency-Key'])->send(
            'POST',
            'probe',
            json: ['marker' => 'synthetic'],
            headers: ['Idempotency-Key' => 'retry-1', 'X-Denied' => 'secret'],
        );

        self::assertSame(200, $response->status);
        self::assertSame('Bearer synthetic-token', $response->json['authorization']);
        self::assertSame('retry-1', $response->json['idempotency']);
        self::assertNull($response->json['denied']);
        self::assertNull($response->json['cookie']);
        self::assertSame('192.0.2.10', $response->json['remote']);
        self::assertSame(['marker' => 'synthetic'], $response->json['payload']);
        self::assertTrue($response->json['bound']);
        self::assertSame($outer, $this->app->make('request'));
    }

    public function test_it_restores_the_outer_request_after_an_exception(): void
    {
        $this->app->make(Router::class)->get('/api/v1/fail', static fn () => throw new RuntimeException('synthetic'));
        $outer = Request::create('/api/v1/mcp', 'POST');
        $this->app->instance('request', $outer);

        $response = $this->transport($outer)->send('GET', 'fail');

        self::assertSame(500, $response->status);
        self::assertSame($outer, $this->app->make('request'));
    }

    public function test_it_cleans_multipart_temporary_files_after_dispatch(): void
    {
        $this->app->make(Router::class)->post('/api/v1/upload', fn (Request $request) => response()->json([
            'path' => $request->file('document')?->getRealPath(),
            'contents' => $request->file('document')?->getContent(),
        ]));
        $outer = Request::create('/api/v1/mcp', 'POST');
        $this->app->instance('request', $outer);

        $response = $this->transport($outer)->send('POST', 'upload', multipart: new AgentApiMultipart(
            fields: ['kind' => 'synthetic'],
            files: ['document' => new AgentApiFile('example.txt', 'synthetic contents')],
        ));

        self::assertSame('synthetic contents', $response->json['contents']);
        self::assertIsString($response->json['path']);
        self::assertFileDoesNotExist($response->json['path']);
        self::assertSame($outer, $this->app->make('request'));
    }

    public function test_malformed_json_responses_are_returned_as_null(): void
    {
        $this->app->make(Router::class)->get('/api/v1/malformed', static fn () => response('{broken', 200, ['Content-Type' => 'application/json']));
        $outer = Request::create('/api/v1/mcp', 'POST');
        $this->app->instance('request', $outer);

        self::assertNull($this->transport($outer)->send('GET', 'malformed')->json);
    }

    /** @param list<string> $allowedHeaders */
    private function transport(Request $outer, array $allowedHeaders = []): InternalAgentApiTransport
    {
        return new InternalAgentApiTransport(
            $this->app->make(Router::class),
            $this->app->make(ExceptionHandler::class),
            $outer,
            $this->app,
            allowedHeaders: $allowedHeaders,
            temporaryFilePrefix: 'bridge-test-',
        );
    }
}
