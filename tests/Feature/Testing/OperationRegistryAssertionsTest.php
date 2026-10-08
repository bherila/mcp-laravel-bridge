<?php

namespace Bherila\McpLaravelBridge\Tests\Feature\Testing;

use Bherila\McpLaravelBridge\Capabilities\McpBinding;
use Bherila\McpLaravelBridge\Capabilities\OperationRegistry;
use Bherila\McpLaravelBridge\Capabilities\Requirement;
use Bherila\McpLaravelBridge\Capabilities\RestBinding;
use Bherila\McpLaravelBridge\OpenApi\OpenApiDocumentBuilder;
use Bherila\McpLaravelBridge\OpenApi\OpenApiSettings;
use Bherila\McpLaravelBridge\Testing\OperationRegistryAssertions;
use Bherila\McpLaravelBridge\Tests\Unit\Capabilities\Fixtures;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;

final class OperationRegistryAssertionsTest extends TestCase
{
    use OperationRegistryAssertions;

    private OperationRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = (new OperationRegistry)->register(
            Fixtures::read('things.list', new Requirement(['things:read']), ['rest' => new RestBinding('GET', '/things', routeName: 'api.things.list')]),
            Fixtures::write('things.create', new Requirement(['things:write']), ['rest' => new RestBinding('POST', '/things')]),
            Fixtures::write('things.archive', new Requirement(['things:write']), ['rest' => null, 'mcp' => new McpBinding(name: 'things_archive')]),
            Fixtures::write('mcp.exchange', new Requirement(['mcp:use']), ['rest' => new RestBinding('POST', '/mcp')]),
        );
        Route::get('/api/v1/things', static fn () => [])->name('api.things.list');
        Route::middleware('web')->group(static function (): void {
            Route::post('/things', static fn () => [])->name('web.things.store');
            Route::post('/things/{thing}/archive', static fn () => [])->name('web.things.archive');
            Route::post('/logout', static fn () => [])->name('logout');
            Route::get('/things', static fn () => [])->name('web.things.index');
        });
        Route::getRoutes()->refreshNameLookups();
    }

    private function openApi(): OpenApiDocumentBuilder
    {
        return new OpenApiDocumentBuilder($this->registry, new OpenApiSettings(
            title: 'Things', version: '1', serverUrl: 'https://things.example.test/api/v1',
            authorizationUrl: 'https://things.example.test/oauth/authorize', tokenUrl: 'https://things.example.test/oauth/token',
            connectionScopes: ['mcp:use'],
        ));
    }

    /** @return array<string, string> */
    private function classification(): array
    {
        return [
            'web.things.store' => 'operation:things.create',
            'web.things.archive' => 'mcp-only:things.archive',
            'logout' => 'web-only:ends the browser session',
        ];
    }

    public function test_a_sound_application_passes(): void
    {
        self::assertOperationRegistryContract($this->registry, $this->openApi(), ['mcp:use']);
        self::assertWebRoutesClassified($this->registry, $this->classification());
    }

    public function test_a_route_name_that_is_not_registered_fails(): void
    {
        $registry = (new OperationRegistry)->register(Fixtures::read('x', new Requirement(['s']), ['rest' => new RestBinding('GET', '/x', routeName: 'nowhere')]));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('[nowhere]');
        self::assertOperationRegistryContract($registry);
    }

    public function test_an_operation_without_an_api_token_alternative_fails(): void
    {
        $builder = new OpenApiDocumentBuilder($this->registry, new OpenApiSettings(
            title: 'Things', version: '1', serverUrl: 'https://things.example.test/api/v1',
            authorizationUrl: 'https://things.example.test/oauth/authorize', tokenUrl: 'https://things.example.test/oauth/token',
            apiTokens: false,
        ));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('personal API tokens');
        self::assertOperationRegistryContract($this->registry, $builder, ['mcp:use']);
    }

    /** @return iterable<string, array{0: array<string, string>, 1: string}> */
    public static function badClassifications(): iterable
    {
        $sound = [
            'web.things.store' => 'operation:things.create',
            'web.things.archive' => 'mcp-only:things.archive',
            'logout' => 'web-only:ends the browser session',
        ];
        yield 'unclassified route' => [array_diff_key($sound, ['web.things.archive' => 1]), '[web.things.archive] is not classified'];
        yield 'stale entry' => [[...$sound, 'web.gone' => 'missing'], 'no longer exists'];
        yield 'operation without REST' => [[...$sound, 'web.things.store' => 'operation:things.archive'], 'has no REST binding'];
        yield 'mcp-only that landed' => [[...$sound, 'web.things.store' => 'mcp-only:things.create'], 'now has a REST binding'];
        yield 'reason required' => [[...$sound, 'logout' => 'deliberate: '], 'needs a reason'];
        yield 'unknown kind' => [[...$sound, 'logout' => 'maybe'], 'unknown classification'];
    }

    /** @param  array<string, string>  $classification */
    #[DataProvider('badClassifications')]
    public function test_web_route_classification_failures(array $classification, string $message): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage($message);
        self::assertWebRoutesClassified($this->registry, $classification);
    }

    public function test_snapshots_pin_scopes_and_visibility(): void
    {
        $dir = sys_get_temp_dir().'/op-snapshots-'.bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            file_put_contents("{$dir}/scopes.json", json_encode([
                'mcp.exchange' => ['scopes' => ['mcp:use']],
                'things.archive' => ['scopes' => ['things:write']],
                'things.create' => ['scopes' => ['things:write']],
                'things.list' => ['scopes' => ['things:read']],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            self::assertScopeInventory($this->registry, "{$dir}/scopes.json");

            file_put_contents("{$dir}/visible.json", json_encode(['reader' => ['things.list']], JSON_PRETTY_PRINT)."\n");
            self::assertVisibilitySnapshot(['reader' => ['things.list', 'things.list']], "{$dir}/visible.json");

            $this->expectException(AssertionFailedError::class);
            self::assertVisibilitySnapshot(['reader' => ['things.list', 'things.create']], "{$dir}/visible.json");
        } finally {
            array_map('unlink', glob("{$dir}/*") ?: []);
            rmdir($dir);
        }
    }
}
