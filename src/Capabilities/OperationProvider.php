<?php

namespace Bherila\McpLaravelBridge\Capabilities;

/** A module's operations. Third-party packages may provide their own. */
interface OperationProvider
{
    /** @return iterable<Operation> */
    public function operations(): iterable;
}
