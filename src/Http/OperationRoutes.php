<?php

namespace Bherila\McpLaravelBridge\Http;

use Bherila\McpLaravelBridge\Capabilities\InvalidOperation;
use Bherila\McpLaravelBridge\Capabilities\OperationRegistry;
use Closure;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * Registers a REST route from an operation's binding and gates it with
 * `GateOperation`, so route definitions no longer repeat scopes,
 * permissions or deployment flags. Paths are relative to the enclosing
 * route group, as the binding's path is relative to the API base.
 */
final class OperationRoutes
{
    /** @param  array{0: class-string|object, 1: string}|class-string|Closure  $action */
    public static function register(OperationRegistry $registry, string $operationId, array|string|Closure $action): RoutingRoute
    {
        $operation = $registry->find($operationId) ?? throw new InvalidOperation("Operation [{$operationId}] is not registered.");
        $rest = $operation->rest ?? throw new InvalidOperation("Operation [{$operationId}] has no REST binding.");

        // A declared route name is the route's full name, as the contract
        // assertion looks it up; inside a named group, only the part after
        // the group's prefix is passed on, so the prefix is not doubled.
        $name = $operationId;
        if ($rest->routeName !== null) {
            $stack = Route::getGroupStack();
            $prefix = (string) (end($stack)['as'] ?? '');
            if (! str_starts_with($rest->routeName, $prefix)) {
                throw new InvalidOperation("Operation [{$operationId}] declares route name [{$rest->routeName}], which a route in a group named [{$prefix}…] cannot have.");
            }
            $name = substr($rest->routeName, strlen($prefix));
        }

        return Route::match([strtoupper($rest->method)], $rest->path, $action)
            ->name($name)
            ->middleware(GateOperation::class.':'.$operationId);
    }

    /** `Route::operation('things.list', [ThingController::class, 'index'])`, resolving the registry from the container. */
    public static function macro(): void
    {
        if (! Route::hasMacro('operation')) {
            Route::macro('operation', static fn (string $operationId, array|string|Closure $action): RoutingRoute => OperationRoutes::register(app(OperationRegistry::class), $operationId, $action));
        }
    }
}
