<?php

namespace Bherila\McpLaravelBridge\Http;

use Illuminate\Http\Request;
use Mcp\Server;
use Symfony\Component\HttpFoundation\Response;

/** Convenience composition for controllers that do not route-register the middleware. */
final readonly class McpHttpEndpoint
{
    public function __construct(private StreamableHttpResponder $responder) {}

    public function run(Request $request, Server $server, McpHttpPolicy $policy): Response
    {
        $security = new McpHttpSecurityMiddleware($policy);

        return $security->handle(
            $request,
            fn (Request $request): Response => $this->responder->run(
                request: $request,
                server: $server,
                middleware: SdkMiddlewareProfile::forHardenedLaravelEdge(),
                maxBodyBytes: $policy->maxRequestBodyBytes,
            ),
        );
    }
}
