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
     * Operations the installation offers at all (flags only). This is what
     * server capabilities advertise, independent of who is asking.
     *
     * @return list<Operation>
     */
    public function implemented(): array
    {
        return array_values(array_filter(
            $this->registry->all(),
            fn (Operation $operation): bool => $this->flagFailure($operation) === null,
        ));
    }

    public function evaluate(Principal $principal): AvailabilityReport
    {
        $available = [];
        $withheld = [];
        $direct = [];
        foreach ($this->registry->all() as $operation) {
            $direct[$operation->id] = $this->check($principal, $operation);
        }
        foreach ($this->registry->all() as $operation) {
            $reason = $direct[$operation->id] ?? $this->dependencyFailure($operation, $direct);
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
        $operation = $this->registry->find($operationId);
        if ($operation === null) {
            return new Withheld($operationId, WithheldReason::Policy, 'unknown_operation');
        }
        $direct = $this->check($principal, $operation);
        if ($direct !== null) {
            return $direct;
        }
        $dependencies = [];
        foreach ($operation->requiresOperations as $dependency) {
            $dependencyOperation = $this->registry->find($dependency);
            $dependencies[$dependency] = $dependencyOperation === null
                ? new Withheld($dependency, WithheldReason::Policy, 'unknown_operation')
                : $this->check($principal, $dependencyOperation);
        }

        return $this->dependencyFailure($operation, $dependencies);
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

    /** @param array<string, Withheld|null> $results */
    private function dependencyFailure(Operation $operation, array $results): ?Withheld
    {
        foreach ($operation->requiresOperations as $dependency) {
            if (($results[$dependency] ?? null) !== null || ! array_key_exists($dependency, $results)) {
                return new Withheld($operation->id, WithheldReason::DependsOn, $dependency);
            }
        }

        return null;
    }
}
