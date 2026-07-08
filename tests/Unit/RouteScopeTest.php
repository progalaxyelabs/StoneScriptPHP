<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Routing\RouteEntry;
use StoneScriptPHP\Routing\Router;
use StoneScriptPHP\Routing\ScopeMiddlewareBuilder;
use StoneScriptPHP\Routing\MiddlewarePipeline;

/**
 * Tests for route service support (v4.0):
 * - RouteEntry value object
 * - Router::normalizeRouteConfig()
 * - Router::loadRoutes() with service-aware formats
 * - Router::scope() for service middleware
 * - Service filtering for client generation
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
        $this->assertEquals('shared', $entry->service);
        $this->assertFalse($entry->isAlias);
    }

    public function test_route_entry_with_service(): void
    {
        $entry = new RouteEntry(handler: 'App\\Routes\\DashboardRoute', service: 'portal');
        $this->assertEquals('portal', $entry->service);
        $this->assertFalse($entry->isAlias);
    }

    public function test_route_entry_alias(): void
    {
        $entry = new RouteEntry(handler: 'App\\Routes\\DashboardRoute', service: 'portal', isAlias: true);
        $this->assertTrue($entry->isAlias);
    }

    public function test_route_entry_get_handler_class_string(): void
    {
        $entry = new RouteEntry(handler: 'App\\Routes\\HomeRoute');
        $this->assertEquals('App\\Routes\\HomeRoute', $entry->getHandlerClass());
    }

    public function test_route_entry_has_no_scope_field(): void
    {
        $entry = new RouteEntry(handler: 'App\\Routes\\HomeRoute', service: 'portal');
        // v4.0: scope field removed — only service exists
        $this->assertFalse(property_exists($entry, 'scope'));
        $this->assertEquals('portal', $entry->service);
    }

    // =========================================================================
    // Router::normalizeRouteConfig()
    // =========================================================================

    public function test_normalize_string_handler(): void
    {
        $entry = Router::normalizeRouteConfig('App\\Routes\\HomeRoute');
        $this->assertEquals('App\\Routes\\HomeRoute', $entry->handler);
        $this->assertEquals('shared', $entry->service);
        $this->assertFalse($entry->isAlias);
    }

    public function test_normalize_array_handler_with_service(): void
    {
        $entry = Router::normalizeRouteConfig([
            'handler' => 'App\\Routes\\DashboardRoute',
            'service' => 'portal',
        ]);
        $this->assertEquals('App\\Routes\\DashboardRoute', $entry->handler);
        $this->assertEquals('portal', $entry->service);
        $this->assertFalse($entry->isAlias);
    }

    public function test_normalize_array_handler_with_alias(): void
    {
        $entry = Router::normalizeRouteConfig([
            'handler' => 'App\\Routes\\DashboardRoute',
            'service' => 'portal',
            'alias' => true,
        ]);
        $this->assertTrue($entry->isAlias);
    }

    public function test_normalize_array_handler_defaults_to_shared(): void
    {
        $entry = Router::normalizeRouteConfig([
            'handler' => 'App\\Routes\\ProfileRoute',
        ]);
        $this->assertEquals('shared', $entry->service);
        $this->assertFalse($entry->isAlias);
    }

    // =========================================================================
    // Router::loadRoutes() with multiple formats
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
        // All legacy routes default to service 'shared'
        $this->assertEquals('shared', $meta[0]['service']);
        $this->assertEquals('shared', $meta[1]['service']);
    }

    public function test_load_routes_with_service_in_values(): void
    {
        $router = new Router();
        $router->loadRoutes([
            'GET' => [
                '/health' => 'App\\Routes\\HealthRoute',
                '/portal/dashboard' => ['handler' => 'App\\Routes\\DashboardRoute', 'service' => 'portal'],
                '/admin/users' => ['handler' => 'App\\Routes\\AdminUsersRoute', 'service' => 'admin'],
            ],
        ]);

        $meta = $router->getRouteMeta();
        $this->assertCount(3, $meta);

        $byPath = [];
        foreach ($meta as $m) {
            $byPath[$m['path']] = $m;
        }

        $this->assertEquals('shared', $byPath['/health']['service']);
        $this->assertEquals('portal', $byPath['/portal/dashboard']['service']);
        $this->assertEquals('admin', $byPath['/admin/users']['service']);
    }

    public function test_route_entry_is_public_default_false(): void
    {
        $entry = new RouteEntry(handler: 'App\\Routes\\HomeRoute');
        $this->assertFalse($entry->isPublic);
    }

    public function test_route_entry_is_public_explicit_true(): void
    {
        $entry = new RouteEntry(handler: 'App\\Routes\\HomeRoute', isPublic: true);
        $this->assertTrue($entry->isPublic);
    }

    public function test_normalize_route_config_reads_is_public_key(): void
    {
        $entry = Router::normalizeRouteConfig(['handler' => 'App\\Routes\\HomeRoute', 'is_public' => true]);
        $this->assertTrue($entry->isPublic);
    }

    public function test_normalize_route_config_is_public_defaults_false_when_absent(): void
    {
        $entry = Router::normalizeRouteConfig(['handler' => 'App\\Routes\\HomeRoute']);
        $this->assertFalse($entry->isPublic);
    }

    /**
     * Regression test (2026-07-08): Format 2 (flat format) previously had NO way
     * to mark an individual route public — loadRoutes() hardcoded isPublic=false
     * for every flat-format route regardless of intent, forcing routes that must
     * work without a pre-existing valid access token (e.g. a token-refresh
     * endpoint validated by its OWN body-supplied refresh token) to incorrectly
     * require one via JwtAuthMiddleware first — an unsatisfiable requirement for
     * their actual purpose. Found live: progalaxy's POST /user/refresh-access.
     */
    public function test_load_routes_flat_format_respects_per_route_is_public(): void
    {
        $router = new Router();
        $router->loadRoutes([
            'POST' => [
                '/user/refresh-access' => ['handler' => 'App\\Routes\\RefreshRoute', 'is_public' => true],
                '/projects/add' => ['handler' => 'App\\Routes\\AddProjectRoute'],
            ],
        ]);

        $meta = $router->getRouteMeta();
        $byPath = [];
        foreach ($meta as $m) {
            $byPath[$m['path']] = $m;
        }

        $this->assertTrue($byPath['/user/refresh-access']['is_public'], 'Explicitly marked public route must be public');
        $this->assertFalse($byPath['/projects/add']['is_public'], 'Route without is_public key must default to protected (backward compat)');
    }

    /**
     * Regression guard (v6.0.0, routing consolidation — see
     * ROUTING-CONSOLIDATION-PLAN.md): the 'public'/'protected' sectioned
     * format is REMOVED, not silently reinterpreted. Before this change,
     * feeding this shape to a flat-format-only loadRoutes() would have
     * silently registered routes under the bogus HTTP methods "PUBLIC"/
     * "PROTECTED" — never matching any real request, with no error at all.
     * loadRoutes() must instead fail loudly and name the migration path.
     */
    public function test_load_routes_rejects_removed_public_protected_format(): void
    {
        $router = new Router();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("'public'/'protected' sectioned route format was removed in v6.0.0");

        $router->loadRoutes([
            'public' => [
                'GET' => ['/health' => 'App\\Routes\\HealthRoute'],
            ],
            'protected' => [
                'GET' => ['/portal/dashboard' => ['handler' => 'App\\Routes\\DashboardRoute', 'service' => 'portal']],
            ],
        ]);
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

    public function test_get_route_meta_includes_all_v4_fields(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/test', 'TestRoute', [], false, null, null, false, null, 'portal');

        $meta = $router->getRouteMeta();
        $this->assertCount(1, $meta);
        $this->assertEquals('GET', $meta[0]['method']);
        $this->assertEquals('/test', $meta[0]['path']);
        $this->assertEquals('TestRoute', $meta[0]['handler']);
        $this->assertEquals('portal', $meta[0]['service']);
        $this->assertFalse($meta[0]['is_public']);
        // v4.0: no 'scope' key in output
        $this->assertArrayNotHasKey('scope', $meta[0]);
    }

    public function test_add_route_with_service(): void
    {
        $router = new Router();
        $router->get('/portal/invoices', 'InvoicesRoute', [], false, null, null, false, null, 'portal');
        $router->post('/portal/invoices', 'CreateInvoiceRoute', [], false, null, null, false, null, 'portal');
        $router->get('/health', 'HealthRoute', [], true, null, null, false, null, 'shared');

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
