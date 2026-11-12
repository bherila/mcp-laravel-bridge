<?php

namespace Bherila\McpLaravelBridge\Mcp;

use Bherila\McpLaravelBridge\Schema\SchemaTraversal;
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
        parent::__construct(logger: new NullLogger);
    }

    /**
     * @param array<string, mixed>|object $schema
     * @return list<array{pointer: string, keyword: string, message: string}>
     */
    public function validateAgainstJsonSchema(mixed $data, array|object $schema): array
    {
        try {
            // A public object may implement JsonSerializable. Guard the same immutable JSON
            // snapshot upstream consumes, not its unrelated PHP properties or a second encoding.
            $prepared = SchemaTraversal::normalize(json_decode(json_encode($schema, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR));
            if (! is_array($prepared) && ! is_object($prepared)) {
                throw new \InvalidArgumentException;
            }
        } catch (\Throwable) {
            return [['pointer' => '', 'keyword' => 'schema', 'message' => 'Invalid JSON Schema definition.']];
        }
        $unsafeReference = $this->firstExternalReference($prepared);
        if ($unsafeReference !== null) {
            $scheme = strtolower((string) parse_url($unsafeReference['value'], PHP_URL_SCHEME));
            $this->safeLogger->warning('MCP schema validation rejected an external reference.', [
                'kind' => $unsafeReference['keyword'],
                'scheme' => in_array($scheme, ['http', 'https', 'file'], true)
                    ? $scheme
                    : ($scheme === '' ? 'relative' : 'other'),
            ]);

            return [[
                'pointer' => '',
                'keyword' => $unsafeReference['keyword'],
                'message' => 'External JSON Schema references are not supported.',
            ]];
        }

        return parent::validateAgainstJsonSchema($data, $prepared);
    }

    /** @return array{keyword: string, value: string}|null */
    private function firstExternalReference(mixed $node): ?array
    {
        $found = null;
        SchemaTraversal::map($node, static function (array $fields) use (&$found): array {
            foreach (['$ref', '$dynamicRef', '$recursiveRef'] as $keyword) {
                $value = $fields[$keyword] ?? null;
                if ($found === null && is_string($value) && ! str_starts_with($value, '#')) {
                    $found = ['keyword' => $keyword, 'value' => $value];
                }
            }
            return $fields;
        });

        return $found;
    }
}
