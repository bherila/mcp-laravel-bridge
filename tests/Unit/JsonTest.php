<?php

namespace Bherila\McpLaravelBridge\Tests\Unit;

use Bherila\McpLaravelBridge\Json;
use PHPUnit\Framework\TestCase;

final class JsonTest extends TestCase
{
    public function test_empty_objects_remain_distinct_from_empty_lists(): void
    {
        $decoded = Json::decodeObject('{"object":{},"list":[]}');

        self::assertInstanceOf(\stdClass::class, $decoded['object']);
        self::assertSame([], $decoded['list']);
    }

    public function test_malformed_or_non_object_json_is_not_treated_as_an_envelope(): void
    {
        self::assertNull(Json::decodeObject('{broken'));
        self::assertNull(Json::decodeObject('[]'));
    }
}
