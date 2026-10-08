<?php

namespace Bherila\McpLaravelBridge\Capabilities;

/**
 * Every operation an application exposes to agents, declared once.
 *
 * Registration rejects what can never be right (duplicate ids or MCP names, an
 * operation reachable by no transport, a write that declares no safety policy,
 * a non-public operation that requires nothing). Open schemas are reported by
 * contractViolations() rather than thrown, so an application can migrate and
 * then pin the empty list in a test.
 */
final class OperationRegistry
{
    /** @var array<string, Operation> */
    private array $operations = [];

    /** @var array<string, string> MCP wire name (and legacy alias) => operation id */
    private array $mcpNames = [];

    public function register(Operation|OperationProvider ...$items): self
    {
        foreach ($items as $item) {
            foreach ($item instanceof Operation ? [$item] : $item->operations() as $operation) {
                $this->add($operation);
            }
        }

        return $this;
    }

    /** @return list<Operation> */
    public function all(): array
    {
        return array_values($this->operations);
    }

    public function find(string $id): ?Operation
    {
        return $this->operations[$id] ?? null;
    }

    public function findByMcpName(string $name): ?Operation
    {
        $id = $this->mcpNames[$name] ?? null;

        return $id === null ? null : $this->operations[$id];
    }

    /**
     * Soft contract rules, for a test to pin as []: closed inline object
     * schemas and operations whose dependencies exist.
     *
     * @return list<string>
     */
    public function contractViolations(): array
    {
        $violations = [];
        foreach ($this->operations as $operation) {
            foreach (['input' => $operation->input, 'output' => $operation->output] as $kind => $schema) {
                if (is_array($schema) && ($schema['type'] ?? null) === 'object' && ($schema['additionalProperties'] ?? true) !== false) {
                    $violations[] = "{$operation->id}: the inline {$kind} schema must set additionalProperties: false";
                }
            }
            foreach ($operation->requiresOperations as $dependency) {
                if (! isset($this->operations[$dependency])) {
                    $violations[] = "{$operation->id}: depends on unknown operation {$dependency}";
                }
            }
        }

        return $violations;
    }

    private function add(Operation $operation): void
    {
        $id = $operation->id;
        if ($id === '' || isset($this->operations[$id])) {
            throw new InvalidOperation($id === '' ? 'An operation id is required.' : "Operation [{$id}] is registered twice.");
        }
        if ($operation->rest === null && $operation->mcp === null) {
            throw new InvalidOperation("Operation [{$id}] has neither a REST nor an MCP binding.");
        }
        if (! $operation->requirement->public && $operation->requirement->isEmpty()) {
            throw new InvalidOperation("Operation [{$id}] requires no scope or permission; mark it public explicitly.");
        }
        if (! $operation->effect->readOnly() && ! $operation->safety->declaresAnything()) {
            throw new InvalidOperation("Write operation [{$id}] declares no write-safety policy.");
        }

        $names = [];
        if ($operation->mcp !== null) {
            $names = [(string) $operation->mcpName(), ...$operation->mcp->legacyAliases];
            foreach ($names as $name) {
                if (isset($this->mcpNames[$name])) {
                    throw new InvalidOperation("MCP name [{$name}] is used by both [{$this->mcpNames[$name]}] and [{$id}].");
                }
            }
        }

        $this->operations[$id] = $operation;
        foreach ($names as $name) {
            $this->mcpNames[$name] = $id;
        }
    }
}
