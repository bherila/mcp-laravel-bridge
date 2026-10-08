<?php

namespace Bherila\McpLaravelBridge\Tests\Unit\Capabilities;

use Bherila\McpLaravelBridge\Capabilities\Effect;
use Bherila\McpLaravelBridge\Capabilities\McpBinding;
use Bherila\McpLaravelBridge\Capabilities\OperationToolFactory;
use Bherila\McpLaravelBridge\Capabilities\Requirement;
use Bherila\McpLaravelBridge\Capabilities\SchemaRef;
use Bherila\McpLaravelBridge\Capabilities\ScopeRule;
use Bherila\McpLaravelBridge\OpenApi\SchemaCatalog;
use PHPUnit\Framework\TestCase;

final class OperationToolFactoryTest extends TestCase
{
    public function test_a_spec_first_tool_takes_its_body_and_output_from_the_document(): void
    {
        $factory = new OperationToolFactory(new SchemaCatalog(__DIR__.'/../../Fixtures/openapi.json'));
        $operation = Fixtures::write('things.create', new Requirement(['things:write']), [
            'mcp' => new McpBinding(handler: static fn (string $idempotency_key): array => []),
            'input' => SchemaRef::requestOf('things.create'),
            'output' => SchemaRef::responseOf('things.create'),
        ]);

        $input = $factory->inputSchema($operation);
        self::assertSame(['idempotency_key', 'name'], array_keys($input['properties']));
        self::assertSame(['idempotency_key', 'name'], $input['required']);
        self::assertFalse($input['additionalProperties']);
        self::assertSame('object', $factory->outputSchema($operation)['type'] ?? null);

        $tool = json_decode((string) json_encode($factory->tool($operation)), true);
        self::assertSame('things.create', $tool['name']);
        self::assertSame([['type' => 'oauth2', 'scopes' => ['things:write']]], $tool['securitySchemes']);
        self::assertFalse($tool['annotations']['readOnlyHint']);
        self::assertFalse($tool['annotations']['destructiveHint']);
        self::assertTrue($tool['annotations']['idempotentHint']);
    }

    public function test_a_code_first_tool_uses_its_inline_schema_and_derived_annotations(): void
    {
        $factory = new OperationToolFactory;
        $operation = Fixtures::read('things.search', new Requirement(['things:read', 'search:use'], ScopeRule::Any), [
            'mcp' => new McpBinding(name: 'search-things', handler: static fn (): array => []),
            'input' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['q' => ['type' => 'string']], 'required' => ['q']],
        ]);

        $tool = json_decode((string) json_encode($factory->tool($operation)), true);
        self::assertSame('search-things', $tool['name']);
        self::assertSame(['q'], $tool['inputSchema']['required']);
        self::assertSame([['type' => 'oauth2', 'scopes' => ['things:read']], ['type' => 'oauth2', 'scopes' => ['search:use']]], $tool['securitySchemes']);
        self::assertTrue($tool['annotations']['readOnlyHint']);
        self::assertSame('things.search', $factory->definition($operation)->operationId());

