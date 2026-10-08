<?php

namespace Bherila\McpLaravelBridge\Capabilities;

use Bherila\McpLaravelBridge\Mcp\ReflectedInputSchemaFactory;
use Bherila\McpLaravelBridge\Mcp\ToolDefinition;
use Bherila\McpLaravelBridge\Mcp\ToolWithSecuritySchemes;
use Bherila\McpLaravelBridge\OpenApi\SchemaCatalog;
use LogicException;
use Mcp\Schema\ToolAnnotations;

/**
 * MCP tools from declared operations: names, schemas, annotations and
 * security schemes are derived from the declaration rather than written again
 * in each application's server factory.
 */
final class OperationToolFactory
{
    /**
     * @param  list<string>  $connectionScopes  scopes the MCP endpoint itself requires (e.g. `mcp:use`),
     *                                          added to every generated scheme so a client that follows the
     *                                          metadata holds everything the endpoint checks
     */
    public function __construct(
        private readonly ?SchemaCatalog $catalog = null,
        private readonly ReflectedInputSchemaFactory $reflected = new ReflectedInputSchemaFactory,
        private readonly array $connectionScopes = [],
    ) {}

    /** The existing ToolDefinition shape, for applications that build their server from it. */
    public function definition(Operation $operation): ToolDefinition
    {
        $mcp = $this->binding($operation);

        return new ToolDefinition(
            name: (string) $operation->mcpName(),
            title: $operation->title,
            description: $operation->description,
            handler: $mcp->handler ?? throw new LogicException("Operation [{$operation->id}] has no MCP handler."),
            operationId: $operation->id,
            readOnly: $operation->effect->readOnly(),
            destructive: $operation->effect->destructive(),
            idempotent: $operation->isIdempotent(),
        );
    }

    public function tool(Operation $operation): ToolWithSecuritySchemes
    {
        $this->binding($operation);

        return new ToolWithSecuritySchemes(
            securitySchemes: self::securitySchemes($operation, $this->connectionScopes),
            name: (string) $operation->mcpName(),
            title: $operation->title,
            inputSchema: $this->inputSchema($operation),
            description: $operation->description,
            annotations: new ToolAnnotations(
                title: $operation->title,
                readOnlyHint: $operation->effect->readOnly(),
                destructiveHint: $operation->effect->readOnly() ? null : $operation->effect->destructive(),
                idempotentHint: $operation->effect->readOnly() ? null : $operation->isIdempotent(),
                openWorldHint: $operation->effect->openWorld(),
            ),
            outputSchema: $this->outputSchema($operation),
        );
    }

    /**
     * The handler's reflected signature, with the declared body merged over it:
     * declared properties win, required lists and $defs are unioned, and the
     * result is closed.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(Operation $operation): array
    {
        $mcp = $this->binding($operation);
        $declared = $this->resolve($operation->input);
        $schema = $mcp->handler !== null ? $this->reflected->for($mcp->handler) : ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false];
        if ($declared === null) {
            return $schema;
        }

        // A handler with no parameters reflects `properties` as an empty object.
        $schema['properties'] = [...(array) ($schema['properties'] ?? []), ...(array) ($declared['properties'] ?? [])];
        $required = array_values(array_unique([...($schema['required'] ?? []), ...($declared['required'] ?? [])]));
        if ($required !== []) {
            $schema['required'] = $required;
        }
        if (isset($declared['$defs'])) {
            $schema['$defs'] = [...($schema['$defs'] ?? []), ...$declared['$defs']];
        }
        // Every other declared keyword (combinators, not, if/then, propertyNames,
        // minProperties, ...) carries over unchanged: dropping one would let
        // MCP accept input the declared contract rejects.
        foreach ($declared as $keyword => $value) {
            if (! in_array($keyword, ['properties', 'required', '$defs', 'type', 'additionalProperties'], true)) {
                $schema[$keyword] = $value;
            }
        }
        $schema['type'] = 'object';
        $schema['additionalProperties'] = false;
        if ($schema['properties'] === []) {
            // `properties` must serialize as a JSON object, never [].
            $schema['properties'] = new \stdClass;
        }

        return $schema;
    }

    /** @return array<string, mixed>|null */
    public function outputSchema(Operation $operation): ?array
    {
        $schema = $this->resolve($operation->output);

        return $schema === null ? null : self::objectProperties($schema);
    }

    /**
     * A decoded document turns `"properties": {}` into an empty PHP array,
     * which would serialize back as `[]`; restore it as an object at every depth.
     *
     * @param  array<array-key, mixed>  $schema
     * @return array<array-key, mixed>
     */
    private static function objectProperties(array $schema): array
    {
        foreach ($schema as $key => $value) {
            if ($key === 'properties' && $value === []) {
                $schema[$key] = new \stdClass;
            } elseif (is_array($value)) {
                $schema[$key] = self::objectProperties($value);
            }
        }

        return $schema;
    }

    /**
     * OAuth security schemes for the MCP tool. An All rule is one scheme with
     * every scope; an Any rule offers one scheme per scope. The endpoint's own
     * connection scopes are part of every alternative.
     *
     * @param  list<string>  $connectionScopes
     * @return list<array{type: string, scopes?: list<string>}>
     */
    public static function securitySchemes(Operation $operation, array $connectionScopes = []): array
    {
        $requirement = $operation->requirement;
        $with = static fn (array $scopes): array => ['type' => 'oauth2', 'scopes' => array_values(array_unique([...$connectionScopes, ...$scopes]))];
        if ($requirement->public) {
            return $connectionScopes === [] ? [['type' => 'noauth']] : [$with([])];
        }
        // Duplicate alternatives would be rejected when the tool is built.
        $scopes = array_values(array_unique($requirement->scopes));
        if ($requirement->scopeRule === ScopeRule::Any && count($scopes) > 1) {
            return array_map(static fn (string $scope): array => $with([$scope]), $scopes);
        }

        return [$with($scopes)];
    }

    /**
     * @param  array<string, mixed>|SchemaRef|null  $schema
     * @return array<string, mixed>|null
     */
    private function resolve(array|SchemaRef|null $schema): ?array
    {
        if ($schema instanceof SchemaRef) {
            return $schema->resolve($this->catalog ?? throw new LogicException('An OpenAPI-referenced schema needs a SchemaCatalog.'));
        }

        return $schema;
    }

    private function binding(Operation $operation): McpBinding
    {
        $mcp = $operation->mcp ?? throw new LogicException("Operation [{$operation->id}] is not exposed over MCP.");
        if ($mcp->kind !== McpKind::Tool) {
            // A resource, template or prompt emitted as a tool would expose the
            // wrong protocol surface and silently drop its kind and URI.
            throw new LogicException("Operation [{$operation->id}] is an MCP {$mcp->kind->value}, not a tool.");
        }

        return $mcp;
    }
}
