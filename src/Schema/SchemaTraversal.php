<?php

namespace Bherila\McpLaravelBridge\Schema;

use InvalidArgumentException;
use stdClass;

/** Internal JSON Schema applicator traversal; annotation/instance values are opaque. */
final class SchemaTraversal
{
    private const MAPS = ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'];

    private const LISTS = ['allOf', 'anyOf', 'oneOf', 'prefixItems'];

    private const SINGLES = ['additionalProperties', 'unevaluatedProperties', 'additionalItems', 'unevaluatedItems', 'contains', 'propertyNames', 'not', 'if', 'then', 'else', 'contentSchema'];

    /**
     * Transform schema objects only; booleans and all literal JSON values retain their kinds.
     * Empty PHP arrays in unambiguous schema/map positions are the legacy object shorthand.
     *
     * @param  callable(array<array-key, mixed>): array<array-key, mixed>  $visitor
     */
    public static function map(mixed $schema, callable $visitor): mixed
    {
        return self::traverse($schema, $visitor, false);
    }

    /** Decoded JSON has unambiguous kinds: reject arrays in schema/object-map positions. */
    public static function fromJson(mixed $schema): mixed
    {
        return self::traverse($schema, static fn (array $fields): array => $fields, true);
    }

    private static function traverse(mixed $schema, callable $visitor, bool $strictKinds): mixed
    {
        if ($strictKinds && ! is_bool($schema) && ! $schema instanceof stdClass) {
            throw new InvalidArgumentException('A JSON schema must be an object or boolean.');
        }
        if (! is_array($schema) && ! $schema instanceof stdClass) {
            return $schema;
        }
        // A nonempty JSON array cannot be a schema object. Leave it invalid, never inspect data as schemas.
        if (is_array($schema) && $schema !== [] && array_is_list($schema)) {
            return $schema;
        }
        $fields = $visitor(is_array($schema) ? $schema : get_object_vars($schema));
        foreach ($fields as $key => $value) {
            if ($strictKinds && in_array($key, [...self::MAPS, 'dependencies'], true) && ! $value instanceof stdClass) {
                throw new InvalidArgumentException('A JSON schema map must be an object.');
            }
            if ($strictKinds && in_array($key, self::LISTS, true) && (! is_array($value) || ! array_is_list($value))) {
                throw new InvalidArgumentException('A JSON schema list must be an array.');
            }
            if (in_array($key, self::MAPS, true) && (is_array($value) || $value instanceof stdClass)) {
                $entries = is_array($value) ? $value : get_object_vars($value);
                foreach ($entries as $name => $entry) {
                    $entries[$name] = self::traverse($entry, $visitor, $strictKinds);
                }
                $fields[$key] = self::objectMap($entries, $value instanceof stdClass);
            } elseif (in_array($key, self::LISTS, true) && is_array($value) && array_is_list($value)) {
                $fields[$key] = array_map(static fn (mixed $entry): mixed => self::traverse($entry, $visitor, $strictKinds), $value);
            } elseif ($key === 'items' && is_array($value) && array_is_list($value)) {
                // Draft 4–7 tuple form, including its empty list; prefixItems is the modern form.
                $fields[$key] = array_map(static fn (mixed $entry): mixed => self::traverse($entry, $visitor, $strictKinds), $value);
            } elseif (($key === 'items' || in_array($key, self::SINGLES, true))) {
                $fields[$key] = self::traverse($value, $visitor, $strictKinds);
            } elseif ($key === 'dependencies' && (is_array($value) || $value instanceof stdClass)) {
                $entries = is_array($value) ? $value : get_object_vars($value);
                foreach ($entries as $name => $entry) {
                    // Property dependencies are string lists, not schemas.
                    if ($entry instanceof stdClass || is_bool($entry) || (is_array($entry) && ! array_is_list($entry))) {
                        $entries[$name] = self::traverse($entry, $visitor, $strictKinds);
                    }
                }
                $fields[$key] = self::objectMap($entries, $value instanceof stdClass);
            }
        }

        return self::objectMap($fields, $schema instanceof stdClass);
    }

    public static function normalize(mixed $schema): mixed
    {
        return self::map($schema, static fn (array $fields): array => $fields);
    }

    /** @param array<array-key, mixed> $fields */
    private static function objectMap(array $fields, bool $wasObject): array|stdClass
    {
        return $wasObject || $fields === [] || array_filter(array_keys($fields), is_int(...)) !== []
            ? (object) $fields : $fields;
    }
}
