<?php

namespace Bherila\McpLaravelBridge\OpenApi;

use Bherila\McpLaravelBridge\Capabilities\Availability;
use Bherila\McpLaravelBridge\Capabilities\IdempotencyKey;
use Bherila\McpLaravelBridge\Capabilities\Operation;
use Bherila\McpLaravelBridge\Capabilities\OperationRegistry;
use Bherila\McpLaravelBridge\Capabilities\Principal;
use Bherila\McpLaravelBridge\Capabilities\ScopeRule;
use Bherila\McpLaravelBridge\Capabilities\SchemaRef;
use JsonException;
use LogicException;
use RuntimeException;
use stdClass;

/**
 * An OpenAPI 3.1 document generated from the operation registry: one entry
 * per operation with a REST binding, its security alternatives derived from
 * its requirement, and its schemas from the declaration or the shipped
 * document. `full()` describes the installation; `filtered()` only what one
 * caller may use. `differences()` compares output with a shipped document so
 * a spec-first application can move onto generation once they match.
 */
final class OpenApiDocumentBuilder
{
    /** @var array<string, true> components referenced by the document being built */
    private array $components = [];

    public function __construct(
        private readonly OperationRegistry $registry,
        private readonly OpenApiSettings $settings,
        private readonly ?SchemaCatalog $catalog = null,
    ) {}

    /** @return array<string, mixed> */
    public function full(): array
    {
        return $this->document($this->registry->all());
    }

    /** @return array<string, mixed> */
    public function filtered(Availability $availability, Principal $principal): array
    {
        return $this->document($availability->evaluate($principal)->available);
    }

    /**
     * @param  list<Operation>  $operations
     * @return array<string, mixed>
     */
    private function document(array $operations): array
    {
        $this->components = [];
        $operations = array_values(array_filter($operations, static fn (Operation $operation): bool => $operation->rest !== null));
        usort($operations, static fn (Operation $a, Operation $b): int => strcmp($a->id, $b->id));

        $paths = [];
        $routes = [];
        foreach ($operations as $operation) {
            $method = strtolower((string) $operation->rest?->method);
            $path = (string) $operation->rest?->path;
            // Templates differing only in placeholder names match the same
            // requests, so they collide too.
            $template = $method.' '.preg_replace('/\{[^}]+\}/', '{}', $path);
            if (isset($routes[$template])) {
                throw new LogicException("Operations [{$routes[$template]}] and [{$operation->id}] are both bound to {$method} {$path}.");
            }
            $routes[$template] = $operation->id;
            $paths[$path][$method] = $this->operation($operation);
        }

        $info = ['title' => $this->settings->title, 'version' => $this->settings->version];
        if ($this->settings->description !== null) {
            $info['description'] = $this->settings->description;
        }

        return [
            'openapi' => $this->settings->openapi,
            'info' => $info,
            'servers' => [['url' => $this->settings->serverUrl]],
            'paths' => $paths === [] ? new stdClass : $paths,
            'components' => $this->documentComponents(),
        ];
    }

