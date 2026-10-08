<?php

namespace Bherila\McpLaravelBridge\Capabilities;

use InvalidArgumentException;

/**
 * What a caller needs to use an operation. Scopes (what the credential was
 * granted) and permissions (what the person may do) are independent axes; a
 * group (module) and deployment flags narrow further.
 */
final readonly class Requirement
{
    /**
     * @param  list<string>  $scopes
     * @param  list<string>  $permissions  all required
     * @param  list<string>  $anyPermissions  at least one required
     * @param  list<string>  $flags  every deployment flag must be on
     */
    public function __construct(
        public array $scopes = [],
        public ScopeRule $scopeRule = ScopeRule::All,
        public array $permissions = [],
        public array $anyPermissions = [],
        public ?string $group = null,
        public array $flags = [],
        public bool $public = false,
    ) {
        if ($public && ($scopes !== [] || $permissions !== [] || $anyPermissions !== [])) {
            throw new InvalidArgumentException('A public requirement cannot also require scopes or permissions.');
        }
    }

    public static function publicAccess(): self
    {
        return new self(public: true);
    }

    public function isEmpty(): bool
    {
        return $this->scopes === [] && $this->permissions === [] && $this->anyPermissions === [];
    }
}
