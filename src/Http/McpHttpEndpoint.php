<?php

namespace Bherila\McpLaravelBridge\Http;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Mcp\Server;
use Symfony\Component\HttpFoundation\Response;

/** Convenience composition for controllers that do not route-register the middleware. */
final readonly class McpHttpEndpoint
{
    public function __construct(
        private StreamableHttpResponder $responder,
        private ?ExceptionHandler $exceptions = null,
    ) {}

    /** @param Server|Closure(Request): Server $server */
    public function run(Request $request, Server|Closure $server, McpHttpPolicy $policy): Response
    {
        $security = new McpHttpSecurityMiddleware($policy, $this->exceptions);

        return $security->handle(
            $request,
            function (Request $request) use ($server, $policy): Response {
                $resolvedServer = $server instanceof Closure ? $server($request) : $server;

                return $this->responder->run(
                    request: $request,
                    server: $resolvedServer,
                    middleware: SdkMiddlewareProfile::forHardenedLaravelEdge(),
                    maxBodyBytes: $policy->maxRequestBodyBytes,
                );
            },
        );
    }
}
