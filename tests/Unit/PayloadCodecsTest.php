<?php

namespace Bherila\McpLaravelBridge\Tests\Unit;

use Bherila\McpLaravelBridge\Http\PayloadCodecs;
use Bherila\McpLaravelBridge\Tests\Support\PrefixedJsonCodec;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PayloadCodecsTest extends TestCase
{
    /** @return iterable<string, array{0: ?string, 1: ?string}> */
    public static function acceptHeaders(): iterable
    {
        yield 'no header' => [null, null];
        yield 'empty' => ['', null];
        yield 'anything' => ['*/*', null];
        yield 'any application type ties, JSON wins' => ['application/*', null];
        yield 'JSON' => ['application/json', null];
        yield 'the codec' => ['application/x-test', 'application/x-test'];
        yield 'the codec, case and parameters' => ['Application/X-Test; charset=utf-8', 'application/x-test'];
        yield 'preferred over JSON' => ['application/json;q=0.5, application/x-test', 'application/x-test'];
        yield 'JSON preferred' => ['application/x-test;q=0.5, application/json', null];
        yield 'equal quality' => ['application/x-test, application/json', null];
        yield 'specific beats wildcard' => ['*/*;q=0.1, application/x-test', 'application/x-test'];
        yield 'refused codec' => ['application/x-test;q=0', null];
        yield 'refused JSON, no codec asked' => ['application/json;q=0, text/html', null];
        yield 'second codec' => ['application/x-other', 'application/x-other'];
    }

    #[DataProvider('acceptHeaders')]
    public function test_negotiation_prefers_json_unless_a_codec_ranks_higher(?string $accept, ?string $expected): void
    {
        $codecs = new PayloadCodecs(new PrefixedJsonCodec, new PrefixedJsonCodec('application/x-other'));

        self::assertSame($expected, $codecs->negotiate($accept)?->mediaType());
    }

    public function test_content_types_find_their_codec(): void
    {
        $codecs = new PayloadCodecs(new PrefixedJsonCodec);

        self::assertSame(['application/x-test'], $codecs->mediaTypes());
        self::assertSame(['application/vnd.example~compact', "text/x-a!b#c\$d%e&f'g^h_i`j|k"], (new PayloadCodecs(new PrefixedJsonCodec('application/vnd.example~compact'), new PrefixedJsonCodec("text/x-a!b#c\$d%e&f'g^h_i`j|k")))->mediaTypes(), 'Every token character');
        self::assertNotNull($codecs->forContentType('application/x-test; charset=utf-8'));
        self::assertNull($codecs->forContentType('application/json'));
        self::assertNull($codecs->forContentType(null));
        self::assertTrue((new PayloadCodecs)->isEmpty());
        self::assertNull((new PayloadCodecs)->negotiate('application/x-test'), 'Nothing to negotiate');
    }

    /** @return iterable<string, array{0: list<string>}> */
    public static function invalidCodecs(): iterable
    {
        yield 'JSON itself' => [['application/json']];
        yield 'a range' => [['application/*']];
        yield 'any type' => [['*/*']];
        yield 'a parameter-less space' => [['application/x test']];
        yield 'not a media type' => [['toon']];
        yield 'twice' => [['application/x-test', 'Application/X-Test']];
    }

    /** @param  list<string>  $types */
    #[DataProvider('invalidCodecs')]
    public function test_a_codec_must_serve_one_concrete_type_of_its_own(array $types): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PayloadCodecs(...array_map(static fn (string $type): PrefixedJsonCodec => new PrefixedJsonCodec($type), $types));
    }
}
