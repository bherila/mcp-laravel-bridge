<?php

namespace Bherila\McpLaravelBridge\OpenApi;

use InvalidArgumentException;
use JsonException;

/** Packages local OpenAPI components as standalone MCP JSON Schemas. */
final class SchemaCatalog
{
    private const string REF_PREFIX = '#/components/schemas/';

    private const array HTTP_METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /** @var array<string, mixed>|null */
    private ?array $document = null;

    /** @var array<string, string>|null */
    private ?array $operationComponents = null;

    /** @var array<string, string>|null */
    private ?array $requestComponents = null;

    /** @var array<string, list<string>>|null */
    private ?array $operationScopes = null;

    /** @var array<string, array<string, mixed>> */
    private array $packaged = [];

    public function __construct(private readonly string $documentPath) {}

    /** @return array<string, mixed> */
    public function schema(string $component): array
    {
        return $this->packaged[$component] ??= $this->package($component);
    }

    /** @return list<string> */
    public function componentIds(): array
    {
        return array_keys($this->components());
    }

    /** @return array<string, mixed> */
    public function forOperation(string $operationId): array
    {
        return $this->schema($this->operationComponent($operationId));
    }

    public function operationComponent(string $operationId): string
    {
        $components = $this->operationComponents();
        if (! isset($components[$operationId])) {
            throw new InvalidArgumentException("No agent API response schema is declared for operation [{$operationId}].");
        }

        return $components[$operationId];
    }

    /** @return array<string, mixed> */
    public function requestForOperation(string $operationId): array
    {
        $components = $this->requestComponents();
        if (! isset($components[$operationId])) {
            throw new InvalidArgumentException("No agent API request schema is declared for operation [{$operationId}].");
        }

        return $this->schema($components[$operationId]);
    }

    /** @return list<string> */
    public function scopesForOperation(string $operationId): array
    {
        if ($this->operationScopes === null) {
            $this->operationScopes = [];
            foreach ($this->document()['paths'] ?? [] as $operations) {
                foreach (is_array($operations) ? $operations : [] as $operation) {
                    $id = is_array($operation) ? ($operation['operationId'] ?? null) : null;
                    $scopes = is_array($operation) ? ($operation['security'][0]['oauth2'] ?? null) : null;
                    if (is_string($id) && is_array($scopes) && array_is_list($scopes)) {
                        $this->operationScopes[$id] = array_values(array_filter($scopes, 'is_string'));
                    }
                }
            }
        }
        if (! array_key_exists($operationId, $this->operationScopes)) {
            throw new InvalidArgumentException("No OAuth scopes are declared for operation [{$operationId}].");
        }

        return $this->operationScopes[$operationId];
    }

    /**
     * Every operation in the document by operationId, as written: its HTTP
     * binding, its `security` (null when absent, `[]` when explicitly public)
     * and its prose. Lets a spec-first application tell a public operation from
     * an unknown one and register operations no tool fronts.
     *
     * @return array<string, array{method: string, path: string, security: list<array<string, list<string>>>|null, summary: string, description: string}>
     */
    public function operations(): array
    {
        $operations = [];
        foreach ($this->document()['paths'] ?? [] as $path => $item) {
            $item = $this->pathItem($item, (string) $path);
            foreach ($item as $method => $operation) {
                // Only HTTP methods: a path item's parameters, servers or an
                // object-valued x- extension is never an operation.
                if (! in_array($method, self::HTTP_METHODS, true) || ! is_array($operation) || ! is_string($operation['operationId'] ?? null)) {
                    continue;
                }
                $security = $operation['security'] ?? null;
                $operations[$operation['operationId']] = [
                    'method' => strtoupper((string) $method),
                    'path' => (string) $path,
                    'security' => is_array($security) ? array_values(array_filter($security, 'is_array')) : null,
                    'summary' => is_string($operation['summary'] ?? null) ? $operation['summary'] : '',
                    'description' => is_string($operation['description'] ?? null) ? $operation['description'] : '',
                ];
            }
        }

        return $operations;
    }

    /**
     * A path item, following a local `$ref` into components.pathItems (OpenAPI
     * 3.1). Any other reference is refused rather than read as no operations.
     *
     * @return array<string, mixed>
     */
    private function pathItem(mixed $item, string $path): array
    {
        $seen = [];
        while (is_array($item) && is_string($item['$ref'] ?? null)) {
            $ref = $item['$ref'];
            $prefix = '#/components/pathItems/';
            if (! str_starts_with($ref, $prefix) || isset($seen[$ref])) {
                throw new InvalidArgumentException("Path [{$path}] references [{$ref}], which is not a resolvable local path item.");
            }
            $seen[$ref] = true;
            $name = str_replace(['~1', '~0'], ['/', '~'], substr($ref, strlen($prefix)));
            $target = $this->document()['components']['pathItems'][$name] ?? null;
            if (! is_array($target)) {
                throw new InvalidArgumentException("Path [{$path}] references missing path item [{$ref}].");
            }
            // Sibling fields beside a Path Item $ref override the referenced ones.
            $item = [...$target, ...array_diff_key($item, ['$ref' => true])];
        }

        return is_array($item) ? $item : [];
    }

    public function flush(): void
    {
        $this->document = null;
        $this->operationComponents = null;
        $this->requestComponents = null;
        $this->operationScopes = null;
        $this->packaged = [];
    }

