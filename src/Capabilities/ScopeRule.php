<?php

namespace Bherila\McpLaravelBridge\Capabilities;

enum ScopeRule: string
{
    /** Every listed scope is required. */
    case All = 'all';
    /** Any one listed scope suffices. */
    case Any = 'any';
}
