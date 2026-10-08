<?php

namespace Bherila\McpLaravelBridge\Tests\Unit;

use Bherila\McpLaravelBridge\OpenApi\SchemaCatalog;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SchemaCatalogTest extends TestCase
{
    private SchemaCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new SchemaCatalog(__DIR__.'/../Fixtures/openapi.json');
    }

    public function test_it_packages_transitive_and_cyclic_refs_as_a_standalone_schema(): void
    {
        $schema = $this->catalog->forOperation('things.list');
        $encoded = json_encode($schema, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('#/components/schemas/', $encoded);
        self::assertSame('#/$defs/Thing', $schema['properties']['data']['items']['$ref']);
        self::assertSame('#/$defs/Thing', $schema['$defs']['Thing']['properties']['parent']['oneOf'][0]['$ref']);
        self::assertArrayHasKey('Thing', $schema['$defs']);
    }

    public function test_it_resolves_requests_scopes_and_response_components(): void
    {
        self::assertSame('ThingEnvelope', $this->catalog->operationComponent('things.create'));
        self::assertSame(['things:write'], $this->catalog->scopesForOperation('things.create'));
        self::assertSame('string', $this->catalog->requestForOperation('things.create')['properties']['name']['type']);
    }

    public function test_it_has_no_permissive_fallback_for_unknown_operations(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->catalog->forOperation('missing.operation');
    }

    public function test_it_rejects_external_references_in_openapi_components(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported response schema reference');

        (new SchemaCatalog(__DIR__.'/../Fixtures/openapi-external.json'))->forOperation('things.list');
    }

    public function test_operations_report_bindings_and_security_as_written(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'openapi');
        file_put_contents($path, json_encode(['openapi' => '3.1.0', 'paths' => [
            '/things' => [
                'get' => ['operationId' => 'things.list', 'summary' => 'List things', 'security' => [['oauth2' => ['things:read']], ['apiToken' => []]]],
                'parameters' => [],
                'x-codegen' => ['operationId' => 'metadata'],
            ],
            '/health' => ['get' => ['operationId' => 'health.get', 'security' => []]],
            '/token' => ['delete' => ['operationId' => 'token.revoke', 'security' => [['oauth2' => []]]]],
            '/legacy' => ['get' => ['operationId' => 'legacy.get']],
        ]]));

        try {
            $operations = (new SchemaCatalog($path))->operations();
        } finally {
            unlink($path);
        }

        $this->assertSame(['things.list', 'health.get', 'token.revoke', 'legacy.get'], array_keys($operations));
        $this->assertSame(['method' => 'GET', 'path' => '/things', 'security' => [['oauth2' => ['things:read']], ['apiToken' => []]], 'summary' => 'List things', 'description' => ''], $operations['things.list']);
        $this->assertSame([], $operations['health.get']['security'], 'Explicitly public');
        $this->assertSame([['oauth2' => []]], $operations['token.revoke']['security'], 'Any credential');
        $this->assertNull($operations['legacy.get']['security'], 'Not declared');
        $this->assertSame('DELETE', $operations['token.revoke']['method']);
    }

    public function test_operations_follow_local_path_item_references_and_refuse_others(): void
    {
        $catalog = static function (array $document): SchemaCatalog {
            $path = tempnam(sys_get_temp_dir(), 'openapi');
            file_put_contents($path, json_encode($document));
            register_shutdown_function(static fn () => @unlink($path));

            return new SchemaCatalog($path);
        };

        $operations = $catalog(['openapi' => '3.1.0',
            'paths' => ['/things' => ['$ref' => '#/components/pathItems/Things']],
            'components' => ['pathItems' => ['Things' => ['get' => ['operationId' => 'things.list', 'security' => [['oauth2' => ['things:read']]]]]]],
        ])->operations();
        $this->assertSame(['GET', '/things'], [$operations['things.list']['method'], $operations['things.list']['path']]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a resolvable local path item');
        $catalog(['openapi' => '3.1.0', 'paths' => ['/things' => ['$ref' => 'https://elsewhere.example.test/things.json']]])->operations();
    }
}
