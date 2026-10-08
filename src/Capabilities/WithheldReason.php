<?php

namespace Bherila\McpLaravelBridge\Capabilities;

enum WithheldReason: string
{
    case DeploymentFlag = 'deployment_flag';
    case MissingScope = 'missing_scope';
    case MissingPermission = 'missing_permission';
    case GroupNotGranted = 'group_not_granted';
    case Policy = 'policy';
    case DependsOn = 'depends_on';
}
