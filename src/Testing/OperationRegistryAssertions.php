<?php

namespace Bherila\McpLaravelBridge\Testing;

use Bherila\McpLaravelBridge\Capabilities\Operation;
use Bherila\McpLaravelBridge\Capabilities\OperationRegistry;
use Bherila\McpLaravelBridge\Capabilities\ScopeRule;
use Bherila\McpLaravelBridge\OpenApi\OpenApiDocumentBuilder;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Assert;

/**
 * Pins an application's agent contract in its own test suite: the registry
 * is sound, its routes exist, every REST operation a personal token can use
 * says so, nothing state-changing on the website escapes classification,
 * and what each kind of caller sees changes only on purpose.
 *
 * Snapshots are JSON files; set UPDATE_OPERATION_SNAPSHOTS=1 to rewrite one
 * for an intended change and review the diff.
 */
trait OperationRegistryAssertions
{
    /**
     * Sound declarations, named routes that exist, and both security schemes on
     * every operation that does not need the MCP connection.
     *
     * @param  list<string>  $connectionScopes
     */
    public static function assertOperationRegistryContract(OperationRegistry $registry, ?OpenApiDocumentBuilder $openApi = null, array $connectionScopes = []): void
    {
        Assert::assertSame([], $registry->contractViolations(), 'The registry reports contract violations.');

        foreach ($registry->all() as $operation) {
            $routeName = $operation->rest?->routeName;
            if ($routeName !== null) {
                Assert::assertTrue(RouteFacade::has($routeName), "Operation [{$operation->id}] names route [{$routeName}], which is not registered.");
            }
        }

        if ($openApi === null) {
            return;
        }
        // Document-level invariants (one operation per method and path, every
        // credentialed operation carried by a scheme) hold only for the whole.
        try {
            $openApi->full();
        } catch (\LogicException $exception) {
            Assert::fail('The OpenAPI document cannot be generated: '.$exception->getMessage());
        }
        foreach ($registry->all() as $operation) {
            $requirement = $operation->requirement;
            $connection = array_intersect($requirement->scopes, $connectionScopes);
            $connectionOnly = $requirement->scopeRule === ScopeRule::Any && count($requirement->scopes) > 1
                ? count($connection) === count($requirement->scopes)
                : $connection !== [];
            if ($operation->rest === null || $requirement->public || $connectionOnly) {
                continue;
            }
            $schemes = array_merge(...array_map(array_keys(...), $openApi->operation($operation)['security']));
            Assert::assertContains('oauth2', $schemes, "Operation [{$operation->id}] is not offered to OAuth clients.");
            Assert::assertContains('apiToken', $schemes, "Operation [{$operation->id}] is not offered to personal API tokens.");
        }
    }

    /** Each operation's requirement, so a scope change is a reviewed diff. */
    public static function assertScopeInventory(OperationRegistry $registry, string $snapshotPath): void
    {
        $inventory = [];
        foreach ($registry->all() as $operation) {
            $requirement = $operation->requirement;
            $inventory[$operation->id] = array_filter([
                'public' => $requirement->public ?: null,
                'authenticated' => $requirement->authenticated ?: null,
                'scopes' => $requirement->scopes ?: null,
                'scope_rule' => count($requirement->scopes) > 1 ? $requirement->scopeRule->value : null,
                'permissions' => $requirement->permissions ?: null,
                'any_permissions' => $requirement->anyPermissions ?: null,
                'group' => $requirement->group,
                'flags' => $requirement->flags ?: null,
                'requires' => $operation->requiresOperations ?: null,
            ], static fn (mixed $value): bool => $value !== null);
        }
        ksort($inventory);

        self::assertMatchesOperationSnapshot($inventory, $snapshotPath);
    }

    /**
     * What each named principal can use, for migrating exposure onto the
     * registry without changing it.
     *
     * @param  array<string, list<string>>  $visibleByPrincipal  label => available operation ids (or tool names)
     */
    public static function assertVisibilitySnapshot(array $visibleByPrincipal, string $snapshotPath): void
    {
        $snapshot = [];
        foreach ($visibleByPrincipal as $label => $visible) {
            $visible = array_values(array_unique($visible));
            sort($visible);
            $snapshot[$label] = $visible;
        }

        self::assertMatchesOperationSnapshot($snapshot, $snapshotPath);
    }

