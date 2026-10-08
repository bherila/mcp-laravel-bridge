<?php

namespace Bherila\McpLaravelBridge\Capabilities;

/** Why an operation is not available to this caller - something an agent can relay. */
final readonly class Withheld
{
    public function __construct(
        public string $operationId,
        public WithheldReason $reason,
        /** The flag, scope(s), permission(s), group, policy code or dependency at fault. */
        public string $detail,
    ) {}

    /** @return array{operation: string, reason: string, detail: string} */
    public function toArray(): array
    {
        return ['operation' => $this->operationId, 'reason' => $this->reason->value, 'detail' => $this->detail];
    }
}