    /** @return array<string, mixed> */
    public function operation(Operation $operation): array
    {
        $rest = $operation->rest ?? throw new LogicException("Operation [{$operation->id}] has no REST binding.");
        $method = strtoupper($rest->method);
        $x = $this->settings->extensionPrefix;

        $document = ['operationId' => $operation->id];
        $summary = $rest->summary ?? ($this->settings->summaries ? $operation->title : false);
        if ($summary !== false) {
            $document['summary'] = $summary;
        }
        $description = $rest->description ?? $operation->description;
        if ($operation->deprecation !== null) {
            $deprecated = "Deprecated: {$operation->deprecation}";
            $description = $description === false ? $deprecated : "{$description}\n\n{$deprecated}";
        }
        if ($description !== false) {
            $document['description'] = $description;
        }
        if ($operation->tags !== []) {
            $document['tags'] = $operation->tags;
        }
        if ($operation->deprecation !== null) {
            $document['deprecated'] = true;
        }
        $document['security'] = $this->security($operation);

        $pathParameters = self::pathParameters($operation);
        $body = null;
        $noBody = $rest->requestSchema === false;
        $queryMethod = in_array($method, ['GET', 'HEAD', 'DELETE'], true);
        if ($rest->requestSchema !== null && $rest->requestSchema !== false) {
            if (in_array($method, ['GET', 'HEAD'], true)) {
                // HTTP gives a GET or HEAD body no meaning; a client would drop it.
                throw new LogicException("Operation [{$operation->id}] declares a request body on {$method}, which has no defined meaning.");
            }
            $body = $this->declaredSchema($rest->requestSchema, "Operation [{$operation->id}]");
        }

        if ($rest->parameters !== null) {
            $parameters = $this->declaredParameters($operation, $pathParameters);
            if ($body === null && ! $noBody && $operation->input !== null && ! $queryMethod) {
                $body = $this->schema($operation->input);
            }
        } else {
            // A declared body replaces the input; otherwise the input becomes
            // query parameters or the body, as it always has.
            $input = $body === null && ($queryMethod || ! $noBody) ? $this->schema($operation->input) : null;
            $resolved = $this->resolvedInput($operation);
            $declared = is_array($resolved['properties'] ?? null) ? $resolved['properties'] : [];
            $parameters = array_map(static function (string $name) use ($declared): array {
                // The input's own definition of the parameter, when it has one.
                $schema = is_array($declared[$name] ?? null) ? $declared[$name] : ['type' => 'string'];
                $parameter = ['name' => $name, 'in' => 'path', 'required' => true];
                if (is_string($schema['description'] ?? null)) {
                    $parameter['description'] = $schema['description'];
                    unset($schema['description']);
                }

                return [...$parameter, 'schema' => $schema];
            }, $pathParameters);
            if (in_array($operation->safety->idempotencyKey, [IdempotencyKey::Header, IdempotencyKey::HeaderAndArgument], true)) {
                $parameters[] = [
                    'name' => 'Idempotency-Key',
                    'in' => 'header',
                    'required' => $operation->safety->idempotencyKey === IdempotencyKey::Header,
                    'description' => 'Reuse the same key only to retry an identical request.',
                    'schema' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                ];
            }
            if ($input !== null && $queryMethod) {
                array_push($parameters, ...self::queryParameters($operation->id, (array) $resolved, $pathParameters));
            } elseif ($input !== null) {
                $body = $input;
            }
        }
        if ($body !== null) {
            $document['requestBody'] = [
                'required' => $rest->requestBodyRequired,
                'content' => array_fill_keys(
                    array_values(array_unique([...$rest->requestContentTypes, ...$this->settings->extraMediaTypes])),
                    ['schema' => $body],
                ),
            ];
        }
        if ($parameters !== []) {
            $document['parameters'] = $parameters;
        }

        $document['responses'] = $this->responses($operation);

        if ($this->settings->agentExtensions) {
            $document["{$x}-effect"] = $operation->effect->value;
            $document["{$x}-idempotent"] = $operation->isIdempotent();
            if ($operation->safety->declaresAnything()) {
                $document["{$x}-write-safety"] = array_filter([
                    'idempotency_key' => $operation->safety->idempotencyKey === IdempotencyKey::None ? null : $operation->safety->idempotencyKey->value,
                    'expected_version' => $operation->safety->expectedVersion ?: null,
                    'confirm' => $operation->safety->confirm ?: null,
                    'dry_run_default' => $operation->safety->dryRunDefault ?: null,
                    'note' => $operation->safety->note,
                ], static fn (mixed $value): bool => $value !== null);
            }
            if ($operation->mcpName() !== null) {
                $document["{$x}-mcp-tool"] = $operation->mcpName();
            }
            if ($operation->requiresOperations !== []) {
                $document["{$x}-requires-operations"] = $operation->requiresOperations;
            }
        }
        foreach ($operation->extensions as $key => $value) {
            $name = str_starts_with((string) $key, 'x-') ? (string) $key : "{$x}-{$key}";
            if (array_key_exists($name, $document)) {
                // Generated metadata describes what the operation does; an
                // extension must never restate it (e.g. a destructive write as safe).
                throw new LogicException("Operation [{$operation->id}] declares extension [{$name}], which would overwrite generated metadata.");
            }
            $document[$name] = $value;
        }

        return $document;
    }

