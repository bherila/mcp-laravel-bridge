<?php

namespace Bherila\McpLaravelBridge\Tests\Unit;

use Bherila\McpLaravelBridge\Capabilities\OperationRegistry;
use Bherila\McpLaravelBridge\Capabilities\OperationToolFactory;
use Bherila\McpLaravelBridge\Capabilities\Requirement;
use Bherila\McpLaravelBridge\Capabilities\SchemaRef;
use Bherila\McpLaravelBridge\Mcp\LocalOnlySchemaValidator;
use Bherila\McpLaravelBridge\Mcp\ToolWithSecuritySchemes;
use Bherila\McpLaravelBridge\OpenApi\OpenApiDocumentBuilder;
use Bherila\McpLaravelBridge\OpenApi\OpenApiSettings;
use Bherila\McpLaravelBridge\OpenApi\SchemaCatalog;
use Bherila\McpLaravelBridge\Tests\Unit\Capabilities\Fixtures;
use InvalidArgumentException;
use Mcp\Capability\Discovery\SchemaValidator;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;

final class SchemaPositionRegressionTest extends TestCase
{
    private array $temporary = [];

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            unlink($path);
        }
    }

    public function test_ref_shaped_literals_are_opaque_to_validation_packaging_and_generation(): void
    {
        $literal = json_decode('{"$ref":"https://private.invalid/data","$dynamicRef":"file:///private/data","$recursiveRef":"relative-data","properties":[],"numeric":{"0":{},"1":[]}}', flags: JSON_THROW_ON_ERROR);
        $schema = ['const' => $literal, 'enum' => [$literal], 'default' => $literal, 'example' => $literal,
            'examples' => [$literal], 'x-annotation' => ['$ref' => 'https://private.invalid/annotation']];
        self::assertSame([], (new LocalOnlySchemaValidator)->validateAgainstJsonSchema($literal, $schema));
        $catalog = $this->catalog(['Literal' => $schema]);
        $packaged = $catalog->schema('Literal');
        foreach (['const', 'enum', 'default', 'example', 'examples', 'x-annotation'] as $keyword) {
            self::assertSame(json_encode($schema[$keyword]), json_encode($packaged[$keyword]), $keyword);
        }
        $operation = Fixtures::read('literal', Requirement::publicAccess(), ['output' => SchemaRef::openApi('Literal')]);
        $document = $this->builder((new OperationRegistry)->register($operation), $catalog)->full();
        self::assertSame(json_encode($literal), json_encode($document['components']['schemas']['Literal']['const']));
        $tool = (new OperationToolFactory($catalog))->tool($operation);
        self::assertSame(json_encode($literal), json_encode($tool->jsonSerialize()['outputSchema']['const']));
    }

    public function test_actual_schema_positions_reject_all_external_ref_kinds_before_any_resolver_runs(): void
    {
        $validator = new LocalOnlySchemaValidator;
        $upstream = new Validator;
        $calls = 0;
        foreach (['https', 'file', 'bridgeprobe'] as $scheme) {
            $upstream->resolver()->registerProtocol($scheme, static function () use (&$calls) {
                $calls++;

                return null;
            });
        }
        (new ReflectionProperty(SchemaValidator::class, 'jsonSchemaValidator'))->setValue($validator, $upstream);
        foreach (['$ref', '$dynamicRef', '$recursiveRef'] as $keyword) {
            foreach (['https://private.invalid/schema', 'file:///private/schema', 'bridgeprobe://private/schema', 'relative.json'] as $reference) {
                foreach ($this->positions([$keyword => $reference]) as $schema) {
                    $errors = $validator->validateAgainstJsonSchema('synthetic', $schema);
                    self::assertSame($keyword, $errors[0]['keyword']);
                    $catalog = $this->catalog(['Root' => $schema]);
                    try {
                        $catalog->componentClosure('Root');
                        self::fail('Actual external reference was accepted.');
                    } catch (InvalidArgumentException $error) {
                        self::assertSame('Unsupported response schema reference.', $error->getMessage());
                    }
                }
            }
        }
        self::assertSame(0, $calls);
    }

    public function test_component_refs_in_schema_positions_are_packaged_without_collecting_literal_refs(): void
    {
        foreach ($this->positions(['$ref' => '#/components/schemas/Leaf']) as $schema) {
            $schema['const'] = ['$ref' => '#/components/schemas/NotAComponent'];
            $catalog = $this->catalog(['Root' => $schema, 'Leaf' => ['type' => 'string']]);
            $packaged = $catalog->schema('Root');
            self::assertSame(['type' => 'string'], $packaged['$defs']['Leaf']);
            self::assertSame('#/components/schemas/NotAComponent', $packaged['const']['$ref']);
            self::assertStringContainsString('#/$defs/Leaf', json_encode($packaged, JSON_UNESCAPED_SLASHES));
        }
    }

    public function test_empty_components_nested_schemas_maps_and_booleans_preserve_wire_kinds(): void
    {
        $schema = json_decode('{"type":"object","properties":{"result":{},"never":false,"always":true,"list":{"type":"array","items":{},"prefixItems":[{},false,true]}},"patternProperties":{},"$defs":{},"dependentSchemas":{},"additionalProperties":{},"unevaluatedProperties":{},"allOf":[{}]}');
        $catalog = $this->catalog(['Root' => $schema, 'Empty' => new stdClass]);
        $registry = (new OperationRegistry)->register(
            Fixtures::read('root', Requirement::publicAccess(), ['output' => SchemaRef::openApi('Root')]),
            Fixtures::read('empty', Requirement::publicAccess(), ['output' => SchemaRef::openApi('Empty')]),
        );
        $document = json_decode($this->builder($registry, $catalog)->encode($this->builder($registry, $catalog)->full()));
        self::assertEquals($schema, $document->components->schemas->Root);
        self::assertEquals(new stdClass, $document->components->schemas->Empty);
        self::assertIsArray($catalog->schema('Empty'), 'Retain the public PHP array facade.');
        $tool = (new OperationToolFactory($catalog))->tool($registry->all()[1]);
        self::assertEquals(new stdClass, json_decode(json_encode($tool))->outputSchema);
        self::assertSame([], (new LocalOnlySchemaValidator)->validateAgainstJsonSchema('any value', $catalog->schema('Empty')));
        $direct = new ToolWithSecuritySchemes([['type' => 'noauth']], 'empty', null, ['type' => 'object', 'properties' => []], null, null, outputSchema: []);
        $wire = json_decode(json_encode($direct));
        self::assertEquals(new stdClass, $wire->inputSchema->properties);
        self::assertEquals(new stdClass, $wire->outputSchema);
    }

    public function test_inline_empty_and_literal_schemas_use_the_same_normalization_at_document_and_mcp_boundaries(): void
    {
        $literal = ['properties' => [], 'items' => [], '$defs' => [], '$ref' => 'https://private.invalid/literal'];
        $operation = Fixtures::read('inline', Requirement::publicAccess(), ['output' => [
            'properties' => ['value' => [], 'map' => ['properties' => []]], 'default' => $literal,
        ]]);
        $document = $this->builder((new OperationRegistry)->register($operation))->full();
        $schema = $document['paths']['/inline']['get']['responses'][200]['content']['application/json']['schema'];
        self::assertEquals(new stdClass, $schema['properties']['value']);
        self::assertSame($literal, $schema['default']);
        $tool = (new OperationToolFactory)->tool($operation);
        self::assertSame(json_encode($schema), json_encode($tool->jsonSerialize()['outputSchema']));
    }

    public function test_current_component_only_subset_does_not_silently_gain_local_fragment_support(): void
    {
        foreach (['#/$defs/value', '#anchor'] as $reference) {
            $catalog = $this->catalog(['Root' => ['$ref' => $reference, '$defs' => ['value' => new stdClass]]]);
            try {
                $catalog->schema('Root');
                self::fail('Local fragment packaging changed scope.');
            } catch (InvalidArgumentException $error) {
                self::assertSame('Unsupported response schema reference.', $error->getMessage());
            }
        }
        $catalog = $this->catalog(['Root' => false]);
        self::assertSame(false, $catalog->componentClosure('Root')['Root']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Top-level boolean MCP schemas are not supported.');
        $catalog->schema('Root');
    }

    public function test_decoded_array_schemas_are_not_silently_weakened_to_empty_objects(): void
    {
        foreach ([[], ['properties' => ['value' => []]], ['allOf' => [[]]], ['properties' => []]] as $invalid) {
            $catalog = $this->catalog(['Invalid' => $invalid]);
            try {
                $catalog->componentClosure('Invalid');
                self::fail('An array was accepted in a JSON schema object position.');
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString('must be an object', $error->getMessage());
            }
        }
    }

    public function test_existing_definitions_are_retained_and_collisions_fail_closed(): void
    {
        $catalog = $this->catalog(['Root' => ['$defs' => ['Leaf' => ['type' => 'integer']], 'properties' => ['value' => ['$ref' => '#/components/schemas/Leaf']]], 'Leaf' => ['type' => 'string']]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Conflicting response schema definition names.');
        $catalog->schema('Root');
    }

    public function test_object_schemas_are_guarded_after_serialization_and_encoded_only_once(): void
    {
        $validator = new LocalOnlySchemaValidator;
        $object = new class implements \JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                return ['$ref' => 'file:///private/schema'];
            }
        };
        self::assertSame('$ref', $validator->validateAgainstJsonSchema('synthetic', $object)[0]['keyword']);
        $public = new class
        {
            public string $ref = 'https://private.invalid/schema';
        };
        self::assertSame([], $validator->validateAgainstJsonSchema('synthetic', $public), 'A PHP property named ref has no dollar sign and is ordinary data.');
        $changing = new class implements \JsonSerializable
        {
            public int $calls = 0;

            public function jsonSerialize(): mixed
            {
                return ++$this->calls === 1 ? ['type' => 'string'] : ['$ref' => 'file:///private/schema'];
            }
        };
        self::assertSame([], $validator->validateAgainstJsonSchema('synthetic', $changing));
        self::assertSame(1, $changing->calls);
        $throwing = new class implements \JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                throw new \RuntimeException('synthetic-private-exception');
            }
        };
        self::assertSame([['pointer' => '', 'keyword' => 'schema', 'message' => 'Invalid JSON Schema definition.']], $validator->validateAgainstJsonSchema('synthetic', $throwing));
    }

    private function positions(array $child): array
    {
        $schemas = [$child];
        foreach (['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas', 'dependencies'] as $map) {
            $schemas[] = [$map => ['const' => $child]];
        }
        foreach (['allOf', 'anyOf', 'oneOf', 'prefixItems'] as $list) {
            $schemas[] = [$list => [$child]];
        }
        foreach (['items', 'additionalProperties', 'unevaluatedProperties', 'additionalItems', 'unevaluatedItems', 'contains', 'propertyNames', 'not', 'if', 'then', 'else', 'contentSchema'] as $keyword) {
            $schemas[] = [$keyword => $child];
        }
        $schemas[] = ['items' => [$child]];

        return $schemas;
    }

    private function catalog(array $schemas): SchemaCatalog
    {
        $path = tempnam(sys_get_temp_dir(), 'bridge-schema-');
        $this->temporary[] = $path;
        file_put_contents($path, json_encode(['openapi' => '3.1.0', 'components' => ['schemas' => $schemas], 'paths' => []], JSON_THROW_ON_ERROR));

        return new SchemaCatalog($path);
    }

    private function builder(OperationRegistry $registry, ?SchemaCatalog $catalog = null): OpenApiDocumentBuilder
    {
        return new OpenApiDocumentBuilder($registry, new OpenApiSettings(title: 'Synthetic', version: '1', serverUrl: 'https://synthetic.invalid'), $catalog);
    }
}
