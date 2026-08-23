<?php

namespace Bherila\McpLaravelBridge\Tests\Unit;

use Bherila\McpLaravelBridge\Mcp\CredentialSessionNamespace;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

final class CredentialSessionNamespaceTest extends TestCase
{
    public function test_bearer_credentials_get_stable_distinct_session_namespaces(): void
    {
        $first = Request::create('/api/v1/mcp', 'POST', server: ['HTTP_AUTHORIZATION' => 'Bearer first-token']);
        $same = Request::create('/api/v1/mcp', 'POST', server: ['HTTP_AUTHORIZATION' => 'Bearer first-token']);
        $second = Request::create('/api/v1/mcp', 'POST', server: ['HTTP_AUTHORIZATION' => 'Bearer second-token']);

        self::assertSame(
            CredentialSessionNamespace::prefix($first, 'app_'),
            CredentialSessionNamespace::prefix($same, 'app_'),
        );
        self::assertNotSame(
            CredentialSessionNamespace::prefix($first, 'app_'),
            CredentialSessionNamespace::prefix($second, 'app_'),
        );
        self::assertStringStartsWith('app_', CredentialSessionNamespace::prefix($first, 'app_'));
        self::assertStringNotContainsString('first-token', CredentialSessionNamespace::prefix($first, 'app_'));
    }
}
