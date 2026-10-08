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
        /** Any authenticated credential, with no particular scope (e.g. self-revocation). */
        public bool $authenticated = false,
    ) {
        // A group is granted by a credential, so it would need one: a public
        // operation advertised as `noauth` must not be withheld from every
        // caller who follows that advertisement.
        if ($public && ($scopes !== [] || $permissions !== [] || $anyPermissions !== [] || $group !== null)) {
            throw new InvalidArgumentException('A public requirement cannot also require scopes, permissions or a group.');
        }
        if ($public && $authenticated) {
            throw new InvalidArgumentException('A requirement cannot be both public and authenticated.');
        }
    }

    public static function publicAccess(): self
    {
        return new self(public: true);
    }

    /** Any authenticated credential, with no scope or permission beyond that. */
    public static function authenticated(): self
    {
        return new self(authenticated: true);
    }

    public function isEmpty(): bool
    {
        return ! $this->authenticated && $this->scopes === [] && $this->permissions === [] && $this->anyPermissions === [];
    }
}
