<?php

namespace Bherila\McpLaravelBridge\Tests\Unit\OpenApi;

use Bherila\McpLaravelBridge\Capabilities\OperationRegistry;
use Bherila\McpLaravelBridge\Capabilities\Requirement;
use Bherila\McpLaravelBridge\Capabilities\RestBinding;
use Bherila\McpLaravelBridge\Http\PayloadCodecs;
use Bherila\McpLaravelBridge\OpenApi\OpenApiDocumentBuilder;
use Bherila\McpLaravelBridge\OpenApi\OpenApiSettings;
use Bherila\McpLaravelBridge\Testing\OperationRegistryAssertions;
use Bherila\McpLaravelBridge\Tests\Support\PrefixedJsonCodec;
use Bherila\McpLaravelBridge\Tests\Unit\Capabilities\Fixtures;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/** The document offers each codec's media type where a body is JSON (#22). */
final class OpenApiCodecMediaTypesTest extends TestCase
{
    use OperationRegistryAssertions;

    private static function registry(): OperationRegistry
    {
        $object = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]];

        return (new OperationRegistry)->register(
            Fixtures::write('things.create', new Requirement(['things:write']), ['rest' => new RestBinding('POST', '/things'), 'input' => $object, 'output' => $object]),
            Fixtures::write('things.patch', new Requirement(['things:write']), ['rest' => new RestBinding('PATCH', '/things/{thing}', requestContentTypes: ['application/merge-patch+json']), 'input' => $object]),
            Fixtures::write('things.upload', new Requirement(['things:write']), ['rest' => new RestBinding('POST', '/things/upload', requestContentTypes: ['multipart/form-data']), 'input' => $object]),
        );
    }

    private static function settings(array $extraMediaTypes = []): OpenApiSettings
    {
        return new OpenApiSettings(title: 'Things', version: '1', serverUrl: 'https://things.example.test/api', extraMediaTypes: $extraMediaTypes);
    }

    public function test_codec_types_are_offered_beside_json_bodies_only(): void
    {
        $paths = (new OpenApiDocumentBuilder(self::registry(), self::settings(), codecs: new PayloadCodecs(new PrefixedJsonCodec)))->full()['paths'];

        self::assertSame(['application/json', 'application/x-test'], array_keys($paths['/things']['post']['requestBody']['content']));
        self::assertSame(['application/json', 'application/x-test'], array_keys($paths['/things']['post']['responses'][200]['content']));
        self::assertSame(['multipart/form-data'], array_keys($paths['/things/upload']['post']['requestBody']['content']), 'A multipart body is not JSON');
        self::assertSame(['application/merge-patch+json', 'application/x-test'], array_keys($paths['/things/{thing}']['patch']['requestBody']['content']), 'A +json body is JSON');
    }

    public function test_declared_json_responses_offer_codec_types_too(): void
    {
        $json = ['description' => 'A thing', 'content' => ['application/json' => ['schema' => ['type' => 'object']]]];
        $registry = (new OperationRegistry)->register(
            Fixtures::read('things.show', new Requirement(['things:read']), ['rest' => new RestBinding('GET', '/things/{thing}', responses: [200 => $json, 404 => 'Missing', 410 => ['description' => 'Gone']])]),
            Fixtures::read('things.file', new Requirement(['things:read']), ['rest' => new RestBinding('GET', '/things/{thing}/file', responses: [200 => ['description' => 'File', 'content' => ['application/pdf' => ['schema' => ['type' => 'string']]]]])]),
        );
        $settings = new OpenApiSettings(title: 'Things', version: '1', serverUrl: 'https://things.example.test/api', responses: ['Missing' => $json]);
        $document = (new OpenApiDocumentBuilder($registry, $settings, codecs: new PayloadCodecs(new PrefixedJsonCodec)))->full();

        self::assertSame(['application/json', 'application/x-test'], array_keys($document['paths']['/things/{thing}']['get']['responses'][200]['content']));
        self::assertSame(['application/json', 'application/x-test'], array_keys($document['components']['responses']['Missing']['content']));
        self::assertArrayNotHasKey('content', $document['paths']['/things/{thing}']['get']['responses'][410]);
        self::assertSame(['application/pdf'], array_keys($document['paths']['/things/{thing}/file']['get']['responses'][200]['content']), 'A file is not re-encoded');
        self::assertSame(['application/json'], array_keys((new OpenApiDocumentBuilder($registry, $settings))->full()['components']['responses']['Missing']['content']), 'No codecs, no change');
    }

    public function test_declared_extra_types_still_work_and_are_not_repeated(): void
    {
        $builder = new OpenApiDocumentBuilder(self::registry(), self::settings(['application/x-test', 'application/x-legacy']), codecs: new PayloadCodecs(new PrefixedJsonCodec));

        self::assertSame(['application/json', 'application/x-test', 'application/x-legacy'], array_keys($builder->full()['paths']['/things']['post']['requestBody']['content']));
        self::assertSame(['application/x-legacy'], $builder->mediaTypesWithoutCodec());
        self::assertSame(['application/x-test'], (new OpenApiDocumentBuilder(self::registry(), self::settings(['application/x-test'])))->mediaTypesWithoutCodec(), 'No codecs: every declared type is unserved');
    }

    public function test_no_codec_registered_is_byte_identical_to_0_4_1(): void
    {
        $settings = self::settings(['application/x-test']);
        $without = OpenApiDocumentBuilder::encode((new OpenApiDocumentBuilder(self::registry(), $settings))->full());

        self::assertSame($without, OpenApiDocumentBuilder::encode((new OpenApiDocumentBuilder(self::registry(), $settings, codecs: new PayloadCodecs))->full()));
    }

    public function test_every_declared_type_needs_a_codec(): void
    {
        $registry = self::registry()->register(Fixtures::write('things.import', new Requirement(['things:write']), ['rest' => new RestBinding('POST', '/things/import', requestContentTypes: ['text/csv', 'application/vnd.things+json'])]));
        $codecs = new PayloadCodecs(new PrefixedJsonCodec);

        self::assertMediaTypesHaveCodecs($registry, self::settings(['application/x-test']), $codecs, ['text/csv']);

        try {
            self::assertMediaTypesHaveCodecs($registry, self::settings(['application/x-test', 'application/x-legacy']), $codecs);
            self::fail('Undelivered types passed');
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('application/x-legacy (OpenApiSettings::$extraMediaTypes)', $failure->getMessage());
            self::assertStringContainsString('text/csv (operation [things.import])', $failure->getMessage());
            self::assertStringNotContainsString('vnd.things+json', $failure->getMessage());
        }

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('multipart/form-data (OpenApiSettings::$extraMediaTypes)');
        self::assertMediaTypesHaveCodecs($registry, self::settings(['multipart/form-data']), $codecs, ['text/csv']);
    }
}
