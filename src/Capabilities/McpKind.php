<?php

namespace Bherila\McpLaravelBridge\Capabilities;

enum McpKind: string
{
    case Tool = 'tool';
    case Resource = 'resource';
    case ResourceTemplate = 'resource_template';
    case Prompt = 'prompt';
}
