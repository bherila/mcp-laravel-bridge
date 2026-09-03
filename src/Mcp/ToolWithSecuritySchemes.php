<?php

namespace Bherila\McpLaravelBridge\Mcp;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Icon;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;

/**
 * MCP tool descriptor carrying current-client auth metadata.
 *
 * The official PHP SDK does not yet model the top-level `securitySchemes`
 * extension. This subclass keeps the SDK's normal Tool and registry behavior
 * while emitting that field and its `_meta.securitySchemes` compatibility
 * mirror from one validated value.
 *
 * @phpstan-type NoAuthSecurityScheme array{type: 'noauth'}
 * @phpstan-type OAuthSecurityScheme array{type: 'oauth2', scopes: list<string>}
 * @phpstan-type SecurityScheme NoAuthSecurityScheme|OAuthSecurityScheme
 */
final class ToolWithSecuritySchemes extends Tool
{
    public const MAX_SCHEMES = 8;

    public const MAX_SCOPES = 64;

    public const MAX_SCOPE_LENGTH = 255;

    public const MAX_SERIALIZED_BYTES = 8192;

    /** @var list<SecurityScheme> */
    public readonly array $securitySchemes;

    /**
     * @param list<SecurityScheme>     $securitySchemes
     * @param array<string, mixed>     $inputSchema
     * @param list<Icon>|null          $icons
     * @param array<string, mixed>|null $meta
     * @param array<string, mixed>|null $outputSchema
     */
    public function __construct(
        array $securitySchemes,
        string $name,
        ?string $title,
        array $inputSchema,
        ?string $description,
        ?ToolAnnotations $annotations,
        ?array $icons = null,
        ?array $meta = null,
        ?array $outputSchema = null,
    ) {
        $securitySchemes = self::validateSecuritySchemes($securitySchemes);

        if (array_key_exists('securitySchemes', $meta ?? [])) {
            if (!is_array($meta['securitySchemes'])
                || self::validateSecuritySchemes($meta['securitySchemes']) !== $securitySchemes
            ) {
                throw new InvalidArgumentException('Tool _meta.securitySchemes must match the top-level securitySchemes value.');
            }
        }

        $this->securitySchemes = $securitySchemes;
        $meta = [...($meta ?? []), 'securitySchemes' => $securitySchemes];

        parent::__construct(
            name: $name,
            title: $title,
            inputSchema: $inputSchema,
            description: $description,
            annotations: $annotations,
            icons: $icons,
            meta: $meta,
            outputSchema: $outputSchema,
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            ...parent::jsonSerialize(),
            'securitySchemes' => $this->securitySchemes,
        ];
    }

    /**
     * @param list<SecurityScheme> $securitySchemes
     * @return list<SecurityScheme>
     */
    private static function validateSecuritySchemes(array $securitySchemes): array
    {
        if (!array_is_list($securitySchemes) || $securitySchemes === [] || count($securitySchemes) > self::MAX_SCHEMES) {
            throw new InvalidArgumentException('Tool securitySchemes must be a non-empty bounded list.');
        }

        $seenSchemes = [];
        $scopeCount = 0;
        $normalizedSchemes = [];

        foreach ($securitySchemes as $scheme) {
            if (!is_array($scheme) || !is_string($scheme['type'] ?? null)) {
                throw new InvalidArgumentException('Each tool security scheme must be an object with a supported type.');
            }

            $keys = array_keys($scheme);
            sort($keys);

            if ($scheme['type'] === 'noauth') {
                if ($keys !== ['type']) {
                    throw new InvalidArgumentException('A noauth security scheme may contain only its type.');
                }
                $normalizedScheme = ['type' => 'noauth'];
            } elseif ($scheme['type'] === 'oauth2') {
                if ($keys !== ['scopes', 'type'] || !is_array($scheme['scopes']) || !array_is_list($scheme['scopes'])) {
                    throw new InvalidArgumentException('An oauth2 security scheme requires a list of scopes and no unknown fields.');
                }

                $seenScopes = [];
                $normalizedScopes = [];
                foreach ($scheme['scopes'] as $scope) {
                    if (!is_string($scope)
                        || $scope === ''
                        || strlen($scope) > self::MAX_SCOPE_LENGTH
                        || preg_match('/^[\x21\x23-\x5B\x5D-\x7E]+$/D', $scope) !== 1
                    ) {
                        throw new InvalidArgumentException('OAuth tool scopes must be valid RFC 6749 scope tokens.');
                    }

                    if (isset($seenScopes[$scope])) {
                        throw new InvalidArgumentException('OAuth tool scopes must not contain duplicates.');
                    }

                    $seenScopes[$scope] = true;
                    $normalizedScopes[] = $scope;
                    $scopeCount++;
                    if ($scopeCount > self::MAX_SCOPES) {
                        throw new InvalidArgumentException('OAuth tool security schemes contain too many scopes.');
                    }
                }
                $normalizedScheme = ['type' => 'oauth2', 'scopes' => $normalizedScopes];
            } else {
                throw new InvalidArgumentException('Unsupported tool security scheme type.');
            }

            $fingerprintScheme = $normalizedScheme;
            if ($fingerprintScheme['type'] === 'oauth2') {
                sort($fingerprintScheme['scopes'], SORT_STRING);
            }
            $fingerprint = json_encode($fingerprintScheme, JSON_THROW_ON_ERROR);
            if (isset($seenSchemes[$fingerprint])) {
                throw new InvalidArgumentException('Tool securitySchemes must not contain duplicates.');
            }
            $seenSchemes[$fingerprint] = true;
            $normalizedSchemes[] = $normalizedScheme;
        }

        if (strlen(json_encode($normalizedSchemes, JSON_THROW_ON_ERROR)) > self::MAX_SERIALIZED_BYTES) {
            throw new InvalidArgumentException('Tool securitySchemes exceed the serialized size limit.');
        }

        return $normalizedSchemes;
    }
}
