<?php

namespace Bherila\McpLaravelBridge\Mcp;

use Illuminate\Http\Request;

final class CredentialSessionNamespace
{
    public static function prefix(Request $request, string $prefix, string $guard = 'api'): string
    {
        return $prefix.hash('sha256', self::identity($request, $guard)).'_';
    }

    private static function identity(Request $request, string $guard): string
    {
        $bearer = $request->bearerToken();
        if (is_string($bearer) && $bearer !== '') {
            return $bearer;
        }

        $token = $request->user($guard)?->token();
        $attributes = is_object($token) && method_exists($token, 'toArray') ? $token->toArray() : [];
        $tokenId = is_array($attributes) ? ($attributes['oauth_access_token_id'] ?? null) : null;
        if (is_string($tokenId) && $tokenId !== '') {
            return $tokenId;
        }
        if (is_object($token)) {
            return 'transient-'.spl_object_id($token);
        }

        return 'preflight';
    }
}
