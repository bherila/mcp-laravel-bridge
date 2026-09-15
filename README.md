# MCP Laravel Bridge

Shared, domain-neutral infrastructure for Bherila Laravel applications that expose an explicit MCP catalog over a canonical versioned REST API.

The package provides:

- an in-process REST transport that forwards only bearer credentials, caller address, and explicitly allow-listed headers;
- JSON, multipart upload, request-binding restoration, and temporary-file cleanup support;
- OpenAPI operation-to-request/response schema resolution with transitive local reference packaging;
- original-wire MCP argument preservation, strict reflected input schemas, and privacy-safe runtime output validation;
- Streamable HTTP PSR/Laravel response conversion and credential-isolated session namespaces.
- a hardened Laravel HTTP edge profile with exact Origin and independent Host
  enforcement, bounded envelopes, private responses, and a version-aware SDK
  middleware selection;
- a synthetic fixture server and assertions for application conformance tests.

Applications continue to own tool catalogs, OAuth scopes, authorization, response components, domain DTOs, write flags, and server instructions. This package does not auto-expose OpenAPI operations.

## Installation

The package supports PHP 8.3–8.5, Laravel 12/13, and the official PHP MCP SDK
0.7/0.8. Install it as a runtime dependency: MCP routes must continue to work
after `composer install --no-dev`.

```bash
composer require bherila/mcp-laravel-bridge:^0.2.0
```

If Composer cannot yet discover a new release through Packagist, declare the
public VCS repository in the consuming application's root `composer.json`:

```json
{
  "repositories": [
    {"type": "vcs", "url": "https://github.com/bherila/mcp-laravel-bridge"}
  ]
}
```

## Hardened HTTP integration

Use `McpHttpSecurityMiddleware` outside OAuth/authentication middleware so that
preflight responses, authentication challenges, and failures receive the same
CORS and private-cache policy. Bind one policy in the application container;
closure-backed lists are re-evaluated on every request and are suitable for
long-lived workers.

```php
use Bherila\McpLaravelBridge\Http\McpHttpPolicy;

$this->app->singleton(McpHttpPolicy::class, fn () => new McpHttpPolicy(
    allowedOrigins: fn (): array => config('agent_api.mcp_allowed_origins', []),
    allowedHosts: fn (): array => array_values(array_unique([
        McpHttpPolicy::hostFromUrl((string) config('app.url')),
        McpHttpPolicy::hostFromUrl((string) config('bherila-auth.oauth_server.resource')),
    ])),
    maxRequestBodyBytes: (int) config('agent_api.mcp_max_body_bytes', 262_144),
    maxResponseBodyBytes: (int) config('agent_api.mcp_max_response_bytes', 1_048_576),
));
```

Register the route in this order:

```php
use Bherila\McpLaravelBridge\Http\McpHttpSecurityMiddleware;

Route::match(['POST', 'DELETE'], '/api/v1/mcp', AgentMcpController::class)
    ->middleware([
        McpHttpSecurityMiddleware::class,
        ExpectOAuthResource::class,
        'auth:api',
        CheckToken::using('mcp:use'),
        'throttle:60,1',
    ]);
Route::options('/api/v1/mcp', AgentMcpController::class)
    ->middleware(McpHttpSecurityMiddleware::class);
```

Browser origins are canonical HTTP(S) origins. Scheme and non-default port are
part of the match; host and scheme casing is normalized. Default `:80` and
`:443` are omitted to match browser Origin serialization. Wildcards, paths,
userinfo, query strings, and fragments are rejected. Native clients without an
Origin remain supported.

The service Host is checked separately even when Origin is present. Allowed
Hosts are exact authorities (`host` or `host:port`) and must come from trusted
application/resource configuration. Browser origins never expand this list.
`hostFromUrl()` is the supported derivation helper and preserves non-default
ports. The bridge deliberately ignores `Forwarded` and `X-Forwarded-Host`;
configure Laravel trusted proxies and the externally accepted authority
explicitly instead of trusting arbitrary forwarded headers.

Query-string bearer tokens/API keys are rejected. The edge never logs request
arguments, results, credentials, or URLs. Every response is marked private,
`no-store`, and `nosniff`; approved browser callers receive MCP session/version
plus `Mcp-Method`, `Mcp-Name`, and `WWW-Authenticate` exposure. The default
preflight policy accepts the corresponding MCP 2026 request headers, including
`Mcp-Name`; custom header lists are deduplicated case-insensitively. An
unapproved OPTIONS request returns 204 with no `Access-Control-Allow-Origin`,
while an unapproved non-OPTIONS request is rejected before execution.

Controllers can either route-register the middleware and use the responder, or
use `McpHttpEndpoint` as the all-in-one composition:

```php
public function __invoke(
    Request $request,
    AgentMcpServerFactory $servers,
    McpHttpEndpoint $endpoint,
    McpHttpPolicy $policy,
): Response {
    return $endpoint->run(
        $request,
        fn (Request $request): Server => $servers->make($request),
        $policy,
    );
}
```

