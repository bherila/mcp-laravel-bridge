<?php

namespace Bherila\McpLaravelBridge\OpenApi;

/**
 * What a generated document says about the installation it describes. Every
 * URL comes from the application's configuration, so a fork's document
 * describes the fork.
 */
final readonly class OpenApiSettings
{
    /**
     * @param  array<string, string>  $scopes  OAuth scope => description, offered by the authorization-code flow
     * @param  list<string>  $connectionScopes  scopes that open the MCP connection; an operation needing one is OAuth-only
     * @param  list<string>  $extraMediaTypes  media types offered beside application/json for request and response bodies
     */
    public function __construct(
        public string $title,
        public string $version,
        public string $serverUrl,
        public ?string $description = null,
        public ?string $authorizationUrl = null,
        public ?string $tokenUrl = null,
        public array $scopes = [],
        public bool $apiTokens = true,
        public string $apiTokenDescription = 'A personal API token, carrying the permissions chosen when it was created.',
        public array $connectionScopes = [],
        public string $extensionPrefix = 'x-agent',
        public array $extraMediaTypes = [],
        public string $openapi = '3.1.0',
    ) {}

    public function oauth(): bool
    {
        return $this->authorizationUrl !== null && $this->tokenUrl !== null;
    }
}
