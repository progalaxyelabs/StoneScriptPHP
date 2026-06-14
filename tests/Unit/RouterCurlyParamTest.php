<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Routing\Router;

/**
 * Router {curly} parameter matching (v4.0.1).
 *
 * Regression guard for the journey-seam bug that shipped in v4.0.0: the client
 * generator (CLIENT-SDK-SPEC §0) emits {curly} placeholders, but the ACTIVE
 * runtime Router (StoneScriptPHP\Routing\Router) only matched legacy ":colon"
 * params — so generated tenant-scoped URLs (/portal/tenant/{tenantId}/...)
 * never matched at runtime. These tests pin the runtime side of the contract:
 *   - {curly} tenant-scoped routes MATCH and extract named params end-to-end.
 *   - ":colon" is NO LONGER a parameter syntax (curly-only, no transitional dual-support).
 */
class RouterCurlyParamTest extends TestCase
{
    /**
     * Drive the private matchRoute() the way dispatch() does, without booting
     * the full request lifecycle (handlers are returned, not executed).
     *
     * @return array{handler:mixed,params:array,pattern:string}|null
     */
    private function match(array $routesByMethod, string $method, string $path): ?array
    {
        $router = new Router();

        $ref = new \ReflectionClass($router);
        $routesProp = $ref->getProperty('routes');
        $routesProp->setAccessible(true);
        $routesProp->setValue($router, $routesByMethod);

        $matchRoute = $ref->getMethod('matchRoute');
        $matchRoute->setAccessible(true);

        return $matchRoute->invoke($router, $method, $path);
    }

    public function test_curly_tenant_scoped_route_matches_and_extracts_params(): void
    {
        $routes = ['GET' => ['/portal/tenant/{tenantId}/states/{id}' => 'StatesByIdRoute']];

        $match = $this->match($routes, 'GET', '/portal/tenant/123/states/9');

        $this->assertNotNull($match, '{curly} tenant-scoped route must match at runtime');
        $this->assertSame('StatesByIdRoute', $match['handler']);
        $this->assertSame('123', $match['params']['tenantId']);
        $this->assertSame('9', $match['params']['id']);
    }

    public function test_curly_single_param_matches(): void
    {
        $routes = ['GET' => ['/portal/tenant/{tenantId}/profile' => 'ProfileRoute']];

        $match = $this->match($routes, 'GET', '/portal/tenant/abc/profile');

        $this->assertNotNull($match);
        $this->assertSame('abc', $match['params']['tenantId']);
    }

    public function test_curly_route_does_not_match_when_segment_missing(): void
    {
        $routes = ['GET' => ['/portal/tenant/{tenantId}/states/{id}' => 'StatesByIdRoute']];

        // Missing the trailing /{id} segment must not match.
        $this->assertNull($this->match($routes, 'GET', '/portal/tenant/123/states'));
        // Extra trailing segment must not match either.
        $this->assertNull($this->match($routes, 'GET', '/portal/tenant/123/states/9/extra'));
    }

    public function test_colon_syntax_is_no_longer_a_parameter(): void
    {
        // v4.0.1 is {curly}-ONLY. A legacy ":colon" pattern is treated as a
        // literal path segment, so it must NOT match a real id value.
        $routes = ['GET' => ['/portal/tenant/:tenantId/profile' => 'ProfileRoute']];

        $this->assertNull(
            $this->match($routes, 'GET', '/portal/tenant/123/profile'),
            ':colon must no longer behave as a parameter (curly-only conformance)'
        );

        // It still matches itself literally (proves it became an inert literal,
        // not a silently-broken capture).
        $literal = $this->match($routes, 'GET', '/portal/tenant/:tenantId/profile');
        $this->assertNotNull($literal);
        $this->assertSame([], $literal['params']);
    }

    public function test_static_route_still_matches_exactly(): void
    {
        $routes = ['GET' => ['/health' => 'HealthRoute']];

        $match = $this->match($routes, 'GET', '/health');

        $this->assertNotNull($match);
        $this->assertSame('HealthRoute', $match['handler']);
        $this->assertSame([], $match['params']);
    }
}
