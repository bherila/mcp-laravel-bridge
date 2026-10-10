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
    /**
     * With a non-empty `PayloadCodecs`, the route also negotiates request and
     * response encodings (`NegotiatePayload`), after the gate.
     *
     * @param  array{0: class-string|object, 1: string}|class-string|Closure  $action
     */
    public static function register(OperationRegistry $registry, string $operationId, array|string|Closure $action, ?PayloadCodecs $codecs = null): RoutingRoute
    {
        $operation = $registry->find($operationId) ?? throw new InvalidOperation("Operation [{$operationId}] is not registered.");
        $rest = $operation->rest ?? throw new InvalidOperation("Operation [{$operationId}] has no REST binding.");
        if (str_contains($operationId, ',')) {
            // Laravel splits middleware parameters on commas, so the gate would
            // receive a fragment of the id.
            throw new InvalidOperation("Operation [{$operationId}] cannot be routed: its id contains a comma.");
        }

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

        $route = Route::match([strtoupper($rest->method)], $rest->path, $action)
            ->name($name)
            ->middleware(GateOperation::class.':'.$operationId);
        if ($codecs !== null && ! $codecs->isEmpty()) {
            // The middleware resolves the collection from the container, so it
            // must be this one, not one built empty on demand.
            if (! app()->bound(PayloadCodecs::class) || app(PayloadCodecs::class) !== $codecs) {
                throw new InvalidOperation("Operation [{$operationId}] is routed with payload codecs that are not the container's PayloadCodecs binding; bind them with app()->instance(PayloadCodecs::class, \$codecs).");
            }
            $route->middleware(NegotiatePayload::class.':'.$operationId);
        }

        return $route;
    }

    /** `Route::operation('things.list', [ThingController::class, 'index'])`, resolving the registry (and any bound `PayloadCodecs`) from the container. */
    public static function macro(): void
    {
        if (! Route::hasMacro('operation')) {
            Route::macro('operation', static fn (string $operationId, array|string|Closure $action): RoutingRoute => OperationRoutes::register(
                app(OperationRegistry::class),
                $operationId,
                $action,
                app()->bound(PayloadCodecs::class) ? app(PayloadCodecs::class) : null,
            ));
        }
    }
}
