<?php

namespace Bherila\McpLaravelBridge\Testing;

use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;

/** Assertions shared by application endpoint contract tests. */
trait McpHttpConformanceAssertions
{
    public static function assertPrivateMcpResponse(Response $response): void
    {
        Assert::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        Assert::assertSame('no-cache', $response->headers->get('Pragma'));
        Assert::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public static function assertAllowedMcpOrigin(Response $response, string $origin): void
    {
        Assert::assertSame($origin, $response->headers->get('Access-Control-Allow-Origin'));
        Assert::assertStringContainsString('Origin', (string) $response->headers->get('Vary'));
        Assert::assertStringContainsString('WWW-Authenticate', (string) $response->headers->get('Access-Control-Expose-Headers'));
    }
}
