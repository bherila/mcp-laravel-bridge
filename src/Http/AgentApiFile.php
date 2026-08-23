<?php

namespace Bherila\McpLaravelBridge\Http;

readonly class AgentApiFile
{
    public function __construct(
        public string $filename,
        public string $contents,
    ) {}
}
