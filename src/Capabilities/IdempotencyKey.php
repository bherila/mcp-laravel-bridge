<?php

namespace Bherila\McpLaravelBridge\Capabilities;

/** Where a write takes its retry key. */
enum IdempotencyKey: string
{
    case None = 'none';
    case Header = 'header';
    case Argument = 'argument';
    case HeaderAndArgument = 'header_and_argument';
}
