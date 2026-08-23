# MCP Laravel Bridge

Shared, domain-neutral infrastructure for Bherila Laravel applications that expose an explicit MCP catalog over a canonical versioned REST API.

The package provides:

- an in-process REST transport that forwards only bearer credentials, caller address, and explicitly allow-listed headers;
- JSON, multipart upload, request-binding restoration, and temporary-file cleanup support;
- OpenAPI operation-to-request/response schema resolution with transitive local reference packaging;
- original-wire MCP argument preservation, strict reflected input schemas, and privacy-safe runtime output validation;
- Streamable HTTP PSR/Laravel response conversion and credential-isolated session namespaces.

Applications continue to own tool catalogs, OAuth scopes, authorization, response components, domain DTOs, write flags, and server instructions. This package does not auto-expose OpenAPI operations.

## Long-lived workers

The internal REST transport temporarily replaces Laravel's container-bound request while an in-process subrequest runs and always restores it after success or failure. This is safe under normal PHP request execution. Concurrently interleaved requests in the same process (including an Octane task model that permits interleaving during dispatch) are not supported; deploy the bridge in a non-interleaving request context or provide an isolated transport adapter.
