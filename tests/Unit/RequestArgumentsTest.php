<?php

namespace Bherila\McpLaravelBridge\Tests\Unit;

use Bherila\McpLaravelBridge\Mcp\RequestArguments;
use Illuminate\Http\Request;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\TestCase;

final class RequestArgumentsTest extends TestCase
{
    public function test_it_preserves_omitted_null_and_empty_object_wire_shapes(): void
    {
        $wire = json_encode([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'tools/call',
            'params' => [
                'name' => 'things.update',
                'arguments' => ['clearable' => null, 'metadata' => (object) []],
            ],
        ], JSON_THROW_ON_ERROR);
        $arguments = new RequestArguments(Request::create('/api/v1/mcp', 'POST', content: $wire));
        $call = (new CallToolRequest('things.update', ['clearable' => null, 'metadata' => []]))->withId(7);
        $context = new RequestContext($this->createStub(SessionInterface::class), $call);

        self::assertTrue($arguments->has($context, 'clearable'));
        self::assertNull($arguments->value($context, 'clearable', 'fallback'));
        self::assertFalse($arguments->has($context, 'omitted'));
        self::assertSame('fallback', $arguments->value($context, 'omitted', 'fallback'));
        self::assertInstanceOf(\stdClass::class, $arguments->nextValidationArguments($call->arguments)['metadata']);
    }

    public function test_malformed_json_is_ignored_safely(): void
    {
        $arguments = new RequestArguments(Request::create('/api/v1/mcp', 'POST', content: '{broken'));
        $call = (new CallToolRequest('things.update', []))->withId(7);
        $context = new RequestContext($this->createStub(SessionInterface::class), $call);

        self::assertFalse($arguments->has($context, 'anything'));
        self::assertNull($arguments->nextValidationArguments([]));
    }
}
