<?php

namespace Bherila\McpLaravelBridge\Http;

interface AgentApiTransport
{
    /**
     * @param  array<string, scalar|list<scalar>|null>  $query
     * @param  array<string, mixed>|null  $json
     * @param  array<string, string>  $headers
     */
    public function send(
        string $method,
        string $path,
        array $query = [],
        ?array $json = null,
        ?AgentApiMultipart $multipart = null,
        array $headers = [],
    ): AgentApiTransportResponse;
}
