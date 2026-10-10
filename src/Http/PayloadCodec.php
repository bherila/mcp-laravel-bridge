<?php

namespace Bherila\McpLaravelBridge\Http;

/**
 * An alternative encoding of an operation's JSON payloads, such as a compact
 * text format for language-model clients. It works on the data JSON decodes
 * to, so an operation's controllers, validation and schemas stay JSON-shaped.
 *
 * The bridge ships no codec; an application or an optional package provides
 * them and binds a {@see PayloadCodecs} collection.
 */
interface PayloadCodec
{
    /** The media type it reads and writes, e.g. `application/toon`, without parameters. */
    public function mediaType(): string;

    /** Encodes data as decoded from JSON (associative arrays). */
    public function encode(mixed $data): string;

    /**
     * Decodes a request body into the data its JSON equivalent would decode
     * to (associative arrays).
     *
     * @throws \InvalidArgumentException when the body is not valid in this encoding
     */
    public function decode(string $body): mixed;
}
