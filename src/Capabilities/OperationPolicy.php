<?php

namespace Bherila\McpLaravelBridge\Capabilities;

/**
 * Coarse, application-specific eligibility (for example "manages at least one
 * workspace"). Object-level authorization stays in the domain actions.
 */
interface OperationPolicy
{
    /** Null allows; a reason code withholds. */
    public function withhold(Principal $principal, Operation $operation): ?string;
}
