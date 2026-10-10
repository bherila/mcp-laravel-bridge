<?php

namespace Bherila\McpLaravelBridge\Capabilities;

/**
 * How an operation is reached over REST. The fields after `requestContentTypes`
 * only shape the generated OpenAPI document; each defaults to what the builder
 * derives, so a binding that sets none of them documents exactly as before.
 */
final readonly class RestBinding
{
    /**
     * @param  list<string>  $pathParameters
     * @param  list<int>  $successStatuses
     * @param  list<string>  $requestContentTypes
     * @param  string|false|null  $summary  the document's summary: null for the operation title (when the settings print summaries), false for none
     * @param  string|false|null  $description  the document's description when it differs from the operation's (e.g. the MCP tool's prose): null for the operation's, false for none
     * @param  list<string|array<string, mixed>>|null  $parameters  the exact, ordered parameter list: a name from `OpenApiSettings::$parameters` (referenced by `$ref`) or an inline parameter object. Null derives them from the path, the idempotency key and a GET, HEAD or DELETE input.
     * @param  array<string, mixed>|SchemaRef|false|null  $requestSchema  the request body when it differs from the input (e.g. MCP arguments that also carry the path parameters); declaring one is also how a DELETE documents a body. False documents no body, whatever the input.
     * @param  array<string, mixed>|SchemaRef|null  $responseSchema  the success response body when it differs from the output
     * @param  array<int, string>  $responseDescriptions  success status => its description
     * @param  array<int|string, string|array<string, mixed>>  $responses  status => a name from `OpenApiSettings::$responses` or a response object; replaces a generated success response, otherwise adds one
     * @param  bool  $requestBodyRequired  false when the operation also accepts a request without a body
     */
    public function __construct(
        public string $method,
        public string $path,
        public ?string $routeName = null,
        public array $pathParameters = [],
        public array $successStatuses = [200],
        public array $requestContentTypes = ['application/json'],
        public string|false|null $summary = null,
        public string|false|null $description = null,
        public ?array $parameters = null,
        public array|SchemaRef|false|null $requestSchema = null,
        public array|SchemaRef|null $responseSchema = null,
        public array $responseDescriptions = [],
        public array $responses = [],
        public bool $requestBodyRequired = true,
    ) {}
}