    /** @return array<string, string> */
    private function operationComponents(): array
    {
        if ($this->operationComponents !== null) {
            return $this->operationComponents;
        }
        $map = [];
        foreach ($this->document()['paths'] ?? [] as $operations) {
            foreach (is_array($operations) ? $operations : [] as $operation) {
                if (! is_array($operation) || ! is_string($operation['operationId'] ?? null)) {
                    continue;
                }
                $component = $this->successComponent($operation);
                if ($component !== null) {
                    $map[$operation['operationId']] = $component;
                }
            }
        }

        return $this->operationComponents = $map;
    }

    /** @return array<string, string> */
    private function requestComponents(): array
    {
        if ($this->requestComponents !== null) {
            return $this->requestComponents;
        }
        $map = [];
        foreach ($this->document()['paths'] ?? [] as $operations) {
            foreach (is_array($operations) ? $operations : [] as $operation) {
                $operationId = is_array($operation) ? ($operation['operationId'] ?? null) : null;
                $ref = is_array($operation) ? ($operation['requestBody']['content']['application/json']['schema']['$ref'] ?? null) : null;
                if (is_string($operationId) && is_string($ref) && str_starts_with($ref, self::REF_PREFIX)) {
                    $map[$operationId] = substr($ref, strlen(self::REF_PREFIX));
                }
            }
        }

        return $this->requestComponents = $map;
    }

    /** @param array<string, mixed> $operation */
    private function successComponent(array $operation): ?string
    {
        $responses = $operation['responses'] ?? [];
        foreach (['200', '201', '202'] as $status) {
            $ref = is_array($responses) ? ($responses[$status]['content']['application/json']['schema']['$ref'] ?? null) : null;
            if (is_string($ref) && str_starts_with($ref, self::REF_PREFIX)) {
                return substr($ref, strlen(self::REF_PREFIX));
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function package(string $component): array
    {
        $components = $this->components();
        if (! isset($components[$component])) {
            throw new InvalidArgumentException("Unknown agent API response schema [{$component}].");
        }
        $reachable = [];
        $this->collect($component, $components, $reachable);
        $schema = $this->rewriteSchema($components[$component]);
        $referenced = $reachable;
        unset($referenced[$component]);
        $defs = [];
        foreach ($referenced as $name => $_) {
            $defs[$name] = $this->rewriteSchema($components[$name]);
        }
        if ($this->referencesRoot($component, $referenced, $components)) {
            $defs[$component] = $this->rewriteSchema($components[$component]);
        }
        if ($defs !== []) {
            ksort($defs);
            $schema['$defs'] = $defs;
        }

        return $schema;
    }

    /** @param array<string, array<string, mixed>> $components @param array<string, true> $seen */
    private function collect(string $component, array $components, array &$seen): void
    {
        if (isset($seen[$component])) {
            return;
        }
        $seen[$component] = true;
        foreach ($this->refsIn($components[$component] ?? []) as $ref) {
            if (! isset($components[$ref])) {
                throw new InvalidArgumentException("Dangling response schema reference [{$ref}].");
            }
            $this->collect($ref, $components, $seen);
        }
    }

    /** @param array<string, true> $referenced @param array<string, array<string, mixed>> $components */
    private function referencesRoot(string $root, array $referenced, array $components): bool
    {
        foreach ([...array_keys($referenced), $root] as $name) {
            if (in_array($root, $this->refsIn($components[$name] ?? []), true)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed>|list<mixed> $node @return list<string> */
    private function refsIn(array $node): array
    {
        $refs = [];
        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value) && str_starts_with($value, self::REF_PREFIX)) {
                $refs[] = substr($value, strlen(self::REF_PREFIX));
            } elseif (is_array($value)) {
                $refs = [...$refs, ...$this->refsIn($value)];
            }
        }

        return array_values(array_unique($refs));
    }

    /** @param array<string, mixed> $node @return array<string, mixed> */
    private function rewriteSchema(array $node): array
    {
        $rewritten = [];
        foreach ($node as $key => $value) {
            if (in_array($key, ['$ref', '$dynamicRef', '$recursiveRef'], true) && is_string($value)) {
                if ($key !== '$ref' || ! str_starts_with($value, self::REF_PREFIX)) {
                    throw new InvalidArgumentException('Unsupported response schema reference.');
                }
                $rewritten[$key] = '#/$defs/'.substr($value, strlen(self::REF_PREFIX));
            } else {
                $rewritten[$key] = $this->rewriteValue($value);
            }
        }

        return $rewritten;
    }

    private function rewriteValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        return array_is_list($value) ? array_map($this->rewriteValue(...), $value) : $this->rewriteSchema($value);
    }

    /** @return array<string, array<string, mixed>> */
    private function components(): array
    {
        $schemas = $this->document()['components']['schemas'] ?? null;
        if (! is_array($schemas) || $schemas === []) {
            throw new InvalidArgumentException('The agent API OpenAPI document declares no response schemas.');
        }

        return $schemas;
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        if ($this->document !== null) {
            return $this->document;
        }
        $contents = file_get_contents($this->documentPath);
        if ($contents === false) {
            throw new InvalidArgumentException('The agent API OpenAPI document is missing or unreadable.');
        }
        try {
            $document = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('The agent API OpenAPI document is not valid JSON.');
        }
        if (! is_array($document)) {
            throw new InvalidArgumentException('The agent API OpenAPI document is not an object.');
        }

        return $this->document = $document;
    }
}
