<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Routing\RouteAccess;
use StoneScriptPHP\Routing\RouteEntry;
use StoneScriptPHP\Routing\Router;

/**
 * Route access model — `access` = public|authentication|authorization plus a
 * `token_type` = access|refresh dimension, superseding the `is_public` boolean
 * while keeping it as a back-compat shim.
 *
 * @covers \StoneScriptPHP\Routing\RouteEntry
 * @covers \StoneScriptPHP\Routing\RouteAccess
 * @covers \StoneScriptPHP\Routing\Router
 */
class RouteAccessModelTest extends TestCase
{
    public function test_is_public_true_derives_public_access(): void
    {
        $entry = new RouteEntry(handler: 'H', isPublic: true);
        $this->assertSame(RouteAccess::PUBLIC, $entry->resolvedAccess());
        $this->assertTrue($entry->isPublicAccess());
    }

    public function test_protected_default_derives_authorization_access(): void
    {
        $entry = new RouteEntry(handler: 'H'); // not public, no explicit access
        $this->assertSame(RouteAccess::AUTHORIZATION, $entry->resolvedAccess());
        $this->assertFalse($entry->isPublicAccess());
    }

    public function test_explicit_access_wins_over_is_public(): void
    {
        $entry = new RouteEntry(handler: 'H', access: RouteAccess::AUTHENTICATION);
        $this->assertSame(RouteAccess::AUTHENTICATION, $entry->resolvedAccess());
    }

    public function test_invalid_access_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RouteEntry(handler: 'H', access: 'superuser');
    }

    public function test_invalid_token_type_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RouteEntry(handler: 'H', tokenType: 'bearer');
    }

    public function test_token_type_defaults_to_access(): void
    {
        $entry = new RouteEntry(handler: 'H');
        $this->assertSame(RouteAccess::TOKEN_ACCESS, $entry->tokenType);
    }

    public function test_router_exposes_access_and_token_type_in_route_meta(): void
    {
        $router = new Router();
        $router->loadRoutes([
            'POST' => [
                '/api/auth/refresh' => [
                    'handler'    => 'RefreshHandler',
                    'access'     => RouteAccess::AUTHORIZATION,
                    'token_type' => RouteAccess::TOKEN_REFRESH,
                ],
                '/api/auth/exchange' => [
                    'handler' => 'ExchangeHandler',
                    'access'  => RouteAccess::AUTHENTICATION,
                ],
            ],
            'GET' => [
                '/health' => ['handler' => 'HealthHandler', 'is_public' => true],
            ],
        ]);

        $routes = [];
        foreach ($router->getRouteMeta() as $r) {
            $routes[$r['method'] . ' ' . $r['path']] = $r;
        }

        $refresh = $routes['POST /api/auth/refresh'];
        $this->assertSame(RouteAccess::AUTHORIZATION, $refresh['access']);
        $this->assertSame(RouteAccess::TOKEN_REFRESH, $refresh['token_type']);
        $this->assertFalse($refresh['is_public']);

        $exchange = $routes['POST /api/auth/exchange'];
        $this->assertSame(RouteAccess::AUTHENTICATION, $exchange['access']);
        $this->assertSame(RouteAccess::TOKEN_ACCESS, $exchange['token_type']);

        // Back-compat: is_public=true still yields public access.
        $health = $routes['GET /health'];
        $this->assertTrue($health['is_public']);
        $this->assertSame(RouteAccess::PUBLIC, $health['access']);
    }

    public function test_router_rejects_invalid_access_on_registration(): void
    {
        $router = new Router();
        $this->expectException(\InvalidArgumentException::class);
        $router->addRoute('GET', '/x', 'H', [], false, null, null, false, null, null, null, false, 'nope');
    }
}
