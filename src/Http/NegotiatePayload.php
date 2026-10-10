<?php

namespace Bherila\McpLaravelBridge\Http;

use Bherila\McpLaravelBridge\Capabilities\OperationRegistry;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Route middleware `NegotiatePayload:<operation id>`, attached by
 * `Route::operation()` after the gate when a {@see PayloadCodecs} collection
 * is bound. A request body in a codec's media type is decoded into the JSON
 * input the controller already reads, where the operation takes a JSON body
 * or lists that type; any other is refused with 415. A JSON response is
 * re-encoded when the Accept header prefers a codec's type. JSON stays the
 * default, and every negotiated response varies on Accept.
 */
final class NegotiatePayload
{
    public function __construct(private readonly Container $container) {}

    public function handle(Request $request, Closure $next, string $operationId): Response
    {
        $codecs = $this->container->make(PayloadCodecs::class);
        $codec = $codecs->forContentType($request->headers->get('Content-Type'));
        // Only a body is decoded: a bodiless request may still carry the header.
        if ($codec !== null && $request->getContent() !== '') {
            $accepted = $this->container->make(OperationRegistry::class)->find($operationId)?->rest?->requestContentTypes ?? [];
            $accepted = array_map(strtolower(...), $accepted);
            $takesJson = array_filter($accepted, PayloadCodecs::isJson(...)) !== [];
            if (! $takesJson && ! in_array(strtolower($codec->mediaType()), $accepted, true)) {
                return self::error(415, "This operation does not accept {$codec->mediaType()} request bodies.");
            }
            try {
                $data = $codec->decode($request->getContent());
            } catch (Throwable) {
                return self::error(400, "The request body is not valid {$codec->mediaType()}.");
            }
            if (! is_array($data)) {
                return self::error(400, 'The request body must decode to an object or a list.');
            }
            // As Laravel builds a JSON request: the decoded body is its input.
            $request->headers->set('Content-Type', PayloadCodecs::JSON);
            $request->setJson(new InputBag($data));
            $request->request = $request->json();
        }

        $codec = $codecs->negotiate($request->headers->get('Accept'));
        if ($codec !== null) {
            // Downstream, the request wants JSON: that is what the controller
            // and the exception handler (validation, 404, 500) must produce
            // for this middleware to re-encode, instead of a redirect or HTML.
            $request->headers->set('Accept', PayloadCodecs::JSON);
        }
        $response = $next($request);
        $response->setVary('Accept', false);
        if ($codec === null || ! self::isJson($response) || $response->getContent() === '' || $response->getContent() === false) {
            return $response;
        }
        try {
            $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $response;
        }

        $encoded = new IlluminateResponse($codec->encode($data), $response->getStatusCode());
        $encoded->headers = clone $response->headers;
        if ($response instanceof JsonResponse && $response->exception !== null) {
            $encoded->withException($response->exception);
        }
        $encoded->headers->set('Content-Type', $codec->mediaType());
        // Validators computed over the JSON bytes do not describe these.
        foreach (['Content-Length', 'ETag', 'Content-MD5', 'Digest', 'Content-Digest', 'Repr-Digest'] as $header) {
            $encoded->headers->remove($header);
        }

        return $encoded;
    }

    private static function isJson(Response $response): bool
    {
        return $response instanceof JsonResponse || PayloadCodecs::isJson((string) $response->headers->get('Content-Type'));
    }

    private static function error(int $status, string $message): JsonResponse
    {
        return new JsonResponse(['message' => $message], $status, ['Cache-Control' => 'no-store', 'Vary' => 'Accept']);
    }
}
