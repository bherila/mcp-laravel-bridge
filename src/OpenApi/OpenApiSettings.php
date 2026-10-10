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
     * @param  bool  $summaries  whether an operation without a declared summary gets its title as one
     * @param  bool  $agentExtensions  whether operations carry the generated `x-agent-*` metadata (effect, idempotency, write safety, MCP tool, dependencies); declared extensions are emitted either way
     * @param  array<string, array<string, mixed>>  $parameters  reusable parameters, emitted under `components/parameters` and named by a binding's `parameters`
     * @param  array<string, array<string, mixed>>  $responses  reusable responses, emitted under `components/responses`
     * @param  array<int|string, string>  $sharedResponses  status (or `default`) => a name from `$responses`, added to every operation that does not declare that status
     * @param  string|null  $apiTokenBearerFormat  the API token scheme's `bearerFormat` hint, when the document gives one
     * @param  string|null  $oauthDescription  the OAuth scheme's description
     * @param  string|false|null  $refreshUrl  the authorization-code flow's refresh URL: null for the token URL, false to leave it out
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
        public bool $summaries = true,
        public bool $agentExtensions = true,
        public array $parameters = [],
        public array $responses = [],
        public array $sharedResponses = [],
        public ?string $apiTokenBearerFormat = null,
        public ?string $oauthDescription = null,
        public string|false|null $refreshUrl = null,
    ) {}

    public function oauth(): bool
    {
        return $this->authorizationUrl !== null && $this->tokenUrl !== null;
    }
}
