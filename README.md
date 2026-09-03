# MCP Laravel Bridge

Shared, domain-neutral infrastructure for Bherila Laravel applications that expose an explicit MCP catalog over a canonical versioned REST API.

The package provides:

- an in-process REST transport that forwards only bearer credentials, caller address, and explicitly allow-listed headers;
- JSON, multipart upload, request-binding restoration, and temporary-file cleanup support;
- OpenAPI operation-to-request/response schema resolution with transitive local reference packaging;
- original-wire MCP argument preservation, strict reflected input schemas, and privacy-safe runtime output validation;
- Streamable HTTP PSR/Laravel response conversion and credential-isolated session namespaces.

Applications continue to own tool catalogs, OAuth scopes, authorization, response components, domain DTOs, write flags, and server instructions. This package does not auto-expose OpenAPI operations.

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
    inputSchema: ['type' => 'object', 'properties' => []],
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

## Long-lived workers

The internal REST transport temporarily replaces Laravel's container-bound request while an in-process subrequest runs and always restores it after success or failure. This is safe under normal PHP request execution. Concurrently interleaved requests in the same process (including an Octane task model that permits interleaving during dispatch) are not supported; deploy the bridge in a non-interleaving request context or provide an isolated transport adapter.
