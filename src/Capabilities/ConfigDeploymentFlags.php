<?php

namespace Bherila\McpLaravelBridge\Capabilities;

use Closure;

/**
 * Flags read from configuration, re-read on every call (safe in long-lived
 * workers). A flag may list parents that must also be on, so an inner cutover
 * can never re-open what an outer one withdrew. An optional bypass lets a
 * first-party browser session pass agent-only cutovers.
 */
final class ConfigDeploymentFlags implements DeploymentFlags
{
    /**
     * @param  array<string, string|array{key: string, parents?: list<string>}>  $flags  flag name => config key
     * @param  (Closure(string): bool)|null  $bypass  returns true to treat a flag as on
     */
    public function __construct(
        private readonly array $flags,
        private readonly ?Closure $bypass = null,
    ) {}

    public function enabled(string $flag, array $seen = []): bool
    {
        $definition = $this->flags[$flag] ?? null;
        if (isset($seen[$flag])) {
            return false;
        }
        // A bypass stands in for this flag's own value only: its parents are
        // still checked (or bypassed) themselves, so a global kill switch
        // still withdraws everything beneath it.
        $bypassed = $this->bypass !== null && ($this->bypass)($flag);
        if (! $bypassed) {
            if ($definition === null) {
                return false;
            }
            $key = is_string($definition) ? $definition : $definition['key'];
            if (! (bool) config($key, false)) {
                return false;
            }
        }
        foreach (is_array($definition) ? ($definition['parents'] ?? []) : [] as $parent) {
            if (! $this->enabled($parent, [...$seen, $flag => true])) {
                return false;
            }
        }

        return true;
    }
}
