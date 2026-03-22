<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Routing\RouteEntry;
use StoneScriptPHP\Routing\Router;
use StoneScriptPHP\Routing\ScopeMiddlewareBuilder;
use StoneScriptPHP\Routing\MiddlewarePipeline;

/**
 * Tests for route scope support:
 * - RouteEntry value object
 * - Router::normalizeRouteConfig()
 * - Router::loadRoutes() with scope-aware formats
 * - Router::scope() for scope middleware
 * - Scope filtering for client generation
 */
class RouteScopeTest extends TestCase
{
    // =========================================================================
    // RouteEntry
    // =========================================================================

    public function test_route_entry_defaults(): void
    {
        $entry = new RouteEntry(handler: 'App\\Routes\\HomeRoute');
        $this->assertEquals('App\\Routes\\HomeRoute', $entry->handler);
        $this->assertEquals('shared', $entry->scope);
        $this->assertFalse($entry->isAlias);
    }

    public function test_route_entry_with_scope(): void
    {
        $entry = new RouteEntry(handler: 'App\\Routes\\DashboardRoute', scope: 'portal');
        $this->assertEquals('portal', $entry->scope);
        $this->assertFalse($entry->isAlias);
    }

    public function test_route_entry_alias(): void
    {
        $entry = new RouteEntry(handler: 'App\\Routes\\DashboardRoute', scope: 'portal', isAlias: true);
        $this->assertTrue($entry->isAlias);
    }

    public function test_route_entry_get_handler_class_string(): void
    {
        $entry = new RouteEntry(handler: 'App\\Routes\\HomeRoute');
        $this->assertEquals('App\\Routes\\HomeRoute', $entry->getHandlerClass());
    }

    // =========================================================================
    // Router::normalizeRouteConfig()
    // =========================================================================

    public function test_normalize_string_handler(): void
    {
        $entry = Router::normalizeRouteConfig('App\\Routes\\HomeRoute');
        $this->assertEquals('App\\Routes\\HomeRoute', $entry->handler);
        $this->assertEquals('shared', $entry->scope);
        $this->assertFalse($entry->isAlias);
    }

    public function test_normalize_array_handler_with_scope(): void
    {
        $entry = Router::normalizeRouteConfig([
            'handler' => 'App\\Routes\\DashboardRoute',
            'scope' => 'portal',
        ]);
        $this->assertEquals('App\\Routes\\DashboardRoute', $entry->handler);
        $this->assertEquals('portal', $entry->scope);
        $this->assertFalse($entry->isAlias);
    }

    public function test_normalize_array_handler_with_alias(): void
    {
        $entry = Router::normalizeRouteConfig([
            'handler' => 'App\\Routes\\DashboardRoute',
            'scope' => 'portal',
            'alias' => true,
        ]);
        $this->assertTrue($entry->isAlias);
    }

    public function test_normalize_array_handler_defaults_to_shared(): void
    {
        $entry = Router::normalizeRouteConfig([
            'handler' => 'App\\Routes\\ProfileRoute',
        ]);
        $this->assertEquals('shared', $entry->scope);
        $this->assertFalse($entry->isAlias);
    }

    // =========================================================================
    // Router::loadRoutes() with mixed formats
    // =========================================================================

    public function test_load_routes_legacy_flat_format(): void
    {
        $router = new Router();
        $router->loadRoutes([
            'GET' => [
                '/health' => 'App\\Routes\\HealthRoute',
                '/dashboard' => 'App\\Routes\\DashboardRoute',
            ],
        ]);

        $meta = $router->getRouteMeta();
        $this->assertCount(2, $meta);
        // All legacy routes default to scope 'shared'
        $this->assertEquals('shared', $meta[0]['scope']);
        $this->assertEquals('shared', $meta[1]['scope']);
    }

    public function test_load_routes_with_scope_in_values(): void
    {
        $router = new Router();
        $router->loadRoutes([
            'GET' => [
                '/health' => 'App\\Routes\\HealthRoute',
                '/portal/dashboard' => ['handler' => 'App\\Routes\\DashboardRoute', 'scope' => 'portal'],
                '/admin/users' => ['handler' => 'App\\Routes\\AdminUsersRoute', 'scope' => 'admin'],
            ],
        ]);

        $meta = $router->getRouteMeta();
        $this->assertCount(3, $meta);

        $byPath = [];
        foreach ($meta as $m) {
            $byPath[$m['path']] = $m;
        }

        $this->assertEquals('shared', $byPath['/health']['scope']);
        $this->assertEquals('portal', $byPath['/portal/dashboard']['scope']);
        $this->assertEquals('admin', $byPath['/admin/users']['scope']);
    }

