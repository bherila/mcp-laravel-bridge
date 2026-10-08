<?php

namespace Bherila\McpLaravelBridge\Capabilities;

/** The caller, as the application sees it: credential scopes, live permissions, granted groups. */
interface Principal
{
    public function hasScope(string $scope): bool;

    public function can(string $permission): bool;

    /** Whether the credential may use this group (module). Null groups are always allowed. */
    public function allowsGroup(?string $group): bool;
}
