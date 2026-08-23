<?php

namespace Bherila\McpLaravelBridge\Mcp;

use Mcp\Capability\Discovery\SchemaValidator;
use Psr\Log\LoggerInterface;

/** Validates original JSON shapes before associative SDK decoding loses them. */
final class OriginalShapeSchemaValidator extends SchemaValidator
{
    public function __construct(LoggerInterface $logger, private readonly RequestArguments $requestArguments)
    {
        parent::__construct($logger);
    }

    /**
     * @param  array<string, mixed>|object  $schema
     * @return list<array{pointer: string, keyword: string, message: string}>
     */
    public function validateAgainstJsonSchema(mixed $data, array|object $schema): array
    {
        $wireArguments = $this->requestArguments->nextValidationArguments($data);

        return parent::validateAgainstJsonSchema($wireArguments ?? $data, $schema);
    }
}
