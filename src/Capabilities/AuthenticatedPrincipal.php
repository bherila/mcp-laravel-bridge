<?php

namespace Bherila\McpLaravelBridge\Capabilities;

/**
 * A principal that can say whether a credential authenticated it at all, for
 * operations that need any credential but no particular scope. A principal
 * that does not implement this is treated as unauthenticated for them.
 */
interface AuthenticatedPrincipal extends Principal
{
    public function isAuthenticated(): bool;
}
