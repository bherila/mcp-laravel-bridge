<?php

namespace Bherila\McpLaravelBridge\Capabilities;

final readonly class AvailabilityReport
{
    /**
     * @param  list<Operation>  $available
     * @param  list<Withheld>  $withheld
     */
    public function __construct(
        public array $available,
        public array $withheld,
    ) {}

    public function isAvailable(string $operationId): bool
    {
        foreach ($this->available as $operation) {
            if ($operation->id === $operationId) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function availableIds(): array
    {
        return array_map(static fn (Operation $operation): string => $operation->id, $this->available);
    }

    /** @return list<array{operation: string, reason: string, detail: string}> */
    public function withheldArray(): array
    {
        return array_map(static fn (Withheld $withheld): array => $withheld->toArray(), $this->withheld);
    }
}
