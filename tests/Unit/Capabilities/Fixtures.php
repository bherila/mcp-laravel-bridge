<?php

namespace Bherila\McpLaravelBridge\Tests\Unit\Capabilities;

use Bherila\McpLaravelBridge\Capabilities\DeploymentFlags;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Bherila\McpLaravelBridge\Capabilities\IdempotencyKey;
use Bherila\McpLaravelBridge\Capabilities\McpBinding;
use Bherila\McpLaravelBridge\Capabilities\Operation;
use Bherila\McpLaravelBridge\Capabilities\Principal;
use Bherila\McpLaravelBridge\Capabilities\Requirement;
use Bherila\McpLaravelBridge\Capabilities\RestBinding;
use Bherila\McpLaravelBridge\Capabilities\WriteSafety;

final class Fixtures
{
    public static function read(string $id, Requirement $requirement, array $extra = []): Operation
    {
        return new Operation(...[
            'id' => $id,
            'title' => $id,
            'description' => "Read {$id}.",
            'effect' => Effect::Read,
            'requirement' => $requirement,
            'rest' => new RestBinding('GET', '/'.$id),
            'mcp' => new McpBinding(handler: static fn (): array => []),
            ...$extra,
        ]);
    }

    public static function write(string $id, Requirement $requirement, array $extra = []): Operation
    {
        return new Operation(...[
            'id' => $id,
            'title' => $id,
            'description' => "Write {$id}.",
            'effect' => Effect::LocalWrite,
            'requirement' => $requirement,
            'safety' => new WriteSafety(idempotencyKey: IdempotencyKey::Header, expectedVersion: true),
            'rest' => new RestBinding('POST', '/'.$id),
            'mcp' => new McpBinding(handler: static fn (): array => []),
            ...$extra,
        ]);
    }

    /** @param list<string> $scopes @param list<string> $permissions @param list<string> $groups */
    public static function principal(array $scopes = [], array $permissions = [], ?array $groups = null): Principal
    {
        return new class($scopes, $permissions, $groups) implements Principal
        {
            public function __construct(private array $scopes, private array $permissions, private ?array $groups) {}

            public function hasScope(string $scope): bool
            {
                return in_array($scope, $this->scopes, true);
            }

            public function can(string $permission): bool
            {
                return in_array($permission, $this->permissions, true);
            }

            public function allowsGroup(?string $group): bool
            {
                return $group === null || $this->groups === null || in_array($group, $this->groups, true);
            }
        };
    }

    /** @param array<string, bool> $values */
    public static function flags(array $values): DeploymentFlags
    {
        return new class($values) implements DeploymentFlags
        {
            public function __construct(private array $values) {}

            public function enabled(string $flag): bool
            {
                return $this->values[$flag] ?? false;
            }
        };
    }
}
