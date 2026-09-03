<?php

namespace Bherila\McpLaravelBridge\Tests\Unit;

use Bherila\McpLaravelBridge\Mcp\ToolWithSecuritySchemes;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\ToolAnnotations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ToolWithSecuritySchemesTest extends TestCase
{
    public function test_it_emits_identical_top_level_and_compatibility_security_schemes(): void
    {
        $schemes = [
            ['type' => 'noauth'],
            ['type' => 'oauth2', 'scopes' => ['mcp:use', 'things:read']],
        ];
        $tool = $this->tool($schemes, ['ui' => ['resourceUri' => 'ui://things/list']]);
        $serialized = $tool->jsonSerialize();

        self::assertSame($schemes, $serialized['securitySchemes']);
        self::assertSame($schemes, $serialized['_meta']['securitySchemes']);
        self::assertSame(['resourceUri' => 'ui://things/list'], $serialized['_meta']['ui']);
        self::assertSame('object', $serialized['inputSchema']['type']);
        self::assertSame('object', $serialized['outputSchema']['type']);
        self::assertTrue($serialized['annotations']->readOnlyHint);
    }

    public function test_an_existing_identical_compatibility_mirror_is_accepted(): void
    {
        $schemes = [['type' => 'oauth2', 'scopes' => []]];

        self::assertSame(
            $schemes,
            $this->tool($schemes, ['securitySchemes' => $schemes])->jsonSerialize()['securitySchemes'],
        );
    }

    public function test_a_compatibility_mirror_with_reordered_scopes_is_accepted_and_replaced_with_the_top_level_order(): void
    {
        $schemes = [['type' => 'oauth2', 'scopes' => ['read', 'write']]];
        $serialized = $this->tool($schemes, [
            'securitySchemes' => [['scopes' => ['write', 'read'], 'type' => 'oauth2']],
        ])->jsonSerialize();

        self::assertSame($schemes, $serialized['securitySchemes']);
        self::assertSame($schemes, $serialized['_meta']['securitySchemes']);
    }

    public function test_scheme_object_keys_are_normalized(): void
    {
        $serialized = $this->tool([['scopes' => ['read'], 'type' => 'oauth2']])->jsonSerialize();

        self::assertSame(
            [['type' => 'oauth2', 'scopes' => ['read']]],
            $serialized['securitySchemes'],
        );
    }

    public function test_validated_schemes_are_detached_from_caller_references(): void
    {
        $scope = 'things:read';
        $schemes = [['type' => 'oauth2', 'scopes' => [&$scope]]];
        $tool = $this->tool($schemes);

        $scope = 'invalid scope after construction';

        self::assertSame(
            [['type' => 'oauth2', 'scopes' => ['things:read']]],
            $tool->jsonSerialize()['securitySchemes'],
        );
        self::assertSame(
            $tool->jsonSerialize()['securitySchemes'],
            $tool->jsonSerialize()['_meta']['securitySchemes'],
        );
    }

    /** @param mixed $schemes */
    #[DataProvider('invalidSchemeProvider')]
    public function test_it_rejects_malformed_or_unbounded_schemes(mixed $schemes): void
    {
        $this->expectException(InvalidArgumentException::class);

        /** @var array<mixed> $schemes */
        $this->tool($schemes);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidSchemeProvider(): iterable
    {
        yield 'empty list' => [[]];
        yield 'associative outer value' => [['oauth' => ['type' => 'oauth2', 'scopes' => []]]];
        yield 'missing type' => [[['scopes' => ['read']]]];
        yield 'unsupported type' => [[['type' => 'apiKey']]];
        yield 'noauth fields' => [[['type' => 'noauth', 'scopes' => []]]];
        yield 'oauth missing scopes' => [[['type' => 'oauth2']]];
        yield 'oauth scalar scopes' => [[['type' => 'oauth2', 'scopes' => 'read']]];
        yield 'oauth unknown field' => [[['type' => 'oauth2', 'scopes' => ['read'], 'issuer' => 'https://example.test']]];
        yield 'empty scope token' => [[['type' => 'oauth2', 'scopes' => ['']]]];
        yield 'space in scope token' => [[['type' => 'oauth2', 'scopes' => ['things read']]]];
        yield 'quote in scope token' => [[['type' => 'oauth2', 'scopes' => ['things"read']]]];
        yield 'backslash in scope token' => [[['type' => 'oauth2', 'scopes' => ['things\\read']]]];
        yield 'duplicate scope' => [[['type' => 'oauth2', 'scopes' => ['read', 'read']]]];
        yield 'duplicate scheme' => [[['type' => 'noauth'], ['type' => 'noauth']]];
        yield 'duplicate reordered scheme' => [[
            ['type' => 'oauth2', 'scopes' => ['read', 'write']],
            ['scopes' => ['write', 'read'], 'type' => 'oauth2'],
        ]];
        yield 'duplicate reordered numeric-looking scopes' => [[
            ['type' => 'oauth2', 'scopes' => ['1', '01']],
            ['type' => 'oauth2', 'scopes' => ['01', '1']],
        ]];
        yield 'too many schemes' => [array_fill(
            0,
            ToolWithSecuritySchemes::MAX_SCHEMES + 1,
            ['type' => 'oauth2', 'scopes' => []],
        )];
        yield 'too many scopes' => [[['type' => 'oauth2', 'scopes' => array_map(
            static fn (int $index): string => 'scope:'.$index,
            range(0, ToolWithSecuritySchemes::MAX_SCOPES),
        )]]];
        yield 'oversized scope' => [[['type' => 'oauth2', 'scopes' => [str_repeat('a', ToolWithSecuritySchemes::MAX_SCOPE_LENGTH + 1)]]]];
        yield 'oversized serialized value' => [[['type' => 'oauth2', 'scopes' => array_map(
            static fn (int $index): string => str_pad('scope:'.$index.':', ToolWithSecuritySchemes::MAX_SCOPE_LENGTH, 'a'),
            range(0, 39),
        )]]];
    }

    public function test_it_rejects_a_conflicting_compatibility_mirror(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->tool(
            [['type' => 'oauth2', 'scopes' => ['read']]],
            ['securitySchemes' => [['type' => 'oauth2', 'scopes' => ['write']]]],
        );
    }

    public function test_it_rejects_a_null_compatibility_mirror(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->tool(
            [['type' => 'oauth2', 'scopes' => ['read']]],
            ['securitySchemes' => null],
        );
    }

    /**
     * @param array<mixed>         $securitySchemes
     * @param array<string, mixed> $meta
     */
    private function tool(array $securitySchemes, array $meta = []): ToolWithSecuritySchemes
    {
        return new ToolWithSecuritySchemes(
            securitySchemes: $securitySchemes,
            name: 'things.list',
            title: 'List things',
            inputSchema: ['type' => 'object', 'properties' => []],
            description: 'Lists things.',
            annotations: new ToolAnnotations(readOnlyHint: true),
            meta: $meta,
            outputSchema: ['type' => 'object', 'properties' => []],
        );
    }
}
