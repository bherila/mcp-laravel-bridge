<?php

namespace Bherila\McpLaravelBridge\Capabilities;

/**
 * Which operations a caller may use, and why the rest are withheld. One
 * evaluation feeds every surface: the MCP tool list, a filtered OpenAPI
 * document, the context view an agent reads before planning, and the runtime
 * gate - so they cannot disagree.
 *
 * Order of checks, first failure wins: deployment flags, group, scopes,
 * permissions, application policy, then dependencies on other operations.
 */
final class Availability
{
    public function __construct(
        private readonly OperationRegistry $registry,
        private readonly DeploymentFlags $flags,
        private readonly ?OperationPolicy $policy = null,
    ) {}

    /**
     * Operations the installation offers at all: deployment flags only, but
     * followed through dependencies, so an operation resting on a switched-off
     * one is not advertised. This is what server capabilities advertise,
     * independent of who is asking.
     *
     * @return list<Operation>
     */
    public function implemented(): array
    {
        $memo = [];

        return array_values(array_filter(
            $this->registry->all(),
            fn (Operation $operation): bool => $this->offered($operation->id, $memo, []),
        ));
    }

    /**
     * @param  array<string, bool>  $memo
     * @param  array<string, true>  $visiting
     */
    private function offered(string $operationId, array &$memo, array $visiting): bool
    {
        if (array_key_exists($operationId, $memo)) {
            return $memo[$operationId];
        }
        $operation = $this->registry->find($operationId);
        if ($operation === null || $this->flagFailure($operation) !== null) {
            return $memo[$operationId] = false;
        }
        $visiting[$operationId] = true;
        foreach ($operation->requiresOperations as $dependency) {
            if (isset($visiting[$dependency]) || ! $this->offered($dependency, $memo, $visiting)) {
                return $memo[$operationId] = false;
            }
        }

        return $memo[$operationId] = true;
    }

    public function evaluate(Principal $principal): AvailabilityReport
    {
        $memo = [];
        $available = [];
        $withheld = [];
        foreach ($this->registry->all() as $operation) {
            $reason = $this->resolve($principal, $operation->id, $memo, []);
            if ($reason === null) {
                $available[] = $operation;
            } else {
                $withheld[] = $reason;
            }
        }

        return new AvailabilityReport($available, $withheld);
    }

    /** Null when the caller may use the operation now. */
    public function withheld(Principal $principal, string $operationId): ?Withheld
    {
        $memo = [];

        return $this->resolve($principal, $operationId, $memo, []);
    }

    /**
     * An operation's own checks, then its dependencies resolved recursively:
     * A needing B needing a withheld C is itself withheld. A dependency cycle
     * withholds everything on it.
     *
     * @param  array<string, Withheld|null>  $memo
     * @param  array<string, true>  $visiting
     */
    private function resolve(Principal $principal, string $operationId, array &$memo, array $visiting): ?Withheld
    {
        if (array_key_exists($operationId, $memo)) {
            return $memo[$operationId];
        }
        $operation = $this->registry->find($operationId);
        if ($operation === null) {
            return $memo[$operationId] = new Withheld($operationId, WithheldReason::Policy, 'unknown_operation');
        }
        $direct = $this->check($principal, $operation);
        if ($direct !== null) {
            return $memo[$operationId] = $direct;
        }
        $visiting[$operationId] = true;
        foreach ($operation->requiresOperations as $dependency) {
            if (isset($visiting[$dependency]) || $this->resolve($principal, $dependency, $memo, $visiting) !== null) {
                return $memo[$operationId] = new Withheld($operationId, WithheldReason::DependsOn, $dependency);
            }
        }

        return $memo[$operationId] = null;
    }

    private function check(Principal $principal, Operation $operation): ?Withheld
    {
        $flag = $this->flagFailure($operation);
        if ($flag !== null) {
            return $flag;
        }
        $requirement = $operation->requirement;
        if (! $principal->allowsGroup($requirement->group)) {
            return new Withheld($operation->id, WithheldReason::GroupNotGranted, (string) $requirement->group);
        }
        if (! $requirement->public && $requirement->scopes !== []) {
            $held = array_values(array_filter($requirement->scopes, $principal->hasScope(...)));
            $satisfied = $requirement->scopeRule === ScopeRule::All
                ? count($held) === count($requirement->scopes)
                : $held !== [];
            if (! $satisfied) {
                $missing = $requirement->scopeRule === ScopeRule::All
                    ? array_values(array_diff($requirement->scopes, $held))
                    : $requirement->scopes;

                return new Withheld($operation->id, WithheldReason::MissingScope, implode(
                    $requirement->scopeRule === ScopeRule::All ? ' ' : '|',
                    $missing,
                ));
            }
        }
        $missingPermissions = array_values(array_filter($requirement->permissions, fn (string $permission): bool => ! $principal->can($permission)));
        if ($missingPermissions !== []) {
            return new Withheld($operation->id, WithheldReason::MissingPermission, implode(' ', $missingPermissions));
        }
        if ($requirement->anyPermissions !== [] && array_filter($requirement->anyPermissions, $principal->can(...)) === []) {
            return new Withheld($operation->id, WithheldReason::MissingPermission, implode('|', $requirement->anyPermissions));
        }
        $policy = $this->policy?->withhold($principal, $operation);

        return $policy === null ? null : new Withheld($operation->id, WithheldReason::Policy, $policy);
    }

    private function flagFailure(Operation $operation): ?Withheld
    {
        foreach ($operation->requirement->flags as $flag) {
            if (! $this->flags->enabled($flag)) {
                return new Withheld($operation->id, WithheldReason::DeploymentFlag, $flag);
            }
        }

        return null;
    }
}
