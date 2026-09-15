<?php

namespace Bherila\McpLaravelBridge\Http;

use Closure;
use InvalidArgumentException;

/** Security and resource limits for a public MCP-over-HTTP endpoint. */
final readonly class McpHttpPolicy
{
    /** @var list<string> */
    public array $allowedMethods;

    /** @var list<string> */
    public array $allowedHeaders;

    /** @var list<string> */
    public array $exposedHeaders;

    /**
     * Arrays are convenient in tests; closures let long-lived Laravel workers
     * resolve current configuration for every request.
     *
     * @param list<string>|Closure(): list<string> $allowedOrigins Exact HTTP(S) origins, including an explicit port when required
     * @param list<string>|Closure(): list<string> $allowedHosts Exact Host authorities (hostname[:port]), independent of browser origins
     * @param list<string> $allowedMethods
     * @param list<string> $allowedHeaders
     * @param list<string> $exposedHeaders
     */
    public function __construct(
        public array|Closure $allowedOrigins,
        public array|Closure $allowedHosts,
        public int $maxRequestBodyBytes = 262_144,
        public int $maxResponseBodyBytes = 1_048_576,
        array $allowedMethods = ['POST', 'DELETE'],
        array $allowedHeaders = [
            'Accept',
            'Authorization',
            'Content-Type',
            'Last-Event-ID',
            'Mcp-Method',
            'Mcp-Protocol-Version',
            'Mcp-Session-Id',
        ],
        array $exposedHeaders = [
            'Mcp-Method',
            'Mcp-Protocol-Version',
            'Mcp-Session-Id',
            'WWW-Authenticate',
        ],
    ) {
        if ($this->maxRequestBodyBytes < 1 || $this->maxResponseBodyBytes < 1) {
            throw new InvalidArgumentException('MCP request and response limits must be at least one byte.');
        }

        $this->allowedMethods = self::normalizedTokens($allowedMethods, 'method');
        $this->allowedHeaders = self::normalizedTokens($allowedHeaders, 'header', false);
        $this->exposedHeaders = self::normalizedTokens($exposedHeaders, 'header', false);

        // Fail during application boot for static configuration. Closure-backed
        // configuration is validated when it is resolved for a request.
        if (is_array($this->allowedOrigins)) {
            $this->origins();
        }
        if (is_array($this->allowedHosts)) {
            $this->hosts();
        }
    }

    /** @return list<string> */
    public function origins(): array
    {
        $values = $this->allowedOrigins instanceof Closure
            ? ($this->allowedOrigins)()
            : $this->allowedOrigins;

        return self::normalizedList($values, self::normalizeOrigin(...), 'origin');
    }

    /** @return list<string> */
    public function hosts(): array
    {
        $values = $this->allowedHosts instanceof Closure
            ? ($this->allowedHosts)()
            : $this->allowedHosts;

        return self::normalizedList($values, self::normalizeHost(...), 'host');
    }

    public static function normalizeOrigin(string $origin): string
    {
        if ($origin === '' || trim($origin) !== $origin || $origin === '*') {
            throw new InvalidArgumentException('MCP origins must be explicit HTTP(S) origins.');
        }

        $parts = parse_url($origin);
        if (! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '')) {
            throw new InvalidArgumentException("Invalid MCP origin [{$origin}]. Use scheme://host[:port] only.");
        }

        $scheme = strtolower($parts['scheme']);
        $host = self::formatHostname((string) $parts['host']);
        $port = isset($parts['port']) ? self::validatedPort((int) $parts['port']) : null;
        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            $port = null;
        }

        return $scheme.'://'.$host.($port === null ? '' : ':'.$port);
    }

    /** Derive the exact Host authority expected for an application/resource URL. */
    public static function hostFromUrl(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException("Invalid MCP application/resource URL [{$url}].");
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = self::formatHostname((string) $parts['host']);
        $port = isset($parts['port']) ? self::validatedPort((int) $parts['port']) : null;
        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            $port = null;
        }

        return $host.($port === null ? '' : ':'.$port);
    }

    public static function normalizeHost(string $authority): string
    {
        if ($authority === '' || trim($authority) !== $authority
            || str_contains($authority, '/') || str_contains($authority, '@')
            || str_contains($authority, '?') || str_contains($authority, '#')) {
            throw new InvalidArgumentException("Invalid MCP service Host [{$authority}]. Use host[:port] only.");
        }

        if (str_starts_with($authority, '[')) {
            if (preg_match('/^\[([^\]]+)](?::([0-9]{1,5}))?$/D', $authority, $matches) !== 1
                || filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new InvalidArgumentException("Invalid MCP service Host [{$authority}].");
            }
            $host = '['.strtolower($matches[1]).']';
            $port = isset($matches[2]) ? self::validatedPort((int) $matches[2]) : null;

            return $host.($port === null ? '' : ':'.$port);
        }

        if (preg_match('/^([^:\s]+)(?::([0-9]{1,5}))?$/D', $authority, $matches) !== 1) {
            throw new InvalidArgumentException("Invalid MCP service Host [{$authority}].");
        }
        $host = strtolower(rtrim($matches[1], '.'));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new InvalidArgumentException("Invalid MCP service Host [{$authority}].");
        }
        $port = isset($matches[2]) ? self::validatedPort((int) $matches[2]) : null;

        return $host.($port === null ? '' : ':'.$port);
    }

    private static function formatHostname(string $host): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return '['.strtolower($host).']';
        }

        return self::normalizeHost($host);
    }

    private static function validatedPort(int $port): int
    {
        if ($port < 1 || $port > 65_535) {
            throw new InvalidArgumentException('MCP origin and Host ports must be between 1 and 65535.');
        }

        return $port;
    }

    /** @param mixed $values @param callable(string): string $normalize @return list<string> */
    private static function normalizedList(mixed $values, callable $normalize, string $kind): array
    {
        if (! is_array($values) || ! array_is_list($values)) {
            throw new InvalidArgumentException("MCP allowed {$kind}s must be a list of strings.");
        }

        $normalized = [];
        foreach ($values as $value) {
            if (! is_string($value)) {
                throw new InvalidArgumentException("MCP allowed {$kind}s must be a list of strings.");
            }
            $normalized[] = $normalize($value);
        }

        return array_values(array_unique($normalized));
    }

    /** @param list<string> $values @return list<string> */
    private static function normalizedTokens(array $values, string $kind, bool $uppercase = true): array
    {
        $normalized = [];
        foreach ($values as $value) {
            if (! is_string($value) || preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D', $value) !== 1) {
                throw new InvalidArgumentException("Invalid MCP CORS {$kind} token.");
            }
            $normalized[] = $uppercase ? strtoupper($value) : $value;
        }

        return array_values(array_unique($normalized));
    }
}
