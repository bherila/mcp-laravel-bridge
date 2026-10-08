<?php

namespace Bherila\McpLaravelBridge\Capabilities;

/**
 * How a write protects against retries, races and accidents. Applications keep
 * their own conventions; declaring them lets contract tests assert them.
 */
final readonly class WriteSafety
{
    public function __construct(
        public IdempotencyKey $idempotencyKey = IdempotencyKey::None,
        public bool $expectedVersion = false,
        public bool $confirm = false,
        public bool $dryRunDefault = false,
        /** Free-form note for conventions the fields above do not cover (natural keys, preview digests). */
        public ?string $note = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function declaresAnything(): bool
    {
        return $this->idempotencyKey !== IdempotencyKey::None
            || $this->expectedVersion
            || $this->confirm
            || $this->dryRunDefault
            || ($this->note !== null && $this->note !== '');
    }
}
