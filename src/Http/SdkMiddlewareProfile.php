<?php

namespace Bherila\McpLaravelBridge\Http;

use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
use Psr\Http\Server\MiddlewareInterface;

/** Selects only lifecycle middleware not already enforced at the Laravel edge. */
final class SdkMiddlewareProfile
{
    /** @return list<MiddlewareInterface> */
    public static function forHardenedLaravelEdge(): array
    {
        // SDK 0.8+ classifies protocol eras before applying its own handshake
        // version middleware. An edge ProtocolVersionMiddleware would reject
        // valid modern requests. SDK 0.7 requires it in the custom stack.
        if (defined(ProtocolVersion::class.'::V2026_07_28')) {
            return [new PrevalidatedRequestMiddleware];
        }

        return [new ProtocolVersionMiddleware];
    }
}