    /**
     * Success responses from the binding's statuses, then the responses it
     * declares (replacing a success response of the same status), then the
     * shared ones for any status still undescribed.
     *
     * @return array<int|string, mixed>
     */
    private function responses(Operation $operation): array
    {
        $rest = $operation->rest ?? throw new LogicException("Operation [{$operation->id}] has no REST binding.");
        $stray = array_diff(array_keys($rest->responseDescriptions), $rest->successStatuses);
        if ($stray !== []) {
            throw new LogicException("Operation [{$operation->id}] describes status [".implode(', ', $stray).'], which is not one of its success statuses.');
        }
        $output = $rest->responseSchema !== null ? $this->declaredSchema($rest->responseSchema, "Operation [{$operation->id}]") : $this->schema($operation->output);
        $responses = [];
        foreach ($rest->successStatuses as $status) {
            $response = ['description' => $rest->responseDescriptions[$status] ?? ($status === 201 ? 'Created' : 'Success')];
            if ($output !== null && $status !== 204) {
                $response['content'] = array_fill_keys(
                    array_values(array_unique(['application/json', ...$this->settings->extraMediaTypes])),
                    ['schema' => $output],
                );
            }
            $responses[(string) $status] = $response;
        }
        foreach ($rest->responses as $status => $response) {
            $responses[self::status($operation, $status)] = $this->response($operation, $response);
        }
        foreach ($this->settings->sharedResponses as $status => $name) {
            $status = self::status($operation, $status);
            if (! array_key_exists($status, $responses)) {
                $responses[$status] = $this->response($operation, $name);
            }
        }

        return $responses;
    }

    /** @param  string|array<string, mixed>  $response */
    private function response(Operation $operation, mixed $response): array
    {
        if (is_string($response)) {
            if (! isset($this->settings->responses[$response])) {
                throw new LogicException("Operation [{$operation->id}] names response [{$response}], which OpenApiSettings::\$responses does not define.");
            }

            return ['$ref' => '#/components/responses/'.$response];
        }
        if (! is_array($response) || ! is_string($response['description'] ?? null)) {
            throw new LogicException("Operation [{$operation->id}] declares a response that is neither a component name nor a response object with a description.");
        }
        self::assertResponseObject($response, "Operation [{$operation->id}]");
        $this->referenceComponentsIn($response, "Operation [{$operation->id}]");

        return self::fragmentMaps($response);
    }

    private static function status(Operation $operation, int|string $status): string
    {
        $status = (string) $status;
        if ($status !== 'default' && preg_match('/^[1-5](\d\d|XX)$/', $status) !== 1) {
            throw new LogicException("Operation [{$operation->id}] declares response status [{$status}], which is not an HTTP status, a range such as 4XX, or default.");
        }

        return $status;
    }

    /**
     * A binding's declared parameter list, as written. It must name every path
     * placeholder exactly once (and nothing else in the path), and document the
     * idempotency header the operation reads.
     *
     * @param  list<string>  $placeholders
     * @return list<array<string, mixed>>
     */
    private function declaredParameters(Operation $operation, array $placeholders): array
    {
        $parameters = [];
        $seen = [];
        $inPath = [];
        foreach ($operation->rest?->parameters ?? [] as $entry) {
            if (is_string($entry)) {
                $resolved = $this->settings->parameters[$entry]
                    ?? throw new LogicException("Operation [{$operation->id}] names parameter [{$entry}], which OpenApiSettings::\$parameters does not define.");
                $parameters[] = ['$ref' => '#/components/parameters/'.$entry];
            } elseif (is_array($entry) && ! array_key_exists('$ref', $entry)) {
                $resolved = $entry;
                $this->referenceComponentsIn($entry, "Operation [{$operation->id}]");
                $parameters[] = self::fragmentMaps($entry);
            } else {
                throw new LogicException("Operation [{$operation->id}] declares a parameter that is neither a component name nor an inline parameter object.");
            }
            self::assertParameterObject($resolved, "Operation [{$operation->id}]");
            $name = (string) $resolved['name'];
            $in = (string) $resolved['in'];
            // Header names are case-insensitive; the rest are exact.
            $key = $in.':'.($in === 'header' ? strtolower($name) : $name);
            if (isset($seen[$key])) {
                throw new LogicException("Operation [{$operation->id}] declares the {$in} parameter [{$name}] twice.");
            }
            $seen[$key] = $resolved;
            if ($in === 'path') {
                if (($resolved['required'] ?? null) !== true) {
                    throw new LogicException("Operation [{$operation->id}] declares path parameter [{$name}] as optional; OpenAPI path parameters are always required.");
                }
                $inPath[] = $name;
            }
        }
        $missing = array_diff($placeholders, $inPath);
        $stray = array_diff($inPath, $placeholders);
        if ($missing !== [] || $stray !== []) {
            throw new LogicException("Operation [{$operation->id}] declares path parameters [".implode(', ', $inPath)."] but its path {$operation->rest?->path} has [".implode(', ', $placeholders).'].');
        }
        $key = $operation->safety->idempotencyKey;
        if (in_array($key, [IdempotencyKey::Header, IdempotencyKey::HeaderAndArgument], true)) {
            $header = $seen['header:idempotency-key'] ?? throw new LogicException("Operation [{$operation->id}] reads an Idempotency-Key header that its declared parameters do not document.");
            if ($key === IdempotencyKey::Header && ($header['required'] ?? false) !== true) {
                throw new LogicException("Operation [{$operation->id}] requires an Idempotency-Key header that its declared parameters mark optional.");
            }
        }

        return $parameters;
    }

