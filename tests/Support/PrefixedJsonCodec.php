<?php

namespace Bherila\McpLaravelBridge\Tests\Support;

use Bherila\McpLaravelBridge\Http\PayloadCodec;
use InvalidArgumentException;

/** A stand-in for a real alternative encoding: JSON behind a marker line. */
final class PrefixedJsonCodec implements PayloadCodec
{
    public function __construct(private readonly string $type = 'application/x-test') {}

    public function mediaType(): string
    {
        return $this->type;
    }

    public function encode(mixed $data): string
    {
        return "TEST\n".json_encode($data, JSON_THROW_ON_ERROR);
    }

    public function decode(string $body): mixed
    {
        if (! str_starts_with($body, "TEST\n")) {
            throw new InvalidArgumentException('Not a test payload.');
        }

        return json_decode(substr($body, 5), true, 512, JSON_THROW_ON_ERROR);
    }
}
