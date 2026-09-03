<?php

namespace Bherila\McpLaravelBridge\Http;

use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Http\Request;
use Mcp\Server;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Server\MiddlewareInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;

final class StreamableHttpResponder
{
    /** @param list<MiddlewareInterface>|null $middleware null selects the SDK's secure defaults */
    public function run(Request $request, Server $server, ?array $middleware, int $maxBodyBytes): Response
    {
        $httpFactory = new HttpFactory;
        $psrRequest = (new PsrHttpFactory($httpFactory, $httpFactory, $httpFactory, $httpFactory))
            ->createRequest($request);
        $transport = new StreamableHttpTransport(
            request: $psrRequest,
            responseFactory: $httpFactory,
            streamFactory: $httpFactory,
            middleware: $middleware,
            maxBodyBytes: $maxBodyBytes,
        );
        $response = $server->run($transport);
        $streamed = str_starts_with(strtolower($response->getHeaderLine('Content-Type')), 'text/event-stream');

        return (new HttpFoundationFactory)->createResponse($response, $streamed);
    }
}
