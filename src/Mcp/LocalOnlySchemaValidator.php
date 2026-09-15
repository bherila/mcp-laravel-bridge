<?php

namespace Bherila\McpLaravelBridge\Mcp;

use Mcp\Capability\Discovery\SchemaValidator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/** Validates schemas without permitting external reference resolution or payload logging. */
class LocalOnlySchemaValidator extends SchemaValidator
{
    public function __construct(private readonly LoggerInterface $safeLogger = new NullLogger)
    {
        // The upstream validator includes data and schema values when an
        // internal failure is logged. Keep that diagnostic path private and
        // emit only bounded metadata below.
        parent::__construct(new NullLogger);
    }

    /**
     * @param array<string, mixed>|object $schema
     * @return list<array{pointer: string, keyword: string, message: string}>
     */
    public function validateAgainstJsonSchema(mixed $data, array|object $schema): array
    {
        $unsafeReference = $this->firstExternalReference($schema);
        if ($unsafeReference !== null) {
            $this->safeLogger->warning('MCP schema validation rejected an external reference.', [
                'scheme' => parse_url($unsafeReference, PHP_URL_SCHEME) ?: 'relative',
            ]);

            return [[
                'pointer' => '',
                'keyword' => '$ref',
                'message' => 'External JSON Schema references are not supported.',
            ]];
        }

        return parent::validateAgainstJsonSchema($data, $schema);
    }

    private function firstExternalReference(mixed $node): ?string
    {
        if (is_object($node)) {
            $node = get_object_vars($node);
        }
        if (! is_array($node)) {
            return null;
        }

        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value) && ! str_starts_with($value, '#')) {
                return $value;
            }
            $nested = $this->firstExternalReference($value);
            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }
}