    /**
     * A parameter object OpenAPI accepts: a name, a location, and exactly one
     * of `schema` or `content`.
     *
     * @param  array<string, mixed>  $parameter
     */
    private static function assertParameterObject(array $parameter, string $owner): void
    {
        if (! is_string($parameter['name'] ?? null) || ! in_array($parameter['in'] ?? null, ['path', 'query', 'header', 'cookie'], true)) {
            throw new LogicException("{$owner} declares a parameter without a name and a location.");
        }
        if (array_key_exists('schema', $parameter) === array_key_exists('content', $parameter)) {
            throw new LogicException("{$owner} declares parameter [{$parameter['name']}] without exactly one of a schema or a content map.");
        }
    }

    /**
     * A response object OpenAPI accepts: a description, or (as a component)
     * only a reference to another one.
     *
     * @param  array<string, mixed>  $response
     */
    private static function assertResponseObject(array $response, string $owner): void
    {
        $reference = array_keys($response) === ['$ref'] && is_string($response['$ref']);
        if (! $reference && ! is_string($response['description'] ?? null)) {
            throw new LogicException("{$owner} declares a response without a description.");
        }
        foreach (is_array($response['headers'] ?? null) ? $response['headers'] : [] as $name => $header) {
            if (is_array($header) && ! array_key_exists('$ref', $header) && array_key_exists('schema', $header) === array_key_exists('content', $header)) {
                throw new LogicException("{$owner} declares header [{$name}] without exactly one of a schema or a content map.");
            }
        }
    }

    /**
     * The input with any reference resolved, to read its property definitions.
     *
     * @return array<string, mixed>|null
     */
    private function resolvedInput(Operation $operation): ?array
    {
        if ($operation->input instanceof SchemaRef) {
            return $operation->input->resolve($this->catalog ?? throw new LogicException('An OpenAPI-referenced schema needs a SchemaCatalog.'));
        }

        return $operation->input === null ? null : self::objectMaps($operation->input);
    }

    /**
     * Carries the schemas a declared fragment references into the document,
     * and refuses a reference the document could not resolve.
     */
    private function referenceComponentsIn(mixed $node, string $owner): void
    {
        if (! is_array($node)) {
            return;
        }
        foreach ($node as $key => $value) {
            if (in_array($key, ['example', 'examples', 'default', 'enum', 'const'], true)) {
                // Literal payload data: a "$ref" there is an ordinary field.
                continue;
            }
            if ($key === '$ref' && is_string($value)) {
                // The component is the pointer's first segment below its
                // section; a reference may point further into it.
                $segments = explode('/', $value);
                $section = $segments[0] === '#' && ($segments[1] ?? null) === 'components' ? ($segments[2] ?? null) : null;
                $name = str_replace(['~1', '~0'], ['/', '~'], $segments[3] ?? '');
                if ($section === 'schemas' && $name !== '') {
                    if ($this->catalog === null || ! in_array($name, $this->catalog->componentIds(), true)) {
                        throw new LogicException("{$owner} references schema [{$name}], which no SchemaCatalog provides.");
                    }
                    $this->components[$name] = true;
                } elseif ($section === 'parameters' && $name !== '') {
                    if (! isset($this->settings->parameters[$name])) {
                        throw new LogicException("{$owner} references [{$value}], which OpenApiSettings::\$parameters does not define.");
                    }
                } elseif ($section === 'responses' && $name !== '') {
                    if (! isset($this->settings->responses[$name])) {
                        throw new LogicException("{$owner} references [{$value}], which OpenApiSettings::\$responses does not define.");
                    }
                } else {
                    throw new LogicException("{$owner} references [{$value}], which is not a component of this document.");
                }
            } else {
                $this->referenceComponentsIn($value, $owner);
            }
        }
    }

