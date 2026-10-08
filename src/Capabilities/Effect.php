<?php

namespace Bherila\McpLaravelBridge\Capabilities;

/**
 * What an operation does to the world. One vocabulary for every application,
 * mapped once to MCP annotations and to HTTP conventions.
 */
enum Effect: string
{
    case Read = 'read';
    /** Changes this application's own records. */
    case LocalWrite = 'local_write';
    /** Reaches outside the application: sends email, charges, calls a third party. */
    case ExternalWrite = 'external_write';
    /** Removes or voids something. */
    case Destructive = 'destructive';
    case Upload = 'upload';
    case Download = 'download';

    public function readOnly(): bool
    {
        return match ($this) {
            self::Read, self::Download => true,
            self::LocalWrite, self::ExternalWrite, self::Destructive, self::Upload => false,
        };
    }

    public function destructive(): bool
    {
        return match ($this) {
            self::Destructive, self::ExternalWrite => true,
            self::Read, self::Download, self::LocalWrite, self::Upload => false,
        };
    }

    /** Whether the operation can affect anything outside the application. */
    public function openWorld(): bool
    {
        return match ($this) {
            self::ExternalWrite => true,
            self::Read, self::Download, self::LocalWrite, self::Destructive, self::Upload => false,
        };
    }
}
