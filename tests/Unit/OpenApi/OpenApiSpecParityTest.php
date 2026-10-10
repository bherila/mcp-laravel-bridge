<?php

namespace Bherila\McpLaravelBridge\Tests\Unit\OpenApi;

use Bherila\McpLaravelBridge\Capabilities\Effect;
use Bherila\McpLaravelBridge\Capabilities\IdempotencyKey;
use Bherila\McpLaravelBridge\Capabilities\Operation;
use Bherila\McpLaravelBridge\Capabilities\OperationRegistry;
use Bherila\McpLaravelBridge\Capabilities\Requirement;
use Bherila\McpLaravelBridge\Capabilities\RestBinding;
use Bherila\McpLaravelBridge\Capabilities\SchemaRef;
use Bherila\McpLaravelBridge\Capabilities\WriteSafety;
use Bherila\McpLaravelBridge\OpenApi\OpenApiDocumentBuilder;
use Bherila\McpLaravelBridge\OpenApi\OpenApiSettings;
use Bherila\McpLaravelBridge\OpenApi\SchemaCatalog;
use Bherila\McpLaravelBridge\Testing\OperationRegistryAssertions;
use Bherila\McpLaravelBridge\Tests\Unit\Capabilities\Fixtures;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The opt-in options that let a spec-first application reproduce its
 * hand-written document (#23).
 */
final class OpenApiSpecParityTest extends TestCase
{
    use OperationRegistryAssertions;

    private const string HAND_WRITTEN = __DIR__.'/../../Fixtures/openapi-hand-written.json';

    private static function settings(array $overrides = []): OpenApiSettings
    {
        return new OpenApiSettings(...[
            'title' => 'Things API',
            'version' => '1',
            'serverUrl' => 'https://things.example.test/api/v1',
            'authorizationUrl' => 'https://things.example.test/oauth/authorize',
            'tokenUrl' => 'https://things.example.test/oauth/token',
            'scopes' => ['things:read' => 'Read things', 'things:write' => 'Write things'],
            'summaries' => false,
            'agentExtensions' => false,
            'parameters' => [
                'ThingId' => ['name' => 'thing', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']],
                'Limit' => ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
                'IdempotencyKey' => ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255]],
            ],
            'responses' => [
                'Error' => ['description' => 'Any error', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
                'NotFound' => ['description' => 'No such thing', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
            ],
            'sharedResponses' => ['default' => 'Error'],
            ...$overrides,
        ]);
    }

    private static function catalog(): SchemaCatalog
    {
        return new SchemaCatalog(self::HAND_WRITTEN);
    }

    /** @return list<Operation> */
    private static function operations(): array
    {
        $mcpInput = ['type' => 'object', 'additionalProperties' => false, 'required' => ['thing', 'expected_version'], 'properties' => [
            'thing' => ['type' => 'string'],
            'expected_version' => ['type' => 'string'],
        ], '$defs' => ['unused' => ['type' => 'string']]];

        return [
            Fixtures::read('things.list', new Requirement(['things:read']), [
                'rest' => new RestBinding('GET', '/things', description: false, parameters: [
                    'Limit',
                    ['name' => 'status', 'in' => 'query', 'schema' => ['$ref' => '#/components/schemas/ThingStatus']],
                ], responseDescriptions: [200 => 'One page of things']),
                // The MCP arguments differ from the query string.
                'input' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['page' => ['type' => 'object']]],
                'output' => SchemaRef::openApi('ThingList'),
            ]),
            Fixtures::write('things.delete', new Requirement(['things:write']), [
                'effect' => Effect::Destructive,
                'rest' => new RestBinding('DELETE', '/things/{thing}', description: 'Deletes one thing at its current version.',
                    parameters: ['ThingId', 'IdempotencyKey'],
                    requestSchema: SchemaRef::openApi('ExpectedVersion'),
                    responseDescriptions: [200 => 'Thing deleted'],
                    responses: [404 => 'NotFound'],
                ),
                'safety' => new WriteSafety(IdempotencyKey::HeaderAndArgument, expectedVersion: true),
                // Uses $defs, which query parameters could never carry.
                'input' => $mcpInput,
                'output' => SchemaRef::openApi('Deletion'),
            ]),
            Fixtures::read('things.download', new Requirement(['things:read']), [
                'effect' => Effect::Download,
                'rest' => new RestBinding('GET', '/things/{thing}/file', summary: 'Download the file', description: false, parameters: [
                    'ThingId',
                    ['name' => 'signature', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']],
                ], responses: [200 => ['description' => 'The file', 'content' => ['application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']]]]]),
            ]),
        ];
    }

    private static function builder(array $settings = [], ?array $operations = null): OpenApiDocumentBuilder
    {
        return new OpenApiDocumentBuilder((new OperationRegistry)->register(...($operations ?? self::operations())), self::settings($settings), self::catalog());
    }

    public function test_a_hand_written_document_is_reproduced_byte_for_byte(): void
    {
        self::assertOpenApiDocumentMatches(self::builder()->full(), self::HAND_WRITTEN);
    }

    public function test_a_delete_takes_a_declared_body_instead_of_query_parameters(): void
    {
        $delete = self::builder()->full()['paths']['/things/{thing}']['delete'];

        self::assertSame(['application/json' => ['schema' => ['$ref' => '#/components/schemas/ExpectedVersion']]], $delete['requestBody']['content']);
        self::assertSame([['$ref' => '#/components/parameters/ThingId'], ['$ref' => '#/components/parameters/IdempotencyKey']], $delete['parameters'], 'No query parameter from the input');

        $derived = self::builder(['parameters' => [], 'responses' => [], 'sharedResponses' => []], [Fixtures::write('things.delete', new Requirement(['things:write']), [
            'rest' => new RestBinding('DELETE', '/things/{thing}', requestSchema: ['type' => 'object', 'properties' => []]),
            'input' => ['type' => 'object', 'oneOf' => [['required' => ['a']]], 'properties' => ['thing' => ['type' => 'integer']]],
        ])])->full()['paths']['/things/{thing}']['delete'];
        self::assertSame(['thing', 'Idempotency-Key'], array_column($derived['parameters'], 'name'), 'Derived parameters keep the path schema and skip the query');
        self::assertSame(['type' => 'integer'], $derived['parameters'][0]['schema']);
        self::assertEquals(new \stdClass, $derived['requestBody']['content']['application/json']['schema']['properties']);
    }

    public function test_a_body_may_be_optional(): void
    {
        $create = self::builder(operations: [Fixtures::write('things.create', new Requirement(['things:write']), [
            'rest' => new RestBinding('POST', '/things', parameters: ['IdempotencyKey'], requestBodyRequired: false),
            'input' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
        ])])->full()['paths']['/things']['post'];

        self::assertFalse($create['requestBody']['required']);
        self::assertSame(['type' => 'object', 'properties' => ['name' => ['type' => 'string']]], $create['requestBody']['content']['application/json']['schema'], 'With declared parameters, a POST input is still the body');
    }

    public function test_inline_request_and_response_schemas_carry_what_they_reference(): void
    {
        $document = self::builder(['parameters' => [], 'responses' => [], 'sharedResponses' => []], [Fixtures::write('things.create', new Requirement(['things:write']), [
            'rest' => new RestBinding('POST', '/things',
                requestSchema: ['type' => 'object', 'properties' => ['status' => ['$ref' => '#/components/schemas/ThingStatus']]],
                responseSchema: ['oneOf' => [['$ref' => '#/components/schemas/Deletion'], ['type' => 'null']]],
            ),
        ])])->full();

        self::assertSame(['Deletion', 'ThingStatus'], array_keys($document['components']['schemas']));

        $nested = self::builder(['parameters' => [], 'responses' => [], 'sharedResponses' => []], [Fixtures::read('things.list', new Requirement(['things:read']), [
            'rest' => new RestBinding('GET', '/things', parameters: [['name' => 'id', 'in' => 'query', 'schema' => ['$ref' => '#/components/schemas/Thing/properties/id']]]),
        ])])->full();
        self::assertSame(['Thing', 'ThingStatus'], array_keys($nested['components']['schemas']), 'A reference into a component carries that component');
        self::assertSame('#/components/schemas/Thing/properties/id', $nested['paths']['/things']['get']['parameters'][0]['schema']['$ref']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('schema [Nope]');
        self::builder(operations: [Fixtures::write('things.create', new Requirement(['things:write']), [
            'rest' => new RestBinding('POST', '/things', parameters: ['IdempotencyKey'], responseSchema: ['$ref' => '#/components/schemas/Nope']),
        ])])->full();
    }

    public function test_a_ref_field_inside_example_data_is_not_a_reference(): void
    {
        $response = ['description' => 'A thing', 'content' => ['application/json' => [
            'schema' => ['type' => 'object', 'default' => ['$ref' => 'literal']],
            'example' => ['$ref' => 'not a pointer'],
            'examples' => ['one' => ['value' => ['$ref' => 'https://data.example.test/x']]],
        ]], 'x-metadata' => ['$ref' => 'internal-id']];
        $document = self::builder(operations: [Fixtures::read('things.list', new Requirement(['things:read']), ['rest' => new RestBinding('GET', '/things', responses: [200 => $response])])])->full();

        self::assertSame(['$ref' => 'not a pointer'], $document['paths']['/things']['get']['responses'][200]['content']['application/json']['example']);
    }

    public function test_only_schemas_inside_declared_fragments_are_normalized(): void
    {
        $response = ['description' => 'A thing', 'content' => ['application/json' => [
            'schema' => ['type' => 'object', 'properties' => []],
            'example' => ['properties' => []],
        ]], 'headers' => ['X-Page' => ['schema' => ['type' => 'object', 'properties' => []]]]];
        $operation = self::builder(operations: [Fixtures::read('things.list', new Requirement(['things:read']), ['rest' => new RestBinding('GET', '/things', responses: [200 => $response])])])->full()['paths']['/things']['get'];

        $json = $operation['responses'][200]['content']['application/json'];
        self::assertEquals(new \stdClass, $json['schema']['properties']);
        self::assertSame(['properties' => []], $json['example'], 'Example data is emitted as written');
        self::assertEquals(new \stdClass, $operation['responses'][200]['headers']['X-Page']['schema']['properties']);
    }

    public function test_a_write_can_document_no_body_whatever_its_input(): void
    {
        $input = ['type' => 'object', 'properties' => ['thing' => ['type' => 'string']]];
        $declared = self::builder(operations: [Fixtures::write('things.publish', new Requirement(['things:write']), [
            'rest' => new RestBinding('POST', '/things/{thing}/publish', parameters: ['ThingId', 'IdempotencyKey'], requestSchema: false),
            'input' => $input,
        ])])->full()['paths']['/things/{thing}/publish']['post'];
        $derived = self::builder(operations: [Fixtures::write('things.publish', new Requirement(['things:write']), [
            'rest' => new RestBinding('POST', '/things/{thing}/publish', requestSchema: false),
            'input' => $input,
        ])])->full()['paths']['/things/{thing}/publish']['post'];

        self::assertArrayNotHasKey('requestBody', $declared);
        self::assertArrayNotHasKey('requestBody', $derived);
        self::assertSame(['thing', 'Idempotency-Key'], array_column($derived['parameters'], 'name'), 'The input still types the path parameter');
        self::assertSame(['type' => 'string'], $derived['parameters'][0]['schema']);
    }

    public function test_a_get_cannot_declare_a_body(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('request body on GET');
        self::builder(operations: [Fixtures::read('things.list', new Requirement(['things:read']), ['rest' => new RestBinding('GET', '/things', requestSchema: ['type' => 'object'])])])->full();
    }

    public function test_responses_take_declared_descriptions_components_and_shared_errors(): void
    {
        $paths = self::builder()->full()['paths'];

        self::assertSame(['200', 'default'], array_map('strval', array_keys($paths['/things']['get']['responses'])));
        self::assertSame('One page of things', $paths['/things']['get']['responses'][200]['description']);
        self::assertSame(['$ref' => '#/components/responses/Error'], $paths['/things']['get']['responses']['default']);
        self::assertSame(['200', '404', 'default'], array_map('strval', array_keys($paths['/things/{thing}']['delete']['responses'])));
        self::assertSame(['$ref' => '#/components/responses/NotFound'], $paths['/things/{thing}']['delete']['responses'][404]);
        self::assertSame(['application/octet-stream'], array_keys($paths['/things/{thing}/file']['get']['responses'][200]['content']), 'A declared response replaces the generated one');

        $declaredDefault = self::builder(operations: [Fixtures::read('things.list', new Requirement(['things:read']), ['rest' => new RestBinding('GET', '/things', responses: ['default' => 'NotFound'])])])->full();
        self::assertSame(['$ref' => '#/components/responses/NotFound'], $declaredDefault['paths']['/things']['get']['responses']['default'], 'A declared status wins over the shared one');
    }

    public function test_summary_description_and_extensions_follow_the_settings_and_the_binding(): void
    {
        $paths = self::builder()->full()['paths'];
        self::assertArrayNotHasKey('summary', $paths['/things']['get']);
        self::assertArrayNotHasKey('description', $paths['/things']['get']);
        self::assertSame('Download the file', $paths['/things/{thing}/file']['get']['summary']);
        self::assertSame('Deletes one thing at its current version.', $paths['/things/{thing}']['delete']['description']);
        self::assertSame([], preg_grep('/^x-/', array_keys($paths['/things/{thing}']['delete'])));

        $own = self::builder(operations: [Fixtures::read('things.list', new Requirement(['things:read']), [
            'rest' => new RestBinding('GET', '/things', description: false),
            'extensions' => ['x-agent-effect' => 'read', 'x-mcp-tool' => 'things_list'],
            'deprecation' => 'Use things.search.',
        ])])->full()['paths']['/things']['get'];
        self::assertSame('read', $own['x-agent-effect'], 'With generated metadata off, the operation may supply its own');
        self::assertSame('things_list', $own['x-mcp-tool']);
        self::assertSame('Deprecated: Use things.search.', $own['description']);
        self::assertTrue($own['deprecated']);
    }

    public function test_security_schemes_take_the_documents_own_wording(): void
    {
        $schemes = self::builder([
            'apiTokenBearerFormat' => 'opaque',
            'oauthDescription' => 'Authorization code with PKCE.',
            'refreshUrl' => false,
        ])->full()['components']['securitySchemes'];

        self::assertSame(['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'opaque', 'description' => self::settings()->apiTokenDescription], $schemes['apiToken']);
        self::assertSame(['type', 'description', 'flows'], array_keys($schemes['oauth2']));
        self::assertSame('Authorization code with PKCE.', $schemes['oauth2']['description']);
        self::assertSame(['authorizationUrl', 'tokenUrl', 'scopes'], array_keys($schemes['oauth2']['flows']['authorizationCode']));

        $refresh = self::builder(['refreshUrl' => 'https://things.example.test/oauth/refresh'])->full()['components']['securitySchemes']['oauth2']['flows']['authorizationCode'];
        self::assertSame('https://things.example.test/oauth/refresh', $refresh['refreshUrl']);
    }

    public function test_declared_components_are_emitted_with_the_schemas_they_reference(): void
    {
        $components = self::builder()->full()['components'];

        self::assertSame(['securitySchemes', 'parameters', 'responses', 'schemas'], array_keys($components));
        self::assertSame(['ThingId', 'Limit', 'IdempotencyKey'], array_keys($components['parameters']));
        self::assertArrayHasKey('Error', $components['schemas'], 'Referenced only from a response component');
        self::assertArrayHasKey('ThingStatus', $components['schemas'], 'Referenced only from an inline parameter');
    }

    /** @return iterable<string, array{0: RestBinding, 1: string, 2?: WriteSafety}> */
    public static function invalidBindings(): iterable
    {
        yield 'missing placeholder' => [new RestBinding('GET', '/things/{thing}', parameters: ['Limit']), 'its path /things/{thing} has [thing]'];
        yield 'stray path parameter' => [new RestBinding('GET', '/things', parameters: ['ThingId']), 'declares path parameters [thing]'];
        yield 'optional path parameter' => [new RestBinding('GET', '/things/{thing}', parameters: [['name' => 'thing', 'in' => 'path', 'schema' => ['type' => 'string']]]), 'as optional'];
        yield 'duplicate' => [new RestBinding('GET', '/things', parameters: ['Limit', ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer']]]), 'twice'];
        yield 'neither schema nor content' => [new RestBinding('GET', '/things', parameters: [['name' => 'q', 'in' => 'query']]), 'exactly one of a schema'];
        yield 'both schema and content' => [new RestBinding('GET', '/things', parameters: [['name' => 'q', 'in' => 'query', 'schema' => [], 'content' => []]]), 'exactly one of a schema'];
        yield 'unknown parameter component' => [new RestBinding('GET', '/things', parameters: ['Cursor']), 'names parameter [Cursor]'];
        yield 'inline $ref' => [new RestBinding('GET', '/things', parameters: [['$ref' => '#/components/parameters/Limit']]), 'neither a component name'];
        yield 'nameless' => [new RestBinding('GET', '/things', parameters: [['in' => 'query']]), 'without a name'];
        yield 'unknown schema' => [new RestBinding('GET', '/things', parameters: [['name' => 'q', 'in' => 'query', 'schema' => ['$ref' => '#/components/schemas/Nope']]]), 'schema [Nope]'];
        yield 'missing part of a schema' => [new RestBinding('GET', '/things', parameters: [['name' => 'q', 'in' => 'query', 'schema' => ['$ref' => '#/components/schemas/Thing/properties/missing']]]), 'which schema [Thing] does not contain'];
        yield 'external reference' => [new RestBinding('GET', '/things', parameters: [['name' => 'q', 'in' => 'query', 'schema' => ['$ref' => 'https://schemas.example.test/q.json']]]), 'not a component of this document'];
        yield 'unknown response component' => [new RestBinding('GET', '/things', responses: [404 => 'Gone']), 'names response [Gone]'];
        yield 'response without description' => [new RestBinding('GET', '/things', responses: [404 => ['content' => []]]), 'with a description'];
        yield 'invalid status' => [new RestBinding('GET', '/things', responses: ['600' => 'Error']), 'status [600]'];
        yield 'description of an undeclared status' => [new RestBinding('GET', '/things', responseDescriptions: [201 => 'Created']), 'status [201]'];
        yield 'undocumented idempotency header' => [new RestBinding('POST', '/things', parameters: []), 'do not document', new WriteSafety(IdempotencyKey::HeaderAndArgument)];
        yield 'required header documented optional' => [new RestBinding('POST', '/things', parameters: [['name' => 'idempotency-key', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string']]]), 'mark optional', new WriteSafety(IdempotencyKey::Header)];
    }

    #[DataProvider('invalidBindings')]
    public function test_a_declaration_the_document_could_not_honour_is_refused(RestBinding $rest, string $message, ?WriteSafety $safety = null): void
    {
        $operation = $safety === null
            ? Fixtures::read('things.x', new Requirement(['things:read']), ['rest' => $rest])
            : Fixtures::write('things.x', new Requirement(['things:write']), ['rest' => $rest, 'safety' => $safety]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);
        self::builder(operations: [$operation])->full();
    }

    public function test_a_parameter_component_must_be_a_parameter_object(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('OpenApiSettings::$parameters[Cursor] declares parameter [cursor] without exactly one of a schema');
        self::builder(['parameters' => [...self::settings()->parameters, 'Cursor' => ['name' => 'cursor', 'in' => 'query']]])->full();
    }

    public function test_an_unused_path_parameter_component_must_still_be_required(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('OpenApiSettings::$parameters[OtherId] declares path parameter [other] as optional');
        self::builder(['parameters' => [...self::settings()->parameters, 'OtherId' => ['name' => 'other', 'in' => 'path', 'schema' => ['type' => 'string']]]])->full();
    }

    public function test_a_response_component_must_be_a_response_object(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('OpenApiSettings::$responses[Error] declares a response without a description');
        self::builder(['responses' => ['Error' => ['content' => []], 'NotFound' => ['$ref' => '#/components/responses/Error']]])->full();
    }

    public function test_a_response_header_needs_a_schema_or_content(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('header [X-Page]');
        self::builder(operations: [Fixtures::read('things.list', new Requirement(['things:read']), ['rest' => new RestBinding('GET', '/things', responses: [200 => ['description' => 'x', 'headers' => ['X-Page' => ['description' => 'y']]]])])])->full();
    }

    public function test_a_settings_component_must_resolve(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('OpenApiSettings::$responses references schema [Missing]');
        self::builder(['responses' => [
            'Error' => ['description' => 'x', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Missing']]]],
            'NotFound' => ['description' => 'y'],
        ]])->full();
    }

    public function test_the_drift_assertion_fails_on_any_difference(): void
    {
        $changed = self::builder()->full();
        $changed['paths']['/things']['get']['responses'][200]['description'] = 'Changed';

        try {
            self::assertOpenApiDocumentMatches($changed, self::HAND_WRITTEN);
            self::fail('A changed document passed');
        } catch (\PHPUnit\Framework\AssertionFailedError $failure) {
            self::assertStringContainsString('/paths/~1things/get/responses/200/description: differs', $failure->getMessage());
        }

        $reordered = self::builder()->full();
        $reordered['info'] = array_reverse($reordered['info'], true);
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->expectExceptionMessage('not byte for byte');
        self::assertOpenApiDocumentMatches($reordered, self::HAND_WRITTEN);
    }
}
