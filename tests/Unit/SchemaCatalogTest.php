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
}