    public function test_load_routes_with_top_level_scopes_key(): void
    {
        $router = new Router();
        $router->loadRoutes([
            'scopes' => ['portal', 'admin', 'shared'],
            'GET' => [
                '/health' => 'App\\Routes\\HealthRoute',
                '/portal/dashboard' => ['handler' => 'App\\Routes\\DashboardRoute', 'scope' => 'portal'],
            ],
        ]);

        $meta = $router->getRouteMeta();
        $this->assertCount(2, $meta);

        $scopes = $router->getKnownScopes();
        $this->assertContains('portal', $scopes);
        $this->assertContains('admin', $scopes);
        $this->assertContains('shared', $scopes);
    }

    public function test_load_routes_public_protected_format_with_scope(): void
    {
        $router = new Router();
        $router->loadRoutes([
            'public' => [
                'GET' => [
                    '/health' => 'App\\Routes\\HealthRoute',
                    '/portal/status' => ['handler' => 'App\\Routes\\StatusRoute', 'scope' => 'portal'],
                ],
            ],
            'protected' => [
                'GET' => [
                    '/portal/dashboard' => ['handler' => 'App\\Routes\\DashboardRoute', 'scope' => 'portal'],
                    '/admin/users' => ['handler' => 'App\\Routes\\AdminUsersRoute', 'scope' => 'admin'],
                ],
            ],
        ]);

        $meta = $router->getRouteMeta();
        $this->assertCount(4, $meta);

        $byPath = [];
        foreach ($meta as $m) {
            $byPath[$m['path']] = $m;
        }

        // Verify scopes
        $this->assertEquals('shared', $byPath['/health']['scope']);
        $this->assertEquals('portal', $byPath['/portal/status']['scope']);
        $this->assertEquals('portal', $byPath['/portal/dashboard']['scope']);
        $this->assertEquals('admin', $byPath['/admin/users']['scope']);

        // Verify public/protected
        $this->assertTrue($byPath['/health']['is_public']);
        $this->assertTrue($byPath['/portal/status']['is_public']);
        $this->assertFalse($byPath['/portal/dashboard']['is_public']);
        $this->assertFalse($byPath['/admin/users']['is_public']);
    }

    // =========================================================================
    // Router::scope() for middleware grouping
    // =========================================================================

    public function test_scope_method_registers_known_scope(): void
    {
        $router = new Router();
        $router->scope('portal', function($r) {
            // no middleware to add in test
        });

        $this->assertContains('portal', $router->getKnownScopes());
    }

    public function test_scope_middleware_builder(): void
    {
        $pipeline = new MiddlewarePipeline();
        $builder = new ScopeMiddlewareBuilder($pipeline);

        // Should be able to chain use() calls
        $result = $builder->use(new TestMiddleware());
        $this->assertInstanceOf(ScopeMiddlewareBuilder::class, $result);
        $this->assertEquals(1, $pipeline->count());
    }

    // =========================================================================
    // Route meta extraction
    // =========================================================================

    public function test_get_route_meta_includes_all_fields(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/test', 'TestRoute', [], false, 'portal');

        $meta = $router->getRouteMeta();
        $this->assertCount(1, $meta);
        $this->assertEquals('GET', $meta[0]['method']);
        $this->assertEquals('/test', $meta[0]['path']);
        $this->assertEquals('TestRoute', $meta[0]['handler']);
        $this->assertEquals('portal', $meta[0]['scope']);
        $this->assertFalse($meta[0]['is_public']);
    }

    public function test_add_route_with_scope(): void
    {
        $router = new Router();
        $router->get('/portal/invoices', 'InvoicesRoute', [], false, 'portal');
        $router->post('/portal/invoices', 'CreateInvoiceRoute', [], false, 'portal');
        $router->get('/health', 'HealthRoute', [], true, 'shared');

        $meta = $router->getRouteMeta();
        $this->assertCount(3, $meta);

        $scopes = $router->getKnownScopes();
        $this->assertContains('portal', $scopes);
        $this->assertContains('shared', $scopes);
    }
}

/**
 * Minimal test middleware for scope middleware builder tests
 */
class TestMiddleware implements \StoneScriptPHP\Routing\MiddlewareInterface
{
    public function handle(array $request, callable $next): ?\StoneScriptPHP\ApiResponse
    {
        return $next($request);
    }
}
