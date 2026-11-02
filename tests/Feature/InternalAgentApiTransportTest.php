<?php

namespace Bherila\McpLaravelBridge\Tests\Feature;

use Bherila\McpLaravelBridge\Http\AgentApiFile;
use Bherila\McpLaravelBridge\Http\AgentApiMultipart;
use Bherila\McpLaravelBridge\Http\InternalAgentApiTransport;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\URL;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_it_carries_the_outer_origin_into_dispatch(): void
    {
        $this->originProbeRoute();
        $outer = $this->bindOuter(Request::create('https://app.example.test:8443/api/v1/mcp', 'POST'));

        $response = $this->transport($outer)->send('GET', 'origin');

        self::assertSame('https://app.example.test:8443/x', $response->json['url']);
        self::assertTrue($response->json['secure']);
        self::assertSame('app.example.test', $response->json['host']);
    }

    public function test_a_signed_url_generated_during_dispatch_validates_at_the_outer_origin(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $router = $this->app->make(Router::class);
        $router->put('/uploads/{id}', static fn () => 'ok')->name('bridge-test.upload');
        $router->getRoutes()->refreshNameLookups();
        $router->get('/api/v1/sign', static fn () => response()->json([
            'url' => URL::temporarySignedRoute('bridge-test.upload', now()->addMinutes(5), ['id' => 1]),
        ]));
        $outer = $this->bindOuter(Request::create('https://app.example.test/api/v1/mcp', 'POST'));

        $url = $this->transport($outer)->send('GET', 'sign')->json['url'];

        self::assertStringStartsWith('https://app.example.test/uploads/1?', $url);
        self::assertTrue(URL::hasValidSignature(Request::create($url, 'PUT')));
    }

    public function test_it_ignores_a_forwarded_host_the_security_policy_never_validated(): void
    {
        $this->originProbeRoute();
        Request::setTrustedProxies(['192.0.2.1'], Request::HEADER_X_FORWARDED_HOST);
        try {
            $outer = $this->bindOuter(Request::create('https://app.example.test/api/v1/mcp', 'POST', server: [
                'REMOTE_ADDR' => '192.0.2.1',
                'HTTP_X_FORWARDED_HOST' => 'other.example.test',
            ]));
            self::assertSame('other.example.test', $outer->getHost());

            $response = $this->transport($outer)->send('GET', 'origin');
        } finally {
            Request::setTrustedProxies([], -1);
        }

        self::assertSame('https://app.example.test/x', $response->json['url']);
    }

    public function test_it_honors_a_forwarded_proto_from_a_trusted_proxy(): void
    {
        $this->originProbeRoute();
        Request::setTrustedProxies(['192.0.2.1'], Request::HEADER_X_FORWARDED_PROTO);
        try {
            $outer = $this->bindOuter(Request::create('http://app.example.test/api/v1/mcp', 'POST', server: [
                'REMOTE_ADDR' => '192.0.2.1',
                'HTTP_X_FORWARDED_PROTO' => 'https',
            ]));

            $response = $this->transport($outer)->send('GET', 'origin');
        } finally {
            Request::setTrustedProxies([], -1);
        }

        self::assertSame('https://app.example.test/x', $response->json['url']);
        self::assertTrue($response->json['secure']);
    }

    public function test_the_url_generator_returns_to_the_outer_origin_after_success_and_after_an_exception(): void
    {
        $this->originProbeRoute();
        $this->app->make(Router::class)->get('/api/v1/origin-fail', static function (): never {
            throw new RuntimeException('Failed at '.url('/x'));
        });
        $this->app->make(ExceptionHandler::class)->renderable(
            static fn (RuntimeException $exception) => response()->json(['message' => $exception->getMessage()], 500),
        );
        $outer = $this->bindOuter(Request::create('https://app.example.test/api/v1/mcp', 'POST'));

        $success = $this->transport($outer)->send('GET', 'origin');
        self::assertSame('https://app.example.test/x', $success->json['url']);
        self::assertSame('https://app.example.test/x', url('/x'));

        $failure = $this->transport($outer)->send('GET', 'origin-fail');
        self::assertSame(500, $failure->status);
        self::assertSame('Failed at https://app.example.test/x', $failure->json['message']);
        self::assertSame('https://app.example.test/x', url('/x'));
    }

    public function test_a_nested_dispatch_keeps_the_outer_origin(): void
    {
        $this->originProbeRoute();
        $this->app->make(Router::class)->get('/api/v1/nested', fn (Request $request) => response()->json([
            'inner' => $this->transport($request)->send('GET', 'origin')->json,
            'after' => url('/x'),
        ]));
        $outer = $this->bindOuter(Request::create('https://app.example.test:8443/api/v1/mcp', 'POST'));

        $response = $this->transport($outer)->send('GET', 'nested');

        self::assertSame('https://app.example.test:8443/x', $response->json['inner']['url']);
        self::assertTrue($response->json['inner']['secure']);
        self::assertSame('https://app.example.test:8443/x', $response->json['after']);
    }

    #[DataProvider('malformedHosts')]
    public function test_a_malformed_host_cannot_change_where_the_request_is_dispatched(string $host): void
    {
        $this->originProbeRoute();
        $router = $this->app->make(Router::class);
        $router->get('/admin', static fn () => response()->json(['reached' => 'admin']));
        $outer = Request::create('/api/v1/mcp', 'POST');
        $outer->headers->set('Host', $host);
        $this->bindOuter($outer);

        $response = $this->transport($outer)->send('GET', 'origin');

        self::assertSame(200, $response->status);
        self::assertSame('http://localhost/x', $response->json['url'] ?? null);
    }

    /** @return array<string, array{string}> */
    public static function malformedHosts(): array
    {
        return [
            'path and query' => ['evil.example.test/admin?'],
            'path and fragment' => ['evil.example.test/admin#'],
            'userinfo' => ['user@evil.example.test'],
            'whitespace' => ['evil example.test'],
            'surrounding whitespace' => [' app.example.test '],
            'bad port' => ['app.example.test:99999'],
        ];
    }

    public function test_it_keeps_a_bare_path_request_when_the_outer_request_has_no_host(): void
    {
        $this->originProbeRoute();
        $outer = Request::create('https://app.example.test/api/v1/mcp', 'POST');
        $outer->headers->remove('Host');
        $this->app->instance('request', $outer);

        $response = $this->transport($outer)->send('GET', 'origin');

        self::assertSame(200, $response->status);
        self::assertSame('http://localhost/x', $response->json['url']);
    }

    public function test_an_api_prefix_that_already_has_an_origin_is_left_alone(): void
    {
        $this->originProbeRoute();
        $outer = $this->bindOuter(Request::create('https://app.example.test/api/v1/mcp', 'POST'));
        $transport = new InternalAgentApiTransport(
            $this->app->make(Router::class),
            $this->app->make(ExceptionHandler::class),
            $outer,
            $this->app,
            apiPrefix: 'https://prefix.example.test:8443/api/v1',
        );

        $response = $transport->send('GET', 'origin');

        self::assertSame(200, $response->status);
        self::assertSame('https://prefix.example.test:8443/x', $response->json['url']);
        self::assertSame('prefix.example.test', $response->json['host']);
    }

    private function originProbeRoute(): void
    {
        $this->app->make(Router::class)->get('/api/v1/origin', static fn (Request $request) => response()->json([
            'url' => url('/x'),
            'secure' => request()->secure(),
            'host' => $request->getHost(),
        ]));
    }

    private function bindOuter(Request $outer): Request
    {
        $this->app->instance('request', $outer);

        return $outer;
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