        $external = Fixtures::write('things.send', new Requirement(['things:write']), ['effect' => Effect::ExternalWrite, 'idempotent' => false]);
        $annotations = json_decode((string) json_encode($factory->tool($external)), true)['annotations'];
        self::assertTrue($annotations['destructiveHint']);
        self::assertTrue($annotations['openWorldHint']);
        self::assertFalse($annotations['idempotentHint']);
    }

    public function test_public_operations_advertise_no_auth(): void
    {
        self::assertSame([['type' => 'noauth']], OperationToolFactory::securitySchemes(Fixtures::read('health', Requirement::publicAccess())));
    }

    public function test_every_declared_input_constraint_survives_the_merge(): void
    {
        $declared = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'string']],
            'minProperties' => 1,
            'not' => ['required' => ['a', 'b']],
            'if' => ['required' => ['a']],
            'then' => ['properties' => ['a' => ['minLength' => 2]]],
            'propertyNames' => ['pattern' => '^[a-z]+$'],
        ];
        $input = (new OperationToolFactory)->inputSchema(Fixtures::read('things.pick', new Requirement(['s']), ['input' => $declared]));

        foreach (['minProperties', 'not', 'if', 'then', 'propertyNames'] as $keyword) {
            self::assertSame($declared[$keyword], $input[$keyword] ?? null, $keyword);
        }
        self::assertFalse($input['additionalProperties']);
    }

    public function test_a_write_without_an_idempotency_key_is_not_advertised_as_idempotent(): void
    {
        $confirmOnly = Fixtures::write('things.notify', new Requirement(['s']), [
            'effect' => \Bherila\McpLaravelBridge\Capabilities\Effect::ExternalWrite,
            'safety' => new \Bherila\McpLaravelBridge\Capabilities\WriteSafety(confirm: true),
        ]);
        $tool = json_decode((string) json_encode((new OperationToolFactory)->tool($confirmOnly)), true);

        self::assertFalse($tool['annotations']['idempotentHint']);
        self::assertFalse($confirmOnly->isIdempotent());
        self::assertTrue(Fixtures::write('things.keyed', new Requirement(['s']))->isIdempotent(), 'A declared idempotency key makes it idempotent');
        self::assertTrue(Fixtures::write('things.natural', new Requirement(['s']), ['safety' => new \Bherila\McpLaravelBridge\Capabilities\WriteSafety(confirm: true), 'idempotent' => true])->isIdempotent(), 'Explicit wins');
    }

    public function test_an_operation_without_inputs_serializes_properties_as_an_object(): void
    {
        $handlerless = Fixtures::read('things.ping', new Requirement(['s']), ['mcp' => new McpBinding]);
        $json = (string) json_encode((new OperationToolFactory)->tool($handlerless));

        self::assertStringContainsString('"properties":{}', $json);
        // The schema itself, which applications may hand to other validators, not just the serialized tool.
        self::assertStringContainsString('"properties":{}', (string) json_encode((new OperationToolFactory)->inputSchema($handlerless)));
    }

    public function test_non_tool_bindings_are_refused(): void
    {
        $resource = Fixtures::read('things.doc', new Requirement(['s']), ['mcp' => new McpBinding(kind: \Bherila\McpLaravelBridge\Capabilities\McpKind::Resource, uri: 'thing://doc')]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('not a tool');
        (new OperationToolFactory)->tool($resource);
    }

    public function test_the_endpoint_connection_scope_is_part_of_every_scheme(): void
    {
        $any = Fixtures::read('things.search', new Requirement(['things:read', 'search:use'], ScopeRule::Any));
        $tool = json_decode((string) json_encode((new OperationToolFactory(connectionScopes: ['mcp:use']))->tool($any)), true);

        self::assertSame([
            ['type' => 'oauth2', 'scopes' => ['mcp:use', 'things:read']],
            ['type' => 'oauth2', 'scopes' => ['mcp:use', 'search:use']],
        ], $tool['securitySchemes']);
        self::assertSame([['type' => 'oauth2', 'scopes' => ['mcp:use']]], OperationToolFactory::securitySchemes(Fixtures::read('health', Requirement::publicAccess()), ['mcp:use']));
    }

    public function test_a_repeated_any_scope_yields_one_alternative_and_a_buildable_tool(): void
    {
        $operation = Fixtures::read('things.search', new Requirement(['things:read', 'search:use', 'things:read'], ScopeRule::Any));

        $tool = json_decode((string) json_encode((new OperationToolFactory)->tool($operation)), true);
        self::assertSame([['type' => 'oauth2', 'scopes' => ['things:read']], ['type' => 'oauth2', 'scopes' => ['search:use']]], $tool['securitySchemes']);
        self::assertSame(
            [['type' => 'oauth2', 'scopes' => ['things:read']]],
            OperationToolFactory::securitySchemes(Fixtures::read('x', new Requirement(['things:read', 'things:read'], ScopeRule::Any))),
        );
    }

    public function test_an_empty_output_object_serializes_its_properties_as_an_object(): void
    {
        $operation = Fixtures::read('things.ping', new Requirement(['things:read']), [
            'output' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false, 'oneOf' => [['type' => 'object', 'properties' => []]]],
        ]);

        $json = (string) json_encode((new OperationToolFactory)->outputSchema($operation));
        self::assertStringContainsString('"properties":{}', $json);
        self::assertStringNotContainsString('"properties":[]', $json);
    }

    public function test_a_definition_names_the_document_operations_its_schemas_come_from(): void
    {
        $factory = new OperationToolFactory;
        $operation = Fixtures::write('things.make', new Requirement(['things:write']), [
            'input' => SchemaRef::requestOf('things.create'),
            'output' => SchemaRef::responseOf('things.show'),
        ]);

        $definition = $factory->definition($operation);
        self::assertSame('things.create', $definition->operationId());
        self::assertSame('things.show', $definition->responseOperationId());

        $inline = $factory->definition(Fixtures::read('things.list', new Requirement(['things:read'])));
        self::assertSame('things.list', $inline->operationId());
        self::assertSame('things.list', $inline->responseOperationId());
    }
}
