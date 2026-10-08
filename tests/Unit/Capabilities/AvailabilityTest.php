<?php

namespace Bherila\McpLaravelBridge\Tests\Unit\Capabilities;

use Bherila\McpLaravelBridge\Capabilities\Availability;
use Bherila\McpLaravelBridge\Capabilities\Operation;
use Bherila\McpLaravelBridge\Capabilities\OperationPolicy;
use Bherila\McpLaravelBridge\Capabilities\OperationRegistry;
use Bherila\McpLaravelBridge\Capabilities\Principal;
use Bherila\McpLaravelBridge\Capabilities\Requirement;
use Bherila\McpLaravelBridge\Capabilities\ScopeRule;
use PHPUnit\Framework\TestCase;

final class AvailabilityTest extends TestCase
{
    private function registry(): OperationRegistry
    {
        return (new OperationRegistry)->register(
            Fixtures::read('items.list', new Requirement(['items:read'])),
            Fixtures::write('items.create', new Requirement(['items:write'], flags: ['writes'])),
            Fixtures::read('items.search', new Requirement(['items:read', 'search:use'], ScopeRule::Any)),
            Fixtures::read('reports.view', new Requirement(['reports:read'], permissions: ['reports.view'], group: 'reports')),
            Fixtures::read('admin.view', new Requirement(['admin:read'], anyPermissions: ['admin.a', 'admin.b'])),
            Fixtures::read('guided.flow', new Requirement(['items:read']), ['requiresOperations' => ['items.create']]),
        );
    }

    public function test_each_withheld_operation_carries_the_first_failing_reason(): void
    {
        $availability = new Availability($this->registry(), Fixtures::flags(['writes' => false]));
        $report = $availability->evaluate(Fixtures::principal(scopes: ['items:read', 'reports:read'], groups: []));

        self::assertSame(['items.list', 'items.search'], $report->availableIds());
        self::assertSame([
            ['operation' => 'items.create', 'reason' => 'deployment_flag', 'detail' => 'writes'],
            ['operation' => 'reports.view', 'reason' => 'group_not_granted', 'detail' => 'reports'],
            ['operation' => 'admin.view', 'reason' => 'missing_scope', 'detail' => 'admin:read'],
            ['operation' => 'guided.flow', 'reason' => 'depends_on', 'detail' => 'items.create'],
        ], $report->withheldArray());
    }

    public function test_scope_rules_and_permission_axes(): void
    {
        $availability = new Availability($this->registry(), Fixtures::flags(['writes' => true]));

        self::assertNull($availability->withheld(Fixtures::principal(scopes: ['search:use']), 'items.search'), 'Any one scope suffices');
        self::assertSame('items:read|search:use', $availability->withheld(Fixtures::principal(), 'items.search')?->detail);
        self::assertSame('reports.view', $availability->withheld(Fixtures::principal(scopes: ['reports:read']), 'reports.view')?->detail);
        self::assertNull($availability->withheld(Fixtures::principal(scopes: ['reports:read'], permissions: ['reports.view']), 'reports.view'));
        self::assertSame('admin.a|admin.b', $availability->withheld(Fixtures::principal(scopes: ['admin:read']), 'admin.view')?->detail);
        self::assertNull($availability->withheld(Fixtures::principal(scopes: ['admin:read'], permissions: ['admin.b']), 'admin.view'));
        self::assertNull($availability->withheld(Fixtures::principal(scopes: ['items:read', 'items:write']), 'guided.flow'), 'Dependency available');
    }

    public function test_implemented_ignores_the_caller_and_reflects_flags_only(): void
    {
        $off = new Availability($this->registry(), Fixtures::flags(['writes' => false]));
        $on = new Availability($this->registry(), Fixtures::flags(['writes' => true]));

        self::assertNotContains('items.create', array_map(static fn (Operation $o): string => $o->id, $off->implemented()));
        self::assertContains('items.create', array_map(static fn (Operation $o): string => $o->id, $on->implemented()));
    }

    public function test_application_policy_withholds_with_its_own_code(): void
    {
        $policy = new class implements OperationPolicy
        {
            public function withhold(Principal $principal, Operation $operation): ?string
            {
                return $operation->id === 'items.list' ? 'not_a_manager' : null;
            }
        };
        $availability = new Availability($this->registry(), Fixtures::flags([]), $policy);

        $withheld = $availability->withheld(Fixtures::principal(scopes: ['items:read']), 'items.list');
        self::assertSame('policy', $withheld?->reason->value);
        self::assertSame('not_a_manager', $withheld?->detail);
        self::assertSame('unknown_operation', $availability->withheld(Fixtures::principal(), 'nope')?->detail);
    }

    public function test_dependency_failures_propagate_through_chains_and_cycles(): void
    {
        $registry = (new OperationRegistry)->register(
            Fixtures::read('a', new Requirement(['s']), ['requiresOperations' => ['b']]),
            Fixtures::read('b', new Requirement(['s']), ['requiresOperations' => ['c']]),
            Fixtures::write('c', new Requirement(['s'], flags: ['off'])),
            Fixtures::read('x', new Requirement(['s']), ['requiresOperations' => ['y']]),
            Fixtures::read('y', new Requirement(['s']), ['requiresOperations' => ['x']]),
        );
        $availability = new Availability($registry, Fixtures::flags(['off' => false]));
        $principal = Fixtures::principal(scopes: ['s']);

        self::assertSame([], $availability->evaluate($principal)->availableIds());
        self::assertSame('b', $availability->withheld($principal, 'a')?->detail);
        self::assertSame('depends_on', $availability->withheld($principal, 'x')?->reason->value);
    }

    public function test_the_implemented_view_follows_flags_through_dependencies(): void
    {
        $registry = (new OperationRegistry)->register(
            Fixtures::read('a.list', new Requirement(['a:read'])),
            Fixtures::write('a.create', new Requirement(['a:write'], flags: ['writes'])),
            Fixtures::read('a.flow', new Requirement(['a:read']), ['requiresOperations' => ['a.step']]),
            Fixtures::read('a.step', new Requirement(['a:read']), ['requiresOperations' => ['a.create']]),
        );

        $off = new Availability($registry, Fixtures::flags(['writes' => false]));
        self::assertSame(['a.list'], array_map(static fn (Operation $o): string => $o->id, $off->implemented()));

        $on = new Availability($registry, Fixtures::flags(['writes' => true]));
        self::assertCount(4, $on->implemented());
    }
}