The server factory form ensures invalid requests and OPTIONS preflights do not
construct an authenticated server. A preconstructed `Server` remains supported
for simple endpoints. Do not apply both integrations to the same route. With route middleware, call
`StreamableHttpResponder::run()` using
`SdkMiddlewareProfile::forHardenedLaravelEdge()`. SDK 0.7 receives its required
protocol-version middleware; SDK 0.8+ lets the transport classify the modern
lifecycle before applying its handshake-only checks.

Request bodies are bounded by both an early Content-Length check and the SDK's
bounded reader. Non-streaming responses and known-length streams are bounded at
the Laravel edge. Applications must additionally keep each tool output schema
closed and bounded (`maxLength`, `maxItems`, and closed object properties),
which bounds individual messages carried by a long-lived SSE response.

For consumer tests, `SyntheticMcpServerFactory::make()` supplies a domain-free
`bridge.ping` tool whose handler directly invokes a callable application
service. `McpHttpConformanceAssertions` checks the common privacy and CORS
contract. This pattern intentionally avoids internal HTTP re-entry:

```php
$server = SyntheticMcpServerFactory::make(
    fn (): array => $applicationService->ping(),
);
```

## Tool authentication metadata

`ToolWithSecuritySchemes` extends the official PHP SDK's `Tool` value object so
applications can publish current-client per-tool authentication requirements
without rewriting `tools/list` responses. It emits both the top-level
`securitySchemes` field and the identical `_meta.securitySchemes` compatibility
mirror required by the [OpenAI authentication
flow](https://developers.openai.com/plugins/build/auth) and [tool metadata
reference](https://developers.openai.com/plugins/reference). Register it through
the SDK registry or `Server::builder()->add()` just like an SDK `Tool`:

```php
use Bherila\McpLaravelBridge\Mcp\ToolWithSecuritySchemes;

$tool = new ToolWithSecuritySchemes(
    securitySchemes: [[
        'type' => 'oauth2',
        'scopes' => ['mcp:use', 'records:read'],
    ]],
    name: 'records.list',
    title: 'List records',
    // Empty JSON object schemas must remain objects on SDK 0.7.
    inputSchema: ['type' => 'object', 'properties' => (object) []],
    description: 'Lists authorized records.',
    annotations: $annotations,
);

$registry->registerTool($tool, $handler);
```

Only `noauth` and `oauth2` schemes are accepted. Scheme counts, aggregate scope
counts, individual RFC 6749 scope tokens, and serialized size are bounded.
Unknown fields, duplicate schemes/scopes, and conflicting compatibility mirrors
fail closed. This metadata never authorizes an invocation: applications must
still validate the bearer token, issuer, audience/resource, expiry, revocation,
client, and scopes before accessing data.

This adapter is needed through official `mcp/sdk` v0.8.1, whose `Tool` schema
does not yet expose the top-level field. The bridge supports and tests both the
v0.7 and v0.8 SDK lines. Prefer the upstream SDK representation once one is
released and proven compatible.

Pass `null` as `StreamableHttpResponder::run()`'s middleware argument only when
using the SDK's local-only defaults. Public Laravel routes should use the
hardened edge profile above. With `mcp/sdk` v0.8+, do not place
`ProtocolVersionMiddleware` in a custom edge stack: the SDK applies it only
after classifying handshake-era requests, allowing the same endpoint to serve
the modern `2026-07-28` lifecycle.

## Schemas and safe validation

`LocalOnlySchemaValidator` is the transport-independent validation seam.
`OriginalShapeSchemaValidator` extends it while preserving JSON object/list
distinctions from the original MCP request. Both reject non-fragment `$ref`
values before Opis can resolve them, and use a null upstream logger so internal
validation errors cannot log payloads or schemas. The optional application
logger receives metadata only.

`SchemaCatalog` packages transitive OpenAPI component references into local
`$defs` and now rejects every external, file, or relative reference. Generated
tool schemas therefore remain self-contained and cannot initiate network or
filesystem retrieval.

## Sessions

`CredentialSessionNamespace::prefix()` hashes the current bearer/access-token
identity. It fails closed when no credential identity is available rather than
sharing one anonymous session namespace. Protocol sessions carry negotiation
state only; applications must keep durable jobs and leases in their own stores
and re-check authorization on every claim, read, download, and completion.

## Upgrading from 0.1

Version 0.2.0 retains the existing transport, schema catalog, tool definition,
and validation APIs. Consumers should move to `^0.2.0`, bind
`McpHttpPolicy`, replace application-owned Origin/Host middleware with
`McpHttpSecurityMiddleware`, and use
`SdkMiddlewareProfile::forHardenedLaravelEdge()` instead of constructing
`ProtocolVersionMiddleware` directly. See [CHANGELOG.md](CHANGELOG.md) for the
session and schema-validation fail-closed changes to account for during the
upgrade.

## Long-lived workers

The internal REST transport temporarily replaces Laravel's container-bound request while an in-process subrequest runs and always restores it after success or failure. This is safe under normal PHP request execution. Concurrently interleaved requests in the same process (including an Octane task model that permits interleaving during dispatch) are not supported; deploy the bridge in a non-interleaving request context or provide an isolated transport adapter.