    /**
     * Every state-changing route accepted by $isWebRoute (typically the web
     * middleware group) is classified, by route name:
     * - `operation:<id>`, served by an operation with a REST binding;
     * - `mcp-only:<id>`, reachable only over MCP until its REST binding lands;
     * - `missing`, a known gap;
     * - `web-only:<reason>` or `deliberate:<reason>`, never an agent operation.
     * Fails on an unclassified route, a stale entry, an unknown or unbound
     * operation, and an `mcp-only` entry whose REST binding has landed.
     *
     * @param  array<string, string>  $classification
     * @param  (callable(Route): bool)|null  $isWebRoute
     */
    public static function assertWebRoutesClassified(OperationRegistry $registry, array $classification, ?callable $isWebRoute = null): void
    {
        $isWebRoute ??= static fn (Route $route): bool => in_array('web', $route->gatherMiddleware(), true);
        $found = [];
        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            if (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) === [] || ! $isWebRoute($route)) {
                continue;
            }
            $name = $route->getName() ?? implode('|', $route->methods()).' '.$route->uri();
            $found[$name] = true;
            Assert::assertArrayHasKey($name, $classification, "State-changing web route [{$name}] is not classified.");
        }

        foreach ($classification as $name => $entry) {
            Assert::assertArrayHasKey($name, $found, "Classified route [{$name}] no longer exists.");
            [$kind, $detail] = array_pad(explode(':', $entry, 2), 2, '');
            match ($kind) {
                'operation' => Assert::assertNotNull(self::restOperation($registry, $detail), "[{$name}] names operation [{$detail}], which has no REST binding."),
                'mcp-only' => (function () use ($registry, $detail, $name): void {
                    Assert::assertNotNull($registry->find($detail), "[{$name}] names unknown operation [{$detail}].");
                    Assert::assertNull(self::restOperation($registry, $detail), "[{$name}]: operation [{$detail}] now has a REST binding; classify it as operation:{$detail}.");
                })(),
                'missing' => null,
                'web-only', 'deliberate' => Assert::assertNotSame('', trim($detail), "[{$name}] needs a reason."),
                default => Assert::fail("[{$name}] has an unknown classification [{$entry}]."),
            };
        }
    }

    /**
     * The checked OpenAPI document is exactly what the registry generates, so
     * the served document and the reviewed file cannot drift apart. Rewrite
     * it with UPDATE_OPERATION_SNAPSHOTS=1 and review the diff.
     *
     * @param  array<string, mixed>  $generated  e.g. `$builder->full()` with the checked file's installation URLs
     */
    public static function assertOpenApiDocumentMatches(array $generated, string $path): void
    {
        $encoded = OpenApiDocumentBuilder::encode($generated);
        if (getenv('UPDATE_OPERATION_SNAPSHOTS') === '1') {
            OpenApiDocumentBuilder::write($generated, $path);
            Assert::markTestSkipped("OpenAPI document {$path} rewritten; review the diff.");
        }
        Assert::assertFileExists($path, 'Create the document with UPDATE_OPERATION_SNAPSHOTS=1.');
        $differences = OpenApiDocumentBuilder::differences($generated, $path);
        Assert::assertSame([], $differences, "The generated OpenAPI document differs from {$path}:\n".implode("\n", array_slice($differences, 0, 20)));
        Assert::assertSame((string) file_get_contents($path), $encoded, "{$path} matches the generated document in content but not byte for byte (key order or formatting).");
    }

    private static function restOperation(OperationRegistry $registry, string $id): ?Operation
    {
        $operation = $registry->find($id);

        return $operation?->rest === null ? null : $operation;
    }

    /** @param  array<array-key, mixed>  $value */
    private static function assertMatchesOperationSnapshot(array $value, string $path): void
    {
        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        if (getenv('UPDATE_OPERATION_SNAPSHOTS') === '1') {
            file_put_contents($path, $encoded);
            Assert::markTestSkipped("Snapshot {$path} rewritten; review the diff.");
        }
        Assert::assertFileExists($path, 'Create the snapshot with UPDATE_OPERATION_SNAPSHOTS=1.');
        Assert::assertSame((string) file_get_contents($path), $encoded, "Snapshot {$path} changed.");
    }
}
