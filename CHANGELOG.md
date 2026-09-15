# Changelog

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
