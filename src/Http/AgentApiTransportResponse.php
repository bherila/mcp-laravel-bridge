<?php

namespace Bherila\McpLaravelBridge\Http;

readonly class AgentApiTransportResponse
{
    /** @param array<string, mixed>|null $json */
    public function __construct(
        public int $status,
        public ?array $json,
    ) {}
}
