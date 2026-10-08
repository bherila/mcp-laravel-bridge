<?php

namespace Bherila\McpLaravelBridge\Capabilities;

use Illuminate\Http\Request;

/** Turns an incoming request into the principal the registry evaluates. Bind it in the container. */
interface PrincipalResolver
{
    public function principal(Request $request): Principal;
}
