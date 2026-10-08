<?php

namespace Bherila\McpLaravelBridge\Tests\Unit\OpenApi;

use Bherila\McpLaravelBridge\Capabilities\Availability;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Bherila\McpLaravelBridge\Capabilities\IdempotencyKey;
use Bherila\McpLaravelBridge\Capabilities\McpBinding;
use Bherila\McpLaravelBridge\Capabilities\OperationRegistry;
use Bherila\McpLaravelBridge\Capabilities\Requirement;
use Bherila\McpLaravelBridge\Capabilities\RestBinding;
use Bherila\McpLaravelBridge\Capabilities\SchemaRef;
use Bherila\McpLaravelBridge\Capabilities\ScopeRule;
use Bherila\McpLaravelBridge\Capabilities\WriteSafety;
use Bherila\McpLaravelBridge\OpenApi\OpenApiDocumentBuilder;
use Bherila\McpLaravelBridge\OpenApi\OpenApiSettings;
use Bherila\McpLaravelBridge\OpenApi\SchemaCatalog;
use Bherila\McpLaravelBridge\Tests\Unit\Capabilities\Fixtures;
use LogicException;
use PHPUnit\Framework\TestCase;

final class OpenApiDocumentBuilderTest extends TestCase
{
    private function settings(array $overrides = []): OpenApiSettings
    {
        return new OpenApiSettings(...[
            'title' => 'Things API',
            'version' => '1',
            'serverUrl' => 'https://things.example.test/api/v1',
            'authorizationUrl' => 'https://things.example.test/oauth/authorize',
            'tokenUrl' => 'https://things.example.test/oauth/token',
            'scopes' => ['things:read' => 'Read things', 'things:write' => 'Write things', 'mcp:use' => 'Connect over MCP'],
            'connectionScopes' => ['mcp:use'],
            'extraMediaTypes' => ['application/toon'],
            ...$overrides,
        ]);
    }

