<?php

namespace Bherila\McpLaravelBridge\Tests\Feature\Http;

use Bherila\McpLaravelBridge\Capabilities\AuthenticatedPrincipal;
use Bherila\McpLaravelBridge\Capabilities\Availability;
use Bherila\McpLaravelBridge\Capabilities\ConfigDeploymentFlags;
use Bherila\McpLaravelBridge\Capabilities\InvalidOperation;
use Bherila\McpLaravelBridge\Capabilities\OperationRegistry;
use Bherila\McpLaravelBridge\Capabilities\Principal;
use Bherila\McpLaravelBridge\Capabilities\PrincipalResolver;
use Bherila\McpLaravelBridge\Capabilities\Requirement;
use Bherila\McpLaravelBridge\Capabilities\RestBinding;
use Bherila\McpLaravelBridge\Capabilities\ScopeRule;
use Bherila\McpLaravelBridge\Http\OperationRoutes;
use Bherila\McpLaravelBridge\Tests\Unit\Capabilities\Fixtures;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;

/** Routes registered from operations answer to the same availability evaluation. */
final class OperationRoutesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $registry = (new OperationRegistry)->register(
            Fixtures::read('things.show', new Requirement(['things:read']), ['rest' => new RestBinding('GET', '/things/{thing}', routeName: 'things.show-route', pathParameters: ['thing'])]),
            Fixtures::read('things.search', new Requirement(['things:read', 'search:use'], ScopeRule::Any), ['rest' => new RestBinding('GET', '/things/search')]),
            Fixtures::write('token.revoke', Requirement::authenticated(), ['rest' => new RestBinding('DELETE', '/token')]),
            Fixtures::write('things.create', new Requirement(['things:write'], flags: ['writes']), ['rest' => new RestBinding('POST', '/things')]),
            Fixtures::read('things.mcp_only', new Requirement(['things:read']), ['rest' => null]),
        );
        config(['agent.writes' => false]);
        $this->app->instance(OperationRegistry::class, $registry);
        $this->app->instance(Availability::class, new Availability($registry, new ConfigDeploymentFlags(['writes' => 'agent.writes'])));
        $this->app->instance(PrincipalResolver::class, new class implements PrincipalResolver
        {
            public function principal(Request $request): Principal
            {
                $scopes = array_filter(explode(' ', (string) $request->header('X-Test-Scopes')));
                $authenticated = $request->hasHeader('X-Test-Scopes');

                return new class($scopes, $authenticated) implements AuthenticatedPrincipal
                {
                    public function __construct(private array $scopes, private bool $authenticated) {}

                    public function isAuthenticated(): bool
                    {
                        return $this->authenticated;
                    }

                    public function hasScope(string $scope): bool
                    {
                        return in_array($scope, $this->scopes, true);
                    }

                    public function can(string $permission): bool
                    {
                        return true;
                    }

                    public function allowsGroup(?string $group): bool
                    {
                        return true;
                    }
                };
            }
        });

        OperationRoutes::macro();
        Route::prefix('api/v1')->name('api.')->group(static function (): void {
            // Literal segments before parameters, as with any Laravel routes.
            Route::operation('things.search', static fn () => ['ok' => true]);
            Route::operation('things.show', static fn (string $thing) => ['thing' => $thing]);
            Route::operation('token.revoke', static fn () => response()->noContent());
            Route::operation('things.create', static fn () => response()->json(['ok' => true], 201));
        });
        Route::getRoutes()->refreshNameLookups();
    }

    public function test_the_route_comes_from_the_binding(): void
    {
        $route = Route::getRoutes()->getByName('api.things.show-route');
        $this->assertNotNull($route);
        $this->assertSame('api/v1/things/{thing}', $route->uri());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertContains('Bherila\McpLaravelBridge\Http\GateOperation:things.show', $route->gatherMiddleware());
        $this->assertNotNull(Route::getRoutes()->getByName('api.things.search'), 'The operation id names a route without a declared route name');
    }

    public function test_an_available_operation_runs(): void
    {
        $this->getJson('/api/v1/things/7', ['X-Test-Scopes' => 'things:read'])->assertOk()->assertJsonPath('thing', '7');
        $this->getJson('/api/v1/things/search', ['X-Test-Scopes' => 'search:use'])->assertOk();
        $this->deleteJson('/api/v1/token', [], ['X-Test-Scopes' => ''])->assertNoContent();
    }

    public function test_a_withheld_operation_answers_with_its_reason(): void
    {
        $this->getJson('/api/v1/things/7', ['X-Test-Scopes' => 'other'])
            ->assertForbidden()
            ->assertHeader('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="things:read"')
            ->assertExactJson(['message' => 'This credential lacks the scope this operation needs.', 'operation' => 'things.show', 'reason' => 'missing_scope', 'detail' => 'things:read']);
        $this->getJson('/api/v1/things/search', ['X-Test-Scopes' => 'other'])
            ->assertForbidden()
            ->assertHeader('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="things:read"')
            ->assertJsonPath('detail', 'things:read|search:use');
        $this->deleteJson('/api/v1/token')->assertUnauthorized()->assertJsonPath('reason', 'unauthenticated');
        $this->getJson('/api/v1/things/7')
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer')
            ->assertJsonPath('reason', 'missing_scope');
        $this->postJson('/api/v1/things', [], ['X-Test-Scopes' => 'things:write'])
            ->assertForbidden()
            ->assertJsonPath('reason', 'deployment_flag')
            ->assertJsonPath('detail', 'writes');
    }

    public function test_an_operation_without_a_rest_binding_cannot_be_routed(): void
    {
        $this->expectException(InvalidOperation::class);
        $this->expectExceptionMessage('no REST binding');
        Route::operation('things.mcp_only', static fn () => []);
    }
}
