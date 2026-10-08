<?php

namespace Bherila\McpLaravelBridge\Capabilities;

/**
 * One agent operation, declared once. REST routing, the OpenAPI document, MCP
 * tools and the availability view are all derived from it.
 */
final readonly class Operation
{
    /**
     * @param  array<string, mixed>|SchemaRef|null  $input
     * @param  array<string, mixed>|SchemaRef|null  $output
     * @param  list<string>  $requiresOperations  ids that must also be available (e.g. a prompt's tools)
     * @param  list<string>  $tags
     * @param  array<string, mixed>  $extensions  emitted as vendor extensions
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $description,
        public Effect $effect,
        public Requirement $requirement,
        /** Null derives it: reads are idempotent; a write is only if it declares an idempotency key. */
        public ?bool $idempotent = null,
        public WriteSafety $safety = new WriteSafety,
        public ?RestBinding $rest = null,
        public ?McpBinding $mcp = null,
        public array|SchemaRef|null $input = null,
        public array|SchemaRef|null $output = null,
        public array $requiresOperations = [],
        public array $tags = [],
        public array $extensions = [],
        public ?string $deprecation = null,
    ) {}

    /**
     * A copy with some fields replaced, e.g. `->with(rest: new RestBinding(...))`.
     * Every other field carries over, including any added later.
     */
    public function with(mixed ...$changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }

    public function mcpName(): ?string
    {
        if ($this->mcp === null) {
            return null;
        }

        return $this->mcp->name ?? $this->id;
    }

    /**
     * Whether repeating the call is safe. Set explicitly for natural-key
     * idempotency; otherwise a write without an idempotency key is not, so
     * clients are never told to retry an email, charge or creation.
     */
    public function isIdempotent(): bool
    {
        return $this->idempotent
            ?? ($this->effect->readOnly() || $this->safety->idempotencyKey !== IdempotencyKey::None);
    }

    public function group(): ?string
    {
        return $this->requirement->group;
    }
}
