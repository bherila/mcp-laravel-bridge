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
        foreach ($operations as $operation) {
            $method = strtolower((string) $operation->rest?->method);
            $path = (string) $operation->rest?->path;
            if (isset($paths[$path][$method])) {
                throw new LogicException("Operations [{$paths[$path][$method]['operationId']}] and [{$operation->id}] are both bound to {$method} {$path}.");
            }
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

        $document = [
            'operationId' => $operation->id,
            'summary' => $operation->title,
            'description' => $operation->description,
        ];
        if ($operation->tags !== []) {
            $document['tags'] = $operation->tags;
        }
        if ($operation->deprecation !== null) {
            $document['deprecated'] = true;
            $document['description'] .= "\n\nDeprecated: {$operation->deprecation}";
        }
        $document['security'] = $this->security($operation);

        $pathParameters = self::pathParameters($operation);
        $input = $this->schema($operation->input);
        $declared = is_array($input['properties'] ?? null) ? $input['properties'] : [];
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
        if ($input !== null && in_array($method, ['GET', 'HEAD', 'DELETE'], true)) {
            array_push($parameters, ...self::queryParameters($input, $pathParameters));
        } elseif ($input !== null) {
            $document['requestBody'] = [
                'required' => true,
                'content' => array_fill_keys(
                    array_values(array_unique([...$rest->requestContentTypes, ...$this->settings->extraMediaTypes])),
                    ['schema' => $input],
                ),
            ];
        }
        if ($parameters !== []) {
            $document['parameters'] = $parameters;
        }

        $output = $this->schema($operation->output);
        $responses = [];
        foreach ($rest->successStatuses as $status) {
            $response = ['description' => $status === 201 ? 'Created' : 'Success'];
            if ($output !== null && $status !== 204) {
                $response['content'] = array_fill_keys(
                    array_values(array_unique(['application/json', ...$this->settings->extraMediaTypes])),
                    ['schema' => $output],
                );
            }
            $responses[(string) $status] = $response;
        }
        $document['responses'] = $responses;

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
        foreach ($operation->extensions as $key => $value) {
            $document[str_starts_with((string) $key, 'x-') ? (string) $key : "{$x}-{$key}"] = $value;
        }

        return $document;
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
        try {
            $shipped = json_decode((string) file_get_contents($shippedPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['/: the shipped document is not valid JSON'];
        }
        $generated = json_decode((string) json_encode($generated, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        return self::diff($shipped, $generated, '');
    }

    /** @param  array<string, mixed>  $document */
    public static function write(array $document, string $path): void
    {
        file_put_contents($path, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
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
        $needsConnection = array_intersect($scopes, $this->settings->connectionScopes) !== [];
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
            $schemes['apiToken'] = ['type' => 'http', 'scheme' => 'bearer', 'description' => $this->settings->apiTokenDescription];
        }
        if ($this->settings->oauth()) {
            $schemes['oauth2'] = [
                'type' => 'oauth2',
                'flows' => ['authorizationCode' => [
                    'authorizationUrl' => (string) $this->settings->authorizationUrl,
                    'tokenUrl' => (string) $this->settings->tokenUrl,
                    'refreshUrl' => (string) $this->settings->tokenUrl,
                    'scopes' => $this->settings->scopes === [] ? new stdClass : $this->settings->scopes,
                ]],
            ];
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
            return $schema;
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

    /** @return array<string, mixed> */
    private function documentComponents(): array
    {
        $components = ['securitySchemes' => $this->securitySchemes() ?: new stdClass];
        $schemas = [];
        foreach (array_keys($this->components) as $component) {
            $schemas += $this->catalog?->componentClosure($component) ?? [];
        }
        if ($schemas !== []) {
            ksort($schemas);
            $components['schemas'] = $schemas;
        }

        return $components;
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
        preg_match_all('/\{([^}?]+)\??\}/', $rest->path, $matches);
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
    private static function queryParameters(array $schema, array $pathParameters): array
    {
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
        if (is_array($expected) && is_array($actual) && array_is_list($expected) === array_is_list($actual)) {
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

        return $expected === $actual ? [] : [($pointer === '' ? '/' : $pointer).': differs'];
    }
}
