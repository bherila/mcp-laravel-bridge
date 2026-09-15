<?php

namespace Bherila\McpLaravelBridge\Tests\Feature;

use Bherila\McpLaravelBridge\Http\McpHttpPolicy;
use Bherila\McpLaravelBridge\Http\McpHttpSecurityMiddleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class McpHttpSecurityMiddlewareTest extends TestCase
{
    public function test_origin_and_service_host_are_enforced_independently(): void
    {
        $middleware = $this->middleware();

        $wrongScheme = $this->request(origin: 'http://client.example', host: 'mcp.example');
        self::assertSame(403, $middleware->handle($wrongScheme, $this->ok(...))->getStatusCode());

        $wrongPort = $this->request(origin: 'https://client.example:8443', host: 'mcp.example');
        self::assertSame(403, $middleware->handle($wrongPort, $this->ok(...))->getStatusCode());

        $badHost = $this->request(origin: 'https://client.example', host: 'client.example');
        self::assertSame(403, $middleware->handle($badHost, $this->ok(...))->getStatusCode());

        $allowed = $this->request(origin: 'https://client.example', host: 'mcp.example');
        self::assertSame(200, $middleware->handle($allowed, $this->ok(...))->getStatusCode());
    }

    public function test_native_request_without_origin_is_allowed_but_still_checks_host(): void
    {
        $middleware = $this->middleware();

        self::assertSame(200, $middleware->handle($this->request(host: 'mcp.example'), $this->ok(...))->getStatusCode());
        self::assertSame(403, $middleware->handle($this->request(host: 'other.example'), $this->ok(...))->getStatusCode());
    }

    public function test_valid_preflight_is_answered_at_the_edge(): void
    {
        $request = $this->request('OPTIONS', 'https://client.example', 'mcp.example');
        $request->headers->set('Access-Control-Request-Method', 'POST');
        $request->headers->set('Access-Control-Request-Headers', 'Authorization, Mcp-Protocol-Version, Mcp-Method');
        $called = false;

        $response = $this->middleware()->handle($request, function () use (&$called): Response {
            $called = true;

            return new Response;
        });

        self::assertFalse($called);
        self::assertSame(204, $response->getStatusCode());
        self::assertSame('https://client.example', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertStringContainsString('Authorization', (string) $response->headers->get('Access-Control-Allow-Headers'));
        self::assertStringContainsString('Mcp-Method', (string) $response->headers->get('Access-Control-Allow-Headers'));
        self::assertStringContainsString('Origin', (string) $response->headers->get('Vary'));
        $this->assertPrivateResponse($response);
    }

    public function test_invalid_preflight_fails_without_cors_permission(): void
    {
        $request = $this->request('OPTIONS', 'https://evil.example', 'mcp.example');
        $request->headers->set('Access-Control-Request-Method', 'POST');

        $response = $this->middleware()->handle($request, $this->ok(...));

        self::assertSame(204, $response->getStatusCode());
        self::assertFalse($response->headers->has('Access-Control-Allow-Origin'));
        $this->assertPrivateResponse($response);
    }

    public function test_oauth_challenge_is_private_and_exposed_to_an_allowed_browser(): void
    {
        $response = $this->middleware()->handle(
            $this->request(origin: 'https://client.example', host: 'mcp.example'),
            static fn (): Response => new JsonResponse(
                ['message' => 'Unauthenticated.'],
                401,
                ['WWW-Authenticate' => 'Bearer resource_metadata="https://mcp.example/.well-known/oauth-protected-resource"'],
            ),
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('https://client.example', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertStringContainsString('WWW-Authenticate', (string) $response->headers->get('Access-Control-Expose-Headers'));
        self::assertNotNull($response->headers->get('WWW-Authenticate'));
        $this->assertPrivateResponse($response);
    }

    public function test_laravel_authentication_exception_is_rendered_inside_the_security_pipeline(): void
    {
        $middleware = new McpHttpSecurityMiddleware(
            new McpHttpPolicy(['https://client.example'], ['mcp.example']),
            $this->app->make(ExceptionHandler::class),
        );

        $response = $middleware->handle(
            $this->request(origin: 'https://client.example', host: 'mcp.example'),
            static function (): never {
                throw new AuthenticationException('Unauthenticated.');
            },
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('https://client.example', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertStringContainsString('WWW-Authenticate', (string) $response->headers->get('Access-Control-Expose-Headers'));
        $this->assertPrivateResponse($response);
    }

    #[DataProvider('queryCredentialProvider')]
    public function test_query_string_credentials_are_rejected(string $name): void
    {
        $request = $this->request(host: 'mcp.example');
        $request->query->set($name, 'secret-value-that-must-not-be-reflected');

        $response = $this->middleware()->handle($request, $this->ok(...));

        self::assertSame(400, $response->getStatusCode());
        self::assertStringNotContainsString('secret-value', (string) $response->getContent());
    }

    /** @return iterable<string, array{string}> */
    public static function queryCredentialProvider(): iterable
    {
        yield 'OAuth access token' => ['access_token'];
        yield 'generic token' => ['token'];
        yield 'API key' => ['api_key'];
        yield 'case insensitive' => ['Authorization'];
    }

    public function test_request_and_response_limits_fail_closed(): void
    {
        $request = $this->request(host: 'mcp.example');
        $request->headers->set('Content-Length', '65');
        self::assertSame(413, $this->middleware(64)->handle($request, $this->ok(...))->getStatusCode());

        $response = $this->middleware(responseLimit: 8)->handle(
            $this->request(host: 'mcp.example'),
            static fn (): Response => new Response('ninebytes'),
        );
        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('ninebytes', (string) $response->getContent());
        $this->assertPrivateResponse($response);
    }

    public function test_closure_configuration_is_resolved_per_request(): void
    {
        $host = 'first.example';
        $policy = new McpHttpPolicy([], static function () use (&$host): array {
            return [$host];
        });
        $middleware = new McpHttpSecurityMiddleware($policy);

        self::assertSame(200, $middleware->handle($this->request(host: 'first.example'), $this->ok(...))->getStatusCode());
        $host = 'second.example';
        self::assertSame(200, $middleware->handle($this->request(host: 'second.example'), $this->ok(...))->getStatusCode());
    }

    public function test_browser_default_ports_are_canonical_but_non_default_ports_are_exact(): void
    {
        self::assertSame('https://client.example', McpHttpPolicy::normalizeOrigin('https://CLIENT.example:443'));
        self::assertSame('http://client.example', McpHttpPolicy::normalizeOrigin('http://CLIENT.example:80'));
        self::assertSame('https://client.example:8443', McpHttpPolicy::normalizeOrigin('https://CLIENT.example:8443'));
    }

    public function test_host_can_be_derived_from_an_application_url_without_trusting_forwarded_headers(): void
    {
        self::assertSame('mcp.example', McpHttpPolicy::hostFromUrl('https://mcp.example:443/api/v1/mcp'));
        self::assertSame('mcp.example:8443', McpHttpPolicy::hostFromUrl('https://mcp.example:8443/api/v1/mcp'));
        self::assertSame('[2001:db8::1]:8443', McpHttpPolicy::hostFromUrl('https://[2001:db8::1]:8443/mcp'));
    }

    public function test_wildcard_or_non_origin_configuration_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new McpHttpPolicy(['*'], ['mcp.example']);
    }

    private function middleware(int $requestLimit = 64, int $responseLimit = 128): McpHttpSecurityMiddleware
    {
        return new McpHttpSecurityMiddleware(new McpHttpPolicy(
            allowedOrigins: ['https://client.example'],
            allowedHosts: ['mcp.example'],
            maxRequestBodyBytes: $requestLimit,
            maxResponseBodyBytes: $responseLimit,
        ));
    }

    private function request(string $method = 'POST', ?string $origin = null, string $host = 'mcp.example'): Request
    {
        $server = ['HTTP_HOST' => $host, 'CONTENT_TYPE' => 'application/json'];
        if ($origin !== null) {
            $server['HTTP_ORIGIN'] = $origin;
        }

        return Request::create('/mcp', $method, server: $server, content: '{}');
    }

    private function ok(): Response
    {
        return new JsonResponse(['ok' => true]);
    }

    private function assertPrivateResponse(Response $response): void
    {
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('no-cache', $response->headers->get('Pragma'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }
}
