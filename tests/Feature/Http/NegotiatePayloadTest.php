<?php

namespace Bherila\McpLaravelBridge\Tests\Feature\Http;

use Bherila\McpLaravelBridge\Capabilities\Availability;
use Bherila\McpLaravelBridge\Capabilities\ConfigDeploymentFlags;
use Bherila\McpLaravelBridge\Capabilities\OperationRegistry;
use Bherila\McpLaravelBridge\Capabilities\Principal;
use Bherila\McpLaravelBridge\Capabilities\PrincipalResolver;
use Bherila\McpLaravelBridge\Capabilities\Requirement;
use Bherila\McpLaravelBridge\Capabilities\RestBinding;
use Bherila\McpLaravelBridge\Http\NegotiatePayload;
use Bherila\McpLaravelBridge\Http\OperationRoutes;
use Bherila\McpLaravelBridge\Http\PayloadCodecs;
use Bherila\McpLaravelBridge\Tests\Support\PrefixedJsonCodec;
use Bherila\McpLaravelBridge\Tests\Unit\Capabilities\Fixtures;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;

final class NegotiatePayloadTest extends TestCase
{
    private OperationRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = (new OperationRegistry)->register(
            Fixtures::read('things.show', new Requirement(['things:read']), ['rest' => new RestBinding('GET', '/things/{thing}')]),
            Fixtures::write('things.create', new Requirement(['things:write']), ['rest' => new RestBinding('POST', '/things')]),
            Fixtures::write('things.upload', new Requirement(['things:write']), ['rest' => new RestBinding('POST', '/things/upload', requestContentTypes: ['multipart/form-data'])]),
            Fixtures::read('things.file', new Requirement(['things:read']), ['rest' => new RestBinding('GET', '/things/{thing}/file')]),
            Fixtures::write('things.patch', new Requirement(['things:write']), ['rest' => new RestBinding('PATCH', '/things/{thing}', requestContentTypes: ['application/merge-patch+json'])]),
            Fixtures::read('things.big', new Requirement(['things:read']), ['rest' => new RestBinding('GET', '/big-things')]),
            Fixtures::write('things.fail', new Requirement(['things:write']), ['rest' => new RestBinding('POST', '/things/fail')]),
        );
        $this->app->instance(OperationRegistry::class, $this->registry);
        $this->app->instance(Availability::class, new Availability($this->registry, new ConfigDeploymentFlags([])));
        $this->app->instance(PrincipalResolver::class, new class implements PrincipalResolver
        {
            public function principal(Request $request): Principal
            {
                $scopes = array_filter(explode(' ', (string) $request->header('X-Test-Scopes')));

                return Fixtures::principal(scopes: $scopes);
            }
        });
    }

    private function routes(): void
    {
        OperationRoutes::macro();
        Route::prefix('api')->group(static function (): void {
            Route::operation('things.show', static fn (string $thing) => response()->json(['thing' => $thing, 'tags' => ['a', 'b']])->setEtag('json-bytes')->header('Digest', 'sha-256=x'));
            Route::operation('things.create', static fn (Request $request) => response()->json(['created' => $request->input('name'), 'all' => $request->all()], 201));
            Route::operation('things.upload', static fn () => ['ok' => true]);
            Route::operation('things.big', static fn () => response('{"id":123456789012345678901234567890}', 200, ['Content-Type' => 'application/json']));
            Route::operation('things.patch', static fn (Request $request) => ['all' => $request->all()]);
            Route::operation('things.fail', static function (Request $request): never {
                $request->validate(['name' => 'required']);
                abort(500);
            });
            Route::operation('things.file', static fn () => response('%PDF-1.7', 200, ['Content-Type' => 'application/pdf']));
        });
        Route::getRoutes()->refreshNameLookups();
    }

    private function withCodecs(): void
    {
        $this->app->instance(PayloadCodecs::class, new PayloadCodecs(new PrefixedJsonCodec));
        $this->routes();
    }

    public function test_without_codecs_routes_are_exactly_as_before(): void
    {
        $this->app->instance(PayloadCodecs::class, new PayloadCodecs);
        $this->routes();

        self::assertSame(['Bherila\McpLaravelBridge\Http\GateOperation:things.show'], Route::getRoutes()->getByName('things.show')->gatherMiddleware());
        $response = $this->getJson('/api/things/7', ['X-Test-Scopes' => 'things:read', 'Accept' => 'application/x-test']);
        $response->assertOk()->assertJsonPath('thing', '7');
        self::assertNull($response->headers->get('Vary'));
    }

    public function test_with_codecs_the_route_negotiates_after_the_gate(): void
    {
        $this->withCodecs();

        self::assertSame([
            'Bherila\McpLaravelBridge\Http\GateOperation:things.show',
            NegotiatePayload::class.':things.show',
        ], Route::getRoutes()->getByName('things.show')->gatherMiddleware());
    }

    public function test_json_stays_the_default(): void
    {
        $this->withCodecs();

        $response = $this->getJson('/api/things/7', ['X-Test-Scopes' => 'things:read']);
        $response->assertOk()->assertExactJson(['thing' => '7', 'tags' => ['a', 'b']]);
        self::assertStringContainsString('Accept', (string) $response->headers->get('Vary'));
    }

    public function test_a_response_is_encoded_when_the_client_prefers_a_codec(): void
    {
        $this->withCodecs();

        $response = $this->get('/api/things/7', ['X-Test-Scopes' => 'things:read', 'Accept' => 'application/x-test, application/json;q=0.5']);

        $response->assertOk()->assertHeader('Content-Type', 'application/x-test');
        self::assertSame("TEST\n".json_encode(['thing' => '7', 'tags' => ['a', 'b']]), $response->getContent());
        self::assertNull($response->headers->get('ETag'), 'A validator of the JSON bytes is dropped');
        self::assertNull($response->headers->get('Digest'));
        self::assertStringContainsString('Accept', (string) $response->headers->get('Vary'));
    }

    public function test_a_request_body_in_a_codec_type_reaches_the_controller_as_json_input(): void
    {
        $this->withCodecs();

        $response = $this->call('POST', '/api/things', [], [], [], [
            'CONTENT_TYPE' => 'application/x-test; charset=utf-8',
            'HTTP_ACCEPT' => 'application/x-test',
            'HTTP_X_TEST_SCOPES' => 'things:write',
            'HTTP_IDEMPOTENCY_KEY' => 'k1',
        ], "TEST\n".json_encode(['name' => 'Widget']));

        $response->assertCreated()->assertHeader('Content-Type', 'application/x-test');
        self::assertSame(['created' => 'Widget', 'all' => ['name' => 'Widget']], json_decode(substr((string) $response->getContent(), 5), true));
    }

    public function test_a_bodiless_request_with_a_codec_content_type_is_not_decoded(): void
    {
        $this->withCodecs();

        $this->call('GET', '/api/things/7', [], [], [], ['CONTENT_TYPE' => 'application/x-test', 'HTTP_X_TEST_SCOPES' => 'things:read', 'HTTP_ACCEPT' => 'application/json'])
            ->assertOk()->assertJsonPath('thing', '7');
    }

    public function test_a_structured_suffix_json_body_accepts_a_codec(): void
    {
        $this->withCodecs();

        $this->call('PATCH', '/api/things/7', [], [], [], ['CONTENT_TYPE' => 'application/x-test', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_TEST_SCOPES' => 'things:write'], "TEST\n{\"name\":\"W\"}")
            ->assertOk()->assertExactJson(['all' => ['name' => 'W']]);
    }

    public function test_an_invalid_or_unaccepted_body_is_refused(): void
    {
        $this->withCodecs();
        $server = ['CONTENT_TYPE' => 'application/x-test', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_TEST_SCOPES' => 'things:write'];

        $this->call('POST', '/api/things', [], [], [], $server, '{"name":"not the codec"}')
            ->assertStatus(400)->assertJsonPath('message', 'The request body is not valid application/x-test.');
        $this->call('POST', '/api/things', [], [], [], $server, "TEST\n\"scalar\"")
            ->assertStatus(400);
        $this->call('POST', '/api/things/upload', [], [], [], $server, "TEST\n{}")
            ->assertStatus(415);
        $this->call('GET', '/api/things/7', [], [], [], [...$server, 'HTTP_X_TEST_SCOPES' => 'things:read'], "TEST\n{\"thing\":\"8\"}")
            ->assertStatus(415);
    }

    public function test_a_codec_defect_is_a_server_error_not_a_bad_request(): void
    {
        $this->withCodecs();
        $this->withoutExceptionHandling();

        $this->expectException(\RuntimeException::class);
        $this->call('POST', '/api/things', [], [], [], ['CONTENT_TYPE' => 'application/x-test', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_TEST_SCOPES' => 'things:write'], "TEST\nEXPLODE");
    }

    public function test_the_gate_answers_before_any_body_is_decoded(): void
    {
        $this->withCodecs();

        $this->call('POST', '/api/things', [], [], [], ['CONTENT_TYPE' => 'application/x-test', 'HTTP_ACCEPT' => 'application/x-test'], 'not decodable')
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_an_exception_rendered_below_the_middleware_is_negotiated_too(): void
    {
        $this->withCodecs();

        $response = $this->post('/api/things/fail', [], ['X-Test-Scopes' => 'things:write', 'Accept' => 'application/x-test']);

        $response->assertStatus(422)->assertHeader('Content-Type', 'application/x-test');
        self::assertStringStartsWith("TEST\n", (string) $response->getContent());
        self::assertSame('The name field is required.', json_decode(substr((string) $response->getContent(), 5), true)['message']);
        self::assertStringContainsString('Accept', (string) $response->headers->get('Vary'));
    }

    public function test_a_response_a_codec_would_corrupt_stays_json(): void
    {
        $this->withCodecs();

        $response = $this->get('/api/big-things', ['X-Test-Scopes' => 'things:read', 'Accept' => 'application/x-test']);

        $response->assertOk()->assertHeader('Content-Type', 'application/json');
        self::assertSame('{"id":123456789012345678901234567890}', $response->getContent());
    }

    public function test_a_non_json_response_is_left_alone(): void
    {
        $this->withCodecs();

        $response = $this->get('/api/things/7/file', ['X-Test-Scopes' => 'things:read', 'Accept' => 'application/x-test']);

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        self::assertSame('%PDF-1.7', $response->getContent());
    }
}
