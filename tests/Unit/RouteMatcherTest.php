<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Framework\RouteMatcher;

/**
 * RouteMatcher Unit Tests
 *
 * Tests for the RouteMatcher class that handles dynamic route matching
 */
class RouteMatcherTest extends TestCase
{
    /**
     * Test exact match for static routes (performance optimization)
     */
    public function test_matches_static_routes_exactly(): void
    {
        $routes = [
            '/' => 'HomeRoute',
            '/about' => 'AboutRoute',
            '/contact' => 'ContactRoute',
        ];

        $result = RouteMatcher::match($routes, '/');
        $this->assertEquals('HomeRoute', $result['handler']);
        $this->assertEmpty($result['params']);

        $result = RouteMatcher::match($routes, '/about');
        $this->assertEquals('AboutRoute', $result['handler']);
        $this->assertEmpty($result['params']);
    }

    /**
     * Test single parameter matching
     */
    public function test_matches_single_parameter_route(): void
    {
        $routes = [
            '/user/{id}' => 'UserRoute',
        ];

        $result = RouteMatcher::match($routes, '/user/123');

        $this->assertEquals('UserRoute', $result['handler']);
        $this->assertArrayHasKey('id', $result['params']);
        $this->assertEquals('123', $result['params']['id']);
    }

    /**
     * Test multiple parameter matching
     */
    public function test_matches_multiple_parameter_route(): void
    {
        $routes = [
            '/user/{userId}/post/{postId}' => 'PostRoute',
        ];

        $result = RouteMatcher::match($routes, '/user/456/post/789');

        $this->assertEquals('PostRoute', $result['handler']);
        $this->assertArrayHasKey('userId', $result['params']);
        $this->assertArrayHasKey('postId', $result['params']);
        $this->assertEquals('456', $result['params']['userId']);
        $this->assertEquals('789', $result['params']['postId']);
    }

    /**
     * Test that no match returns null handler
     */
    public function test_returns_null_when_no_match(): void
    {
        $routes = [
            '/user/{id}' => 'UserRoute',
        ];

        $result = RouteMatcher::match($routes, '/product/123');

        $this->assertNull($result['handler']);
        $this->assertEmpty($result['params']);
    }

    /**
     * Test pattern to regex conversion
     */
    public function test_converts_pattern_to_regex(): void
    {
        $regex = RouteMatcher::convertPatternToRegex('/user/{id}');

        // Should match /user/123
        $this->assertEquals(1, preg_match($regex, '/user/123'));

        // Should NOT match /user/123/extra
        $this->assertEquals(0, preg_match($regex, '/user/123/extra'));

        // Should NOT match /user/
        $this->assertEquals(0, preg_match($regex, '/user/'));
    }

    /**
     * Test isDynamic helper
     */
    public function test_is_dynamic_detects_pattern(): void
    {
        $this->assertTrue(RouteMatcher::isDynamic('/user/{id}'));
        $this->assertTrue(RouteMatcher::isDynamic('/post/{slug}/comment/{id}'));
        $this->assertFalse(RouteMatcher::isDynamic('/about'));
        $this->assertFalse(RouteMatcher::isDynamic('/contact'));
    }

    /**
     * Test mixed static and dynamic routes
     */
    public function test_matches_mixed_static_and_dynamic_routes(): void
    {
        $routes = [
            '/users' => 'UsersListRoute',
            '/user/{id}' => 'UserRoute',
            '/user/{id}/edit' => 'UserEditRoute',
        ];

        // Static route
        $result = RouteMatcher::match($routes, '/users');
        $this->assertEquals('UsersListRoute', $result['handler']);

        // Dynamic route
        $result = RouteMatcher::match($routes, '/user/123');
        $this->assertEquals('UserRoute', $result['handler']);
        $this->assertEquals('123', $result['params']['id']);

        // Dynamic route with more segments
        $result = RouteMatcher::match($routes, '/user/456/edit');
        $this->assertEquals('UserEditRoute', $result['handler']);
        $this->assertEquals('456', $result['params']['id']);
    }

    /**
     * Test parameter names with underscores and numbers
     */
    public function test_matches_complex_parameter_names(): void
    {
        $routes = [
            '/api/{api_version}/resource/{resource_id2}' => 'ApiRoute',
        ];

        $result = RouteMatcher::match($routes, '/api/v2/resource/abc123');

        $this->assertEquals('ApiRoute', $result['handler']);
        $this->assertEquals('v2', $result['params']['api_version']);
        $this->assertEquals('abc123', $result['params']['resource_id2']);
    }
}
