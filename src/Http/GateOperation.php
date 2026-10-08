<?php

namespace Bherila\McpLaravelBridge\Http;

use Bherila\McpLaravelBridge\Capabilities\AuthenticatedPrincipal;
use Bherila\McpLaravelBridge\Capabilities\Availability;
use Bherila\McpLaravelBridge\Capabilities\PrincipalResolver;
use Bherila\McpLaravelBridge\Capabilities\WithheldReason;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `GateOperation:<operation id>`: the same availability
 * evaluation that picks MCP tools and filters the OpenAPI document, applied
 * to the REST call. A withheld operation answers with its reason, so the
 * client can relay it: 401 when no credential authenticated the caller, 403
 * (with an RFC 6750 insufficient_scope challenge for a missing scope)
 * otherwise. Needs `Availability` and a `PrincipalResolver` in the container.
 */
final class GateOperation
{
    public function __construct(private readonly Container $container) {}

    public function handle(Request $request, Closure $next, string $operationId): Response
    {
        $principal = $this->container->make(PrincipalResolver::class)->principal($request);
        $withheld = $this->container->make(Availability::class)->withheld($principal, $operationId);
        if ($withheld === null) {
            return $next($request);
        }

        $headers = ['Cache-Control' => 'no-store'];
        $status = 403;
        $message = 'This operation is not available to this caller.';
        // A caller with no credential needs to authenticate first, whatever
        // else the operation would also have required.
        $anonymous = $withheld->reason === WithheldReason::Unauthenticated
            || ($principal instanceof AuthenticatedPrincipal && ! $principal->isAuthenticated());
        if ($anonymous) {
            $status = 401;
            $message = 'Authentication is required.';
            $headers['WWW-Authenticate'] = 'Bearer';
        } elseif ($withheld->reason === WithheldReason::MissingScope) {
            $message = 'This credential lacks the scope this operation needs.';
            // A space-separated scope list means all of them. For alternatives
            // ("a|b") name the first, which is enough on its own.
            $scopes = str_contains($withheld->detail, '|') ? explode('|', $withheld->detail)[0] : $withheld->detail;
            $headers['WWW-Authenticate'] = 'Bearer error="insufficient_scope", scope="'.addcslashes($scopes, '"\\').'"';
        }

        return new JsonResponse([
            'message' => $message,
            'operation' => $operationId,
            'reason' => $withheld->reason->value,
            'detail' => $withheld->detail,
        ], $status, $headers);
    }
}
