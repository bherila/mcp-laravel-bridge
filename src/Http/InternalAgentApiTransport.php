<?php

namespace Bherila\McpLaravelBridge\Http;

use Bherila\McpLaravelBridge\Json;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Request as RequestFacade;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Executes a versioned REST route without forwarding browser session state. */
class InternalAgentApiTransport implements AgentApiTransport
{
    /** @param list<string> $allowedHeaders */
    public function __construct(
        private readonly Router $router,
        private readonly ExceptionHandler $exceptions,
        private readonly Request $outerRequest,
        private readonly Application $application,
        private readonly string $apiPrefix = '/api/v1',
        private readonly array $allowedHeaders = [],
        private readonly string $temporaryFilePrefix = 'agent-api-',
    ) {}

    public function send(
        string $method,
        string $path,
        array $query = [],
        ?array $json = null,
        ?AgentApiMultipart $multipart = null,
        array $headers = [],
    ): AgentApiTransportResponse {
        if ($json !== null && $multipart !== null) {
            throw new LogicException('An agent API request cannot be JSON and multipart.');
        }

        $temporaryPaths = [];
        $files = [];
        $parameters = array_filter($query, static fn (mixed $value): bool => $value !== null);
        $previousRequest = $this->application->make('request');
        $requestWasBound = false;

        try {
            if ($multipart !== null) {
                $parameters = $multipart->fields;
                foreach ($multipart->files as $name => $file) {
                    $temporaryPath = tempnam(sys_get_temp_dir(), $this->temporaryFilePrefix);
                    if (! is_string($temporaryPath) || file_put_contents($temporaryPath, $file->contents, LOCK_EX) === false) {
                        if (is_string($temporaryPath)) {
                            $temporaryPaths[] = $temporaryPath;
                        }
                        throw new RuntimeException('The MCP upload could not be prepared.');
                    }
                    $temporaryPaths[] = $temporaryPath;
                    $files[$name] = new UploadedFile($temporaryPath, $file->filename, test: true);
                }
            }

            $request = Request::create(
                uri: rtrim($this->apiPrefix, '/').'/'.ltrim($path, '/'),
                method: strtoupper($method),
                parameters: $parameters,
                files: $files,
                server: [
                    'HTTP_ACCEPT' => 'application/json',
                    'REMOTE_ADDR' => (string) ($this->outerRequest->ip() ?? '127.0.0.1'),
                ],
                content: $json === null ? null : json_encode($json, JSON_THROW_ON_ERROR),
            );
            $request->headers->set('Accept', 'application/json');
            $authorization = $this->outerRequest->header('Authorization');
            if (is_string($authorization) && $authorization !== '') {
                $request->headers->set('Authorization', $authorization);
            }
            foreach ($this->selectedHeaders($headers) as $name => $value) {
                $request->headers->set($name, $value);
            }
            if ($json !== null) {
                $request->headers->set('Content-Type', 'application/json');
            }
            $request->setUserResolver(fn (?string $guard = null) => $this->outerRequest->user($guard));

            $this->bindRequest($request);
            $requestWasBound = true;
            try {
                $response = $this->router->dispatch($request);
            } catch (Throwable $exception) {
                $response = $this->exceptions->render($request, $exception);
            }

            return new AgentApiTransportResponse(
                status: $response->getStatusCode(),
                json: $this->decodeJson($response),
            );
        } finally {
            if ($requestWasBound) {
                $this->bindRequest($previousRequest);
            }
            foreach ($temporaryPaths as $temporaryPath) {
                @unlink($temporaryPath);
            }
        }
    }

    /** @param array<string, string> $headers @return array<string, string> */
    private function selectedHeaders(array $headers): array
    {
        $allowed = array_map('strtolower', $this->allowedHeaders);

        return array_filter(
            $headers,
            static fn (string $value, string $name): bool => $value !== '' && in_array(strtolower($name), $allowed, true),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    private function bindRequest(Request $request): void
    {
        $this->application->instance('request', $request);
        RequestFacade::clearResolvedInstance();
    }

    /** @return array<string, mixed>|null */
    private function decodeJson(Response $response): ?array
    {
        $content = $response->getContent();

        return is_string($content) && trim($content) !== '' ? Json::decodeObject($content) : null;
    }
}
