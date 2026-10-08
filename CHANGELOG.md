# Changelog

## 0.3.1 - 2026-10-08

From the first spec-first adopter:

- **`Requirement::authenticated()`** needs any authenticated credential and no particular scope, e.g. self-revocation. A `Principal` that also implements `AuthenticatedPrincipal::isAuthenticated()` satisfies it. Any other principal is withheld with the new reason `unauthenticated`, so it fails closed. The registry accepts such an operation, a requirement cannot be both public and authenticated, and the MCP scheme is OAuth with only the connection scopes.
- **`Operation::with(...)`** returns a copy with the named fields replaced, e.g. a REST binding taken from the document. Every other field, including any added later, carries over.
- **`SchemaCatalog::operations()`** lists every documented operation with its method, path, prose and `security` as written. `security` is `[]` when explicitly public and `null` when absent, which `scopesForOperation()` cannot distinguish.

## 0.3.0 - 2026-10-08

- **Capability registry** (`Bherila\McpLaravelBridge\Capabilities`). It lets an application declare
  each agent operation once:
  - `Operation`, with an `Effect`, a `Requirement` (scopes with an All/Any rule, permissions all/any,
    group, nested deployment flags), `WriteSafety`, and REST and MCP bindings;
  - input and output schemas, inline or `SchemaRef` into a shipped OpenAPI document.

  `OperationRegistry` refuses impossible declarations: duplicate ids or MCP names (including legacy
  aliases), an operation with no transport, a non-public operation that requires nothing, and a write
  with no safety policy. It reports open schemas and unknown dependencies for a test to pin.
- **Application adapters:** `Principal`, `DeploymentFlags` (`ConfigDeploymentFlags` with nested
  parents and a first-party bypass hook) and `OperationPolicy`.
- **`Availability`** gives `implemented()` (flags only) and `evaluate()`, which returns available
  operations plus withheld ones with a reason an agent can relay: `deployment_flag`, `missing_scope`,
  `missing_permission`, `group_not_granted`, `policy` or `depends_on`.
- **`OperationToolFactory`** builds MCP tools and `ToolDefinition`s from operations:
  - the reflected handler schema with the declared body merged over it, closed;
  - output from the declaration or the document;
  - annotations from `Effect`;
  - OAuth security schemes from the scope rule.
- **The package boundary changes.** The registry generates surfaces from declarations. Applications
  still own authorization, domain actions, response content, instructions and rate limiting.

## 0.2.1

- Allow and expose the MCP 2026 `Mcp-Name` HTTP header by default so approved
  browser clients can preflight `tools/call` and `resources/read` requests.
- Deduplicate configured CORS method/header lists case-insensitively while
  preserving the first configured spelling.

## 0.2.0

- Add the reusable hardened Laravel MCP HTTP policy, middleware, endpoint, and
  SDK-version-aware transport profile.
- Enforce exact browser Origin and independent service Host allow-lists, reject
  query-string credentials, bound request/non-streaming response envelopes, and
  apply private/no-store response headers consistently.
- Add `McpHttpPolicy::hostFromUrl()` for trusted application/resource URL
  configuration, preserving non-default ports.
- Add a synthetic server and common consumer conformance assertions.
- Add `LocalOnlySchemaValidator`; make original-shape validation and OpenAPI
  packaging reject external schema references without exposing validation data
  to logs.
- Make `CredentialSessionNamespace` fail closed if no bearer/access-token
  identity is available. Authenticated consumers are unchanged; deliberately
  anonymous sessionful endpoints must supply their own isolation strategy.
- Test both official `mcp/sdk` 0.7 and 0.8 lines in CI.

### Upgrade from 0.1.x

Require `bherila/mcp-laravel-bridge:^0.2.0`. Bind one `McpHttpPolicy` and put
`McpHttpSecurityMiddleware` before OAuth/auth middleware. Replace custom edge
`ProtocolVersionMiddleware` construction with
`SdkMiddlewareProfile::forHardenedLaravelEdge()`. Remove duplicate application
Origin/Host logic only after its conformance tests pass. Ensure every call to
`CredentialSessionNamespace::prefix()` occurs after authentication.
