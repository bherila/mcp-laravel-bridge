<?php

namespace Bherila\McpLaravelBridge\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Laravel edge enforcement shared by every public MCP endpoint. */
final readonly class McpHttpSecurityMiddleware
{
    private const array QUERY_CREDENTIAL_NAMES = [
        'access_token',
        'api_key',
        'apikey',
        'authorization',
        'bearer',
        'bearer_token',
        'mcp_token',
        'token',
    ];

    public function __construct(private McpHttpPolicy $policy) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $this->origin($request);
        $rejection = $this->rejectRequest($request, $origin);
        if ($rejection !== null) {
            return $this->secure($rejection, null, $request->isMethod('OPTIONS'));
        }

        if ($request->isMethod('OPTIONS')) {
            if (! $this->validPreflight($request)) {
                // A browser must not learn policy detail for an unapproved
                // preflight. No ACAO header means the browser denies it.
                return $this->secure(new Response('', 204), null, true);
            }

            return $this->secure($this->preflight(), $origin, true);
        }

        $response = $next($request);
        if (! $response instanceof Response) {
            $response = new JsonResponse(['message' => 'The MCP endpoint returned an invalid response.'], 500);
        }

        $response = $this->boundedResponse($response);

        return $this->secure($response, $origin);
    }

    private function rejectRequest(Request $request, ?string $origin): ?Response
    {
        $host = $request->headers->get('Host');
        try {
            $normalizedHost = is_string($host) ? McpHttpPolicy::normalizeHost($host) : null;
        } catch (\InvalidArgumentException) {
            $normalizedHost = null;
        }
        if ($normalizedHost === null || ! in_array($normalizedHost, $this->policy->hosts(), true)) {
            return new JsonResponse(['message' => 'Invalid MCP service host.'], 403);
        }

        if ($request->headers->has('Origin') && $origin === null) {
            return $request->isMethod('OPTIONS')
                ? new Response('', 204)
                : new JsonResponse(['message' => 'Invalid MCP browser origin.'], 403);
        }
        if ($origin !== null && ! in_array($origin, $this->policy->origins(), true)) {
            return $request->isMethod('OPTIONS')
                ? new Response('', 204)
                : new JsonResponse(['message' => 'MCP browser origin is not allowed.'], 403);
        }

        foreach (array_keys($request->query->all()) as $name) {
            if (is_string($name) && in_array(strtolower($name), self::QUERY_CREDENTIAL_NAMES, true)) {
                return new JsonResponse(['message' => 'MCP credentials are not accepted in the query string.'], 400);
            }
        }

        $contentLength = $request->headers->get('Content-Length');
        if (is_string($contentLength) && ctype_digit($contentLength)
            && (int) $contentLength > $this->policy->maxRequestBodyBytes) {
            return new JsonResponse(['message' => 'MCP request exceeds the configured limit.'], 413);
        }

        return null;
    }

    private function origin(Request $request): ?string
    {
        $origin = $request->headers->get('Origin');
        if (! is_string($origin) || $origin === '') {
            return null;
        }

        try {
            return McpHttpPolicy::normalizeOrigin($origin);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function validPreflight(Request $request): bool
    {
        $method = strtoupper((string) $request->headers->get('Access-Control-Request-Method'));
        $headers = array_values(array_filter(array_map(
            static fn (string $header): string => strtolower(trim($header)),
            explode(',', (string) $request->headers->get('Access-Control-Request-Headers')),
        )));
        $allowedHeaders = array_map('strtolower', $this->policy->allowedHeaders);

        return in_array($method, $this->policy->allowedMethods, true)
            && array_diff($headers, $allowedHeaders) === [];
    }

    private function preflight(): Response
    {
        return new Response('', 204, [
            'Access-Control-Allow-Methods' => implode(', ', [...$this->policy->allowedMethods, 'OPTIONS']),
            'Access-Control-Allow-Headers' => implode(', ', $this->policy->allowedHeaders),
            'Access-Control-Max-Age' => '600',
        ]);
    }

    private function boundedResponse(Response $response): Response
    {
        $contentLength = $response->headers->get('Content-Length');
        $knownLength = is_string($contentLength) && ctype_digit($contentLength)
            ? (int) $contentLength
            : null;

        if (! $response instanceof StreamedResponse) {
            $content = $response->getContent();
            $knownLength = is_string($content) ? strlen($content) : $knownLength;
        }

        if ($knownLength !== null && $knownLength > $this->policy->maxResponseBodyBytes) {
            return new JsonResponse([
                'jsonrpc' => '2.0',
                'error' => ['code' => -32603, 'message' => 'MCP response exceeds the configured limit.'],
                'id' => null,
            ], 500);
        }

        return $response;
    }

    private function secure(Response $response, ?string $origin, bool $preflight = false): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        if ($origin !== null && in_array($origin, $this->policy->origins(), true)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Expose-Headers', implode(', ', $this->policy->exposedHeaders));
        }
        $response->setVary('Origin', false);
        if ($preflight) {
            $response->setVary('Access-Control-Request-Method', false);
            $response->setVary('Access-Control-Request-Headers', false);
        }

        return $response;
    }
}