    /**
     * The generated document against a shipped one, as a list of JSON-pointer
     * differences; empty when they match exactly.
     *
     * @param  array<string, mixed>  $generated
     * @return list<string>
     */
    public static function differences(array $generated, string $shippedPath): array
    {
        // Decoded to objects, not arrays, so {} and [] stay distinguishable.
        try {
            $shipped = json_decode((string) file_get_contents($shippedPath), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['/: the shipped document is not valid JSON'];
        }
        $generated = json_decode((string) json_encode($generated, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

        return self::diff($shipped, $generated, '');
    }

    /**
     * The document as `write()` saves it, so a checked copy can be compared
     * byte for byte.
     *
     * @param  array<string, mixed>  $document
     */
    public static function encode(array $document): string
    {
        return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    /** @param  array<string, mixed>  $document */
    public static function write(array $document, string $path): void
    {
        $json = self::encode($document);
        if (@file_put_contents($path, $json) !== strlen($json)) {
            throw new RuntimeException("The OpenAPI document could not be written to {$path}.");
        }
    }

    /**
     * Who may call it. Public: nothing. Otherwise each OAuth alternative (all
     * scopes at once, or one per scope for an Any rule), plus a personal API
     * token unless the operation needs the MCP connection scope.
     *
     * @return list<array<string, list<string>>>
     */
    private function security(Operation $operation): array
    {
        $requirement = $operation->requirement;
        if ($requirement->public) {
            return [];
        }
        $scopes = array_values(array_unique($requirement->scopes));
        $alternatives = $requirement->scopeRule === ScopeRule::Any && count($scopes) > 1
            ? array_map(static fn (string $scope): array => ['oauth2' => [$scope]], $scopes)
            : [['oauth2' => $scopes]];
        if (! $this->settings->oauth()) {
            $alternatives = [];
        }
        // A personal token can carry anything but a connection scope: it is
        // useless only when every way to satisfy the rule needs one (All: any
        // of the scopes is one; Any: all of them are).
        $connection = array_intersect($scopes, $this->settings->connectionScopes);
        $needsConnection = $requirement->scopeRule === ScopeRule::Any && count($scopes) > 1
            ? count($connection) === count($scopes)
            : $connection !== [];
        if ($this->settings->apiTokens && ! $needsConnection) {
            $alternatives[] = ['apiToken' => []];
        }
        if ($alternatives === []) {
            // An empty list would read as public.
            throw new LogicException("Operation [{$operation->id}] needs a credential, but the document offers no scheme that can carry it.");
        }

        return $alternatives;
    }

    /** @return array<string, mixed> */
    private function securitySchemes(): array
    {
        $schemes = [];
        if ($this->settings->apiTokens) {
            $schemes['apiToken'] = ['type' => 'http', 'scheme' => 'bearer'];
            if ($this->settings->apiTokenBearerFormat !== null) {
                $schemes['apiToken']['bearerFormat'] = $this->settings->apiTokenBearerFormat;
            }
            $schemes['apiToken']['description'] = $this->settings->apiTokenDescription;
        }
        if ($this->settings->oauth()) {
            // Every scope an operation can require is one a client must be able
            // to request: settings describe them, and any they omit is added.
            $scopes = $this->settings->scopes;
            foreach ($this->registry->all() as $operation) {
                foreach ($operation->requirement->scopes as $scope) {
                    $scopes[$scope] ??= $scope;
                }
            }
            foreach ($this->settings->connectionScopes as $scope) {
                $scopes[$scope] ??= $scope;
            }
            $flow = [
                'authorizationUrl' => (string) $this->settings->authorizationUrl,
                'tokenUrl' => (string) $this->settings->tokenUrl,
            ];
            if ($this->settings->refreshUrl !== false) {
                $flow['refreshUrl'] = $this->settings->refreshUrl ?? (string) $this->settings->tokenUrl;
            }
            $flow['scopes'] = $scopes === [] ? new stdClass : $scopes;
            $schemes['oauth2'] = ['type' => 'oauth2'];
            if ($this->settings->oauthDescription !== null) {
                $schemes['oauth2']['description'] = $this->settings->oauthDescription;
            }
            $schemes['oauth2']['flows'] = ['authorizationCode' => $flow];
        }

        return $schemes;
    }

    /**
     * An inline schema as declared; a document schema as a reference to its
     * component, which the document then carries with everything it reaches,
     * so nested references stay resolvable.
     *
     * @param  array<string, mixed>|SchemaRef|null  $schema
     * @return array<string, mixed>|null
     */
    private function schema(array|SchemaRef|null $schema): ?array
    {
        if (! $schema instanceof SchemaRef) {
            return $schema === null ? null : self::objectMaps($schema);
        }
        $catalog = $this->catalog ?? throw new LogicException('An OpenAPI-referenced schema needs a SchemaCatalog.');
        $component = match (true) {
            $schema->component !== null => $schema->component,
            $schema->requestOf !== null => $catalog->requestComponent($schema->requestOf),
            default => $catalog->operationComponent((string) $schema->responseOf),
        };
        $this->components[$component] = true;

        return ['$ref' => '#/components/schemas/'.$component];
    }

    /**
     * A binding's own schema: an inline one must reference only what the
     * document carries, and carries what it references.
     *
     * @param  array<string, mixed>|SchemaRef  $schema
     * @return array<string, mixed>
     */
    private function declaredSchema(array|SchemaRef $schema, string $owner): array
    {
        if (is_array($schema)) {
            $this->referenceComponentsIn($schema, $owner);
        }

        return (array) $this->schema($schema);
    }

    /** @return array<string, mixed> */
    private function documentComponents(): array
    {
        $components = ['securitySchemes' => $this->securitySchemes() ?: new stdClass];
        foreach (['parameters' => $this->settings->parameters, 'responses' => $this->settings->responses] as $section => $declared) {
            if ($declared !== []) {
                foreach ($declared as $name => $fragment) {
                    $section === 'parameters'
                        ? self::assertParameterObject($fragment, "OpenApiSettings::\$parameters[{$name}]")
                        : self::assertResponseObject($fragment, "OpenApiSettings::\$responses[{$name}]");
                }
                $this->referenceComponentsIn($declared, "OpenApiSettings::\${$section}");
                $components[$section] = array_map(self::fragmentMaps(...), $declared);
            }
        }
        $schemas = [];
        foreach (array_keys($this->components) as $component) {
            $schemas += $this->catalog?->componentClosure($component) ?? [];
        }
        if ($schemas !== []) {
            ksort($schemas);
            $components['schemas'] = self::objectMaps($schemas);
        }

        return $components;
    }

    /**
     * A declared parameter, response or header as written, with only its
     * schemas normalized: an example's own `"properties": []` stays an array.
     *
     * @param  array<array-key, mixed>  $fragment
     * @return array<array-key, mixed>
     */
    private static function fragmentMaps(array $fragment): array
    {
        if (is_array($fragment['schema'] ?? null)) {
            $fragment['schema'] = self::objectMaps($fragment['schema']);
        }
        foreach (['content', 'headers'] as $map) {
            if (is_array($fragment[$map] ?? null)) {
                $fragment[$map] = array_map(static fn (mixed $entry): mixed => is_array($entry) ? self::fragmentMaps($entry) : $entry, $fragment[$map]);
            }
        }

        return $fragment;
    }

    /**
     * A decoded `"properties": {}` is an empty PHP array and would encode as
     * `[]`, which JSON Schema rejects; restore schema maps as objects at every
     * depth.
     *
     * @param  array<array-key, mixed>  $schema
     * @return array<array-key, mixed>
     */
    private static function objectMaps(array $schema): array
    {
        foreach ($schema as $key => $value) {
            if (in_array($key, ['properties', 'patternProperties', '$defs', 'dependentSchemas'], true) && $value === []) {
                $schema[$key] = new stdClass;
            } elseif (is_array($value)) {
                $schema[$key] = self::objectMaps($value);
            }
        }

        return $schema;
    }

    /**
     * The path's placeholders, which a declared list must match exactly: a
     * missing one would leave a path without its required parameter, a
     * stray one would describe a parameter that does not exist.
     *
     * @return list<string>
     */
    private static function pathParameters(Operation $operation): array
    {
        $rest = $operation->rest ?? throw new LogicException("Operation [{$operation->id}] has no REST binding.");
        if (preg_match('/\{[^}]*\?\}/', $rest->path) === 1) {
            // OpenAPI path parameters are always required; an optional segment
            // cannot be described, so it is refused rather than misstated.
            throw new LogicException("Operation [{$operation->id}] binds the optional placeholder in {$rest->path}, which OpenAPI cannot describe; bind each form as its own operation.");
        }
        preg_match_all('/\{([^}]+)\}/', $rest->path, $matches);
        $placeholders = $matches[1];
        if ($rest->pathParameters !== [] && $rest->pathParameters !== $placeholders) {
            throw new LogicException("Operation [{$operation->id}] declares path parameters [".implode(', ', $rest->pathParameters)."] but its path {$rest->path} has [".implode(', ', $placeholders).'].');
        }

        return $placeholders;
    }

    /**
     * A GET's input object as query parameters; path parameters are not repeated.
     *
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $pathParameters
     * @return list<array<string, mixed>>
     */
    private static function queryParameters(string $operationId, array $schema, array $pathParameters): array
    {
        // Query parameters carry each property and whether it is required,
        // nothing more: a combinator, a dependency or a conditional would be
        // dropped and the document would accept what the operation refuses.
        $unsupported = array_diff(array_keys($schema), ['type', 'properties', 'required', 'additionalProperties', 'description', 'title', '$schema', '$comment']);
        if ($unsupported !== [] || str_contains((string) json_encode($schema['properties'] ?? []), '"$ref"')) {
            throw new LogicException("Operation [{$operationId}] takes query parameters, but its input uses ".($unsupported === [] ? 'references' : implode(', ', $unsupported)).', which query parameters cannot express.');
        }
        $required = array_flip(array_filter(is_array($schema['required'] ?? null) ? $schema['required'] : [], 'is_string'));
        $parameters = [];
        foreach (is_array($schema['properties'] ?? null) ? $schema['properties'] : [] as $name => $property) {
            if (! is_string($name) || ! is_array($property) || in_array($name, $pathParameters, true)) {
                continue;
            }
            $parameter = ['name' => $name, 'in' => 'query', 'required' => isset($required[$name])];
            if (is_string($property['description'] ?? null)) {
                $parameter['description'] = $property['description'];
                unset($property['description']);
            }
            $parameter['schema'] = $property;
            $parameters[] = $parameter;
        }

        return $parameters;
    }

    /** @return list<string> */
    private static function diff(mixed $expected, mixed $actual, string $pointer): array
    {
        $bothObjects = $expected instanceof stdClass && $actual instanceof stdClass;
        if ($bothObjects || (is_array($expected) && is_array($actual))) {
            $expected = (array) $expected;
            $actual = (array) $actual;
            $differences = [];
            foreach (array_unique([...array_keys($expected), ...array_keys($actual)]) as $key) {
                $child = $pointer.'/'.str_replace(['~', '/'], ['~0', '~1'], (string) $key);
                if (! array_key_exists($key, $actual)) {
                    $differences[] = "{$child}: missing from the generated document";
                } elseif (! array_key_exists($key, $expected)) {
                    $differences[] = "{$child}: not in the shipped document";
                } else {
                    array_push($differences, ...self::diff($expected[$key], $actual[$key], $child));
                }
            }

            return $differences;
        }

        if ($expected instanceof stdClass || $actual instanceof stdClass || is_array($expected) || is_array($actual)) {
            return [($pointer === '' ? '/' : $pointer).': differs (object versus array)'];
        }

        return $expected === $actual ? [] : [($pointer === '' ? '/' : $pointer).': differs'];
    }
}
