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
}
