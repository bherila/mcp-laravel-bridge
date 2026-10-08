<?php

namespace Bherila\McpLaravelBridge\Tests\Unit\Capabilities;

use Bherila\McpLaravelBridge\Capabilities\Effect;
use Bherila\McpLaravelBridge\Capabilities\InvalidOperation;
use Bherila\McpLaravelBridge\Capabilities\McpBinding;
use Bherila\McpLaravelBridge\Capabilities\Operation;
use Bherila\McpLaravelBridge\Capabilities\OperationProvider;
use Bherila\McpLaravelBridge\Capabilities\OperationRegistry;
use Bherila\McpLaravelBridge\Capabilities\Requirement;
use Bherila\McpLaravelBridge\Capabilities\RestBinding;
use Bherila\McpLaravelBridge\Capabilities\WriteSafety;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OperationRegistryTest extends TestCase
{
    public function test_operations_register_from_providers_and_resolve_by_id_and_mcp_name(): void
    {
        $registry = (new OperationRegistry)->register(new class implements OperationProvider
        {
            public function operations(): iterable
            {
                yield Fixtures::read('things.list', new Requirement(['things:read']), ['mcp' => new McpBinding(name: 'list-things', legacyAliases: ['things_list'])]);
                yield Fixtures::write('things.create', new Requirement(['things:write']));
            }
        });

        self::assertSame(['things.list', 'things.create'], array_map(static fn (Operation $o): string => $o->id, $registry->all()));
        self::assertSame('things.list', $registry->findByMcpName('list-things')?->id);
        self::assertSame('things.list', $registry->findByMcpName('things_list')?->id, 'Legacy aliases resolve');
        self::assertNull($registry->find('missing'));
    }

    /** @return iterable<string, array{0: list<Operation>, 1: string}> */
    public static function invalid(): iterable
    {
        yield 'duplicate id' => [[Fixtures::read('a', new Requirement(['s'])), Fixtures::read('a', new Requirement(['s']))], 'registered twice'];
        yield 'no transport' => [[Fixtures::read('a', new Requirement(['s']), ['rest' => null, 'mcp' => null])], 'neither a REST nor an MCP'];
        yield 'requires nothing' => [[Fixtures::read('a', new Requirement)], 'mark it public'];
        yield 'write without safety' => [[Fixtures::write('a', new Requirement(['s']), ['safety' => WriteSafety::none()])], 'write-safety'];
        yield 'mcp name clash' => [[
            Fixtures::read('a', new Requirement(['s']), ['mcp' => new McpBinding(name: 'x')]),
            Fixtures::read('b', new Requirement(['s']), ['mcp' => new McpBinding(name: 'y', legacyAliases: ['x'])]),
        ], 'MCP name [x]'];
    }

    /** @param list<Operation> $operations */
    #[DataProvider('invalid')]
    public function test_impossible_declarations_are_refused(array $operations, string $message): void
    {
        $this->expectException(InvalidOperation::class);
        $this->expectExceptionMessage($message);
        (new OperationRegistry)->register(...$operations);
    }

    public function test_an_authenticated_requirement_is_a_declaration_and_excludes_public(): void
    {
        $registry = (new OperationRegistry)->register(Fixtures::write('token.revoke', Requirement::authenticated()));
        self::assertCount(1, $registry->all(), 'Any credential is a requirement, not nothing');

        $this->expectException(\InvalidArgumentException::class);
        new Requirement(public: true, authenticated: true);
    }

    public function test_with_replaces_named_fields_and_keeps_the_rest(): void
    {
        $operation = Fixtures::read('things.list', new Requirement(['things:read']), ['tags' => ['things'], 'rest' => null]);
        $bound = $operation->with(rest: new RestBinding('GET', '/things'));

        self::assertSame('/things', $bound->rest?->path);
        self::assertNull($operation->rest, 'The original is unchanged');
        self::assertSame(['things'], $bound->tags);
        self::assertSame($operation->mcp, $bound->mcp);
        self::assertEquals(
            array_diff_key(get_object_vars($operation), ['rest' => 1]),
            array_diff_key(get_object_vars($bound), ['rest' => 1]),
        );
    }

    public function test_a_public_requirement_cannot_name_a_credential_group(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('or a group');
        new Requirement(group: 'reports', public: true);
    }

    public function test_public_and_read_only_operations_need_no_scope_or_safety(): void
    {
        $registry = (new OperationRegistry)->register(Fixtures::read('health', Requirement::publicAccess()));

        self::assertCount(1, $registry->all());
    }

    public function test_soft_contract_violations_are_reported_for_a_test_to_pin(): void
    {
        $registry = (new OperationRegistry)->register(
            Fixtures::read('open', new Requirement(['s']), ['input' => ['type' => 'object', 'properties' => []]]),
            Fixtures::read('closed', new Requirement(['s']), ['input' => ['type' => 'object', 'additionalProperties' => false], 'requiresOperations' => ['ghost']]),
        );

        self::assertSame([
            'open: the inline input schema must set additionalProperties: false',
            'closed: depends on unknown operation ghost',
        ], $registry->contractViolations());
    }

    public function test_effects_map_to_annotations(): void
    {
        self::assertTrue(Effect::Read->readOnly());
        self::assertTrue(Effect::Download->readOnly());
        self::assertFalse(Effect::Upload->readOnly());
        self::assertTrue(Effect::Destructive->destructive());
        self::assertTrue(Effect::ExternalWrite->openWorld());
        self::assertFalse(Effect::LocalWrite->destructive());
    }
}
