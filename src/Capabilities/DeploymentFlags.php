<?php

namespace Bherila\McpLaravelBridge\Capabilities;

/** Installation-level switches that withdraw operations for everyone (cutovers, kill switches). */
interface DeploymentFlags
{
    public function enabled(string $flag): bool;
}