    private function registry(): OperationRegistry
    {
        return (new OperationRegistry)->register(
            Fixtures::read('things.list', new Requirement(['things:read']), [
                'rest' => new RestBinding('GET', '/projects/{project}/things', pathParameters: ['project']),
                'input' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['project'], 'properties' => [
                    'project' => ['type' => 'string'],
                    'limit' => ['type' => 'integer', 'maximum' => 100, 'description' => 'Page size'],
                ]],
                'output' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['data' => ['type' => 'array']]],
                'tags' => ['things'],
            ]),
            Fixtures::write('things.create', new Requirement(['things:write']), [
                'rest' => new RestBinding('POST', '/things', successStatuses: [200, 201]),
                'safety' => new WriteSafety(idempotencyKey: IdempotencyKey::Header),
                'input' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['name' => ['type' => 'string']]],
                'extensions' => ['risk' => 'write'],
            ]),
            Fixtures::read('things.search', new Requirement(['things:read', 'search:use'], ScopeRule::Any), ['rest' => new RestBinding('GET', '/things/search')]),
            Fixtures::read('health.get', Requirement::publicAccess(), ['rest' => new RestBinding('GET', '/health')]),
            Fixtures::write('token.revoke', Requirement::authenticated(), ['rest' => new RestBinding('DELETE', '/oauth/token', successStatuses: [204]), 'safety' => new WriteSafety(note: 'Revokes this credential.')]),
            Fixtures::write('mcp.exchange', new Requirement(['mcp:use']), ['rest' => new RestBinding('POST', '/mcp'), 'safety' => new WriteSafety(note: 'JSON-RPC.')]),
            Fixtures::read('things.mcp_only', new Requirement(['things:read']), ['rest' => null, 'mcp' => new McpBinding(name: 'things_mcp_only')]),
        );
    }

    public function test_the_document_describes_the_installation_and_every_rest_operation(): void
    {
        $document = (new OpenApiDocumentBuilder($this->registry(), $this->settings()))->full();

        self::assertSame('3.1.0', $document['openapi']);
        self::assertSame([['url' => 'https://things.example.test/api/v1']], $document['servers']);
        $flow = $document['components']['securitySchemes']['oauth2']['flows']['authorizationCode'];
        self::assertSame('https://things.example.test/oauth/authorize', $flow['authorizationUrl']);
        self::assertSame('https://things.example.test/oauth/token', $flow['tokenUrl']);
        self::assertSame('Read things', $flow['scopes']['things:read']);
        self::assertSame('search:use', $flow['scopes']['search:use'] ?? null, 'A required scope the settings omit is still requestable');
        self::assertSame(['type' => 'http', 'scheme' => 'bearer'], array_intersect_key($document['components']['securitySchemes']['apiToken'], ['type' => 1, 'scheme' => 1]));
        self::assertSame(['/health', '/mcp', '/oauth/token', '/projects/{project}/things', '/things', '/things/search'], self::sorted(array_keys($document['paths'])), 'Every REST operation, and the MCP-only one is absent');
    }

    public function test_reads_take_query_parameters_and_writes_take_a_body_in_every_media_type(): void
    {
        $paths = (new OpenApiDocumentBuilder($this->registry(), $this->settings()))->full()['paths'];

        $list = $paths['/projects/{project}/things']['get'];
        self::assertSame([
            ['name' => 'project', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
            ['name' => 'limit', 'in' => 'query', 'required' => false, 'description' => 'Page size', 'schema' => ['type' => 'integer', 'maximum' => 100]],
        ], $list['parameters']);
        self::assertArrayNotHasKey('requestBody', $list);
        self::assertSame(['application/json', 'application/toon'], array_keys($list['responses']['200']['content']));
        self::assertSame(['things'], $list['tags']);
        self::assertSame('read', $list['x-agent-effect']);

        $create = $paths['/things']['post'];
        self::assertSame(['application/json', 'application/toon'], array_keys($create['requestBody']['content']));
        self::assertSame([200, 201], array_keys($create['responses']), 'Status keys (JSON-encoded as strings)');
        self::assertSame('Idempotency-Key', $create['parameters'][0]['name']);
        self::assertTrue($create['parameters'][0]['required']);
        self::assertSame(['idempotency_key' => 'header'], $create['x-agent-write-safety']);
        self::assertTrue($create['x-agent-idempotent']);
        self::assertSame('write', $create['x-agent-risk']);
        self::assertSame('things.create', $create['x-agent-mcp-tool']);
        self::assertArrayNotHasKey('content', $paths['/oauth/token']['delete']['responses']['204']);
    }

    public function test_security_follows_each_requirement(): void
    {
        $paths = (new OpenApiDocumentBuilder($this->registry(), $this->settings()))->full()['paths'];

        self::assertSame([['oauth2' => ['things:read']], ['apiToken' => []]], $paths['/projects/{project}/things']['get']['security']);
        self::assertSame([['oauth2' => ['things:read']], ['oauth2' => ['search:use']], ['apiToken' => []]], $paths['/things/search']['get']['security'], 'Any: one alternative per scope');
        self::assertSame([], $paths['/health']['get']['security'], 'Public');
        self::assertSame([['oauth2' => []], ['apiToken' => []]], $paths['/oauth/token']['delete']['security'], 'Any credential');
        self::assertSame([['oauth2' => ['mcp:use']]], $paths['/mcp']['post']['security'], 'No API token for the MCP connection');

        $writes = (new OperationRegistry)->register(Fixtures::write('things.create', new Requirement(['things:write']), ['rest' => new RestBinding('POST', '/things')]));
        $tokensOnly = (new OpenApiDocumentBuilder($writes, $this->settings(['authorizationUrl' => null, 'tokenUrl' => null])))->full();
        self::assertSame([['apiToken' => []]], $tokensOnly['paths']['/things']['post']['security']);
        self::assertArrayNotHasKey('oauth2', $tokensOnly['components']['securitySchemes']);
    }

    public function test_an_operation_no_offered_scheme_can_authorize_is_refused_rather_than_shown_as_public(): void
    {
        $builder = new OpenApiDocumentBuilder($this->registry(), $this->settings(['authorizationUrl' => null, 'tokenUrl' => null]));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('mcp.exchange');
        $builder->full();
    }

    public function test_filtered_shows_only_what_the_caller_may_use(): void
    {
        $registry = $this->registry();
        $document = (new OpenApiDocumentBuilder($registry, $this->settings()))
            ->filtered(new Availability($registry, Fixtures::flags([])), Fixtures::principal(scopes: ['things:read']));

        self::assertSame(['/health', '/projects/{project}/things', '/things/search'], self::sorted(array_keys($document['paths'])));
    }

    public function test_path_parameters_come_from_the_path_and_a_mismatched_list_is_refused(): void
    {
        $registry = (new OperationRegistry)->register(Fixtures::read('things.show', new Requirement(['things:read']), ['rest' => new RestBinding('GET', '/projects/{project}/things/{thing}')]));
        $show = (new OpenApiDocumentBuilder($registry, $this->settings()))->full()['paths']['/projects/{project}/things/{thing}']['get'];
        self::assertSame(['project', 'thing'], array_column($show['parameters'], 'name'));

        $typed = (new OperationRegistry)->register(Fixtures::read('things.show', new Requirement(['things:read']), [
            'rest' => new RestBinding('GET', '/things/{thing}'),
            'input' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['thing'], 'properties' => ['thing' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Thing id']]],
        ]));
        $parameters = (new OpenApiDocumentBuilder($typed, $this->settings()))->full()['paths']['/things/{thing}']['get']['parameters'];
        self::assertSame([['name' => 'thing', 'in' => 'path', 'required' => true, 'description' => 'Thing id', 'schema' => ['type' => 'integer', 'minimum' => 1]]], $parameters);

        $stale = (new OperationRegistry)->register(Fixtures::read('things.show', new Requirement(['things:read']), ['rest' => new RestBinding('GET', '/things/{thing}', pathParameters: ['id'])]));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('declares path parameters [id]');
        (new OpenApiDocumentBuilder($stale, $this->settings()))->full();
    }

    public function test_a_query_input_with_constraints_parameters_cannot_express_is_refused(): void
    {
        $registry = (new OperationRegistry)->register(Fixtures::read('things.search', new Requirement(['things:read']), [
            'rest' => new RestBinding('GET', '/things/search'),
            'input' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'string']], 'oneOf' => [['required' => ['a']], ['required' => ['b']]]],
        ]));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('oneOf');
        (new OpenApiDocumentBuilder($registry, $this->settings()))->full();
    }

    public function test_empty_schema_maps_encode_as_objects(): void
    {
        $registry = (new OperationRegistry)->register(Fixtures::write('things.ping', new Requirement(['things:write']), [
            'rest' => new RestBinding('POST', '/things/ping'),
            'input' => ['type' => 'object', 'additionalProperties' => false, 'properties' => []],
            'output' => ['type' => 'object', 'properties' => ['meta' => ['type' => 'object', 'properties' => []]]],
        ]));

        $json = (string) json_encode((new OpenApiDocumentBuilder($registry, $this->settings()))->full());

        self::assertStringNotContainsString('"properties":[]', $json);
        self::assertSame(2, substr_count($json, '"properties":{}') / 2, 'Request and response bodies each carry both media types');
    }

    public function test_a_failed_write_is_reported(): void
    {
        $this->expectException(\RuntimeException::class);
        OpenApiDocumentBuilder::write(['openapi' => '3.1.0'], sys_get_temp_dir().'/missing-'.bin2hex(random_bytes(4)).'/openapi.json');
    }

    public function test_a_referenced_input_types_its_path_parameters(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'openapi');
        file_put_contents($path, json_encode(['openapi' => '3.1.0', 'paths' => new \stdClass, 'components' => ['schemas' => [
            'ThingKey' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['thing'], 'properties' => ['thing' => ['type' => 'integer', 'minimum' => 1]]],
        ]]]));
        try {
            $registry = (new OperationRegistry)->register(Fixtures::write('things.archive', new Requirement(['things:write']), [
                'rest' => new RestBinding('POST', '/things/{thing}/archive'),
                'input' => SchemaRef::openApi('ThingKey'),
            ]));
            $parameters = (new OpenApiDocumentBuilder($registry, $this->settings(), new SchemaCatalog($path)))->full()['paths']['/things/{thing}/archive']['post']['parameters'];
        } finally {
            unlink($path);
        }

        self::assertSame(['type' => 'integer', 'minimum' => 1], $parameters[0]['schema']);
    }

    public function test_two_operations_on_one_route_are_refused(): void
    {
        $registry = (new OperationRegistry)->register(
            Fixtures::read('a', new Requirement(['s']), ['rest' => new RestBinding('GET', '/same')]),
            Fixtures::read('b', new Requirement(['s']), ['rest' => new RestBinding('GET', '/same')]),
        );

        $this->expectException(LogicException::class);
        (new OpenApiDocumentBuilder($registry, $this->settings()))->full();
    }

    public function test_spec_first_schemas_resolve_from_the_shipped_document(): void
    {
        $registry = (new OperationRegistry)->register(Fixtures::write('things.create', new Requirement(['things:write']), [
            'rest' => new RestBinding('POST', '/things', successStatuses: [201]),
            'input' => SchemaRef::requestOf('things.create'),
            'output' => SchemaRef::responseOf('things.create'),
        ]));
        $catalog = new SchemaCatalog(__DIR__.'/../../Fixtures/openapi.json');

        $document = (new OpenApiDocumentBuilder($registry, $this->settings(), $catalog))->full();
        $operation = $document['paths']['/things']['post'];

        $request = $operation['requestBody']['content']['application/json']['schema']['$ref'];
        $response = $operation['responses']['201']['content']['application/json']['schema']['$ref'];
        self::assertSame('#/components/schemas/'.$catalog->requestComponent('things.create'), $request);
        self::assertSame('#/components/schemas/'.$catalog->operationComponent('things.create'), $response);
        // Every reference in the document, including nested ones, resolves within it.
        $encoded = (string) json_encode($document, JSON_UNESCAPED_SLASHES);
        preg_match_all('~"\$ref":"#/components/schemas/([^"]+)"~', $encoded, $refs);
        self::assertGreaterThan(2, count($refs[1]), 'Nested references are present');
        foreach (array_unique($refs[1]) as $name) {
            self::assertArrayHasKey($name, $document['components']['schemas'], "Dangling reference to {$name}");
        }
        self::assertStringNotContainsString('#/$defs', $encoded);
    }

    public function test_differences_compare_with_a_shipped_document(): void
    {
        $builder = new OpenApiDocumentBuilder($this->registry(), $this->settings());
        $path = tempnam(sys_get_temp_dir(), 'openapi');
        try {
            OpenApiDocumentBuilder::write($builder->full(), $path);
            self::assertSame([], OpenApiDocumentBuilder::differences($builder->full(), $path));

            $changed = $builder->full();
            $changed['paths']['/things']['post']['summary'] = 'Renamed';
            unset($changed['paths']['/health']);
            $changed['paths']['/new'] = ['get' => []];
            $shape = $builder->full();
            $shape['paths']['/things']['post']['requestBody']['content']['application/json']['schema']['properties'] = [];
            self::assertSame(
                ['/paths/~1things/post/requestBody/content/application~1json/schema/properties: differs (object versus array)'],
                OpenApiDocumentBuilder::differences($shape, $path),
            );

            self::assertSame([
                '/paths/~1health: missing from the generated document',
                '/paths/~1things/post/summary: differs',
                '/paths/~1new: not in the shipped document',
            ], OpenApiDocumentBuilder::differences($changed, $path));
        } finally {
            unlink($path);
        }
    }

    /** @param  list<string>  $values @return list<string> */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
