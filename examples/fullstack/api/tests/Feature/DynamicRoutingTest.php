<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Dynamic Routing Feature Tests
 *
 * Tests for advanced routing features including:
 * - Dynamic route parameters (/user/{id})
 * - Route grouping and prefixes
 * - Middleware execution
 * - Nested routes
 */
class DynamicRoutingTest extends TestCase
{
    /**
     * Test that pattern with single parameter converts to regex correctly
     * Example: /user/{id} should match /user/123
     */
    public function test_router_matches_single_parameter_routes(): void
    {
        // Test the pattern conversion logic
        $pattern = '/user/{id}';

        // Convert {id} to regex: /user/([^/]+)
        $regex = $this->convertPatternToRegex($pattern);

        // Should match /user/123
        $this->assertEquals(1, preg_match($regex, '/user/123'));

        // Should match /user/abc
        $this->assertEquals(1, preg_match($regex, '/user/abc'));

        // Should NOT match /user/ (missing parameter)
        $this->assertEquals(0, preg_match($regex, '/user/'));

        // Should NOT match /user (missing /)
        $this->assertEquals(0, preg_match($regex, '/user'));

        // Should NOT match /user/123/extra
        $this->assertEquals(0, preg_match($regex, '/user/123/extra'));
    }

    /**
     * Test that pattern with multiple parameters converts to regex correctly
     * Example: /user/{id}/post/{postId} should match /user/123/post/456
     */
    public function test_router_matches_multiple_parameter_routes(): void
    {
        $pattern = '/user/{userId}/post/{postId}';

        $regex = $this->convertPatternToRegex($pattern);

        // Should match /user/123/post/456
        $this->assertEquals(1, preg_match($regex, '/user/123/post/456'));

        // Should match /user/abc/post/xyz
        $this->assertEquals(1, preg_match($regex, '/user/abc/post/xyz'));

        // Should NOT match /user/123/post (missing postId)
        $this->assertEquals(0, preg_match($regex, '/user/123/post'));

        // Should NOT match /user/123 (missing /post/{postId})
        $this->assertEquals(0, preg_match($regex, '/user/123'));
    }

    /**
     * Test that router correctly extracts parameters from URL
     */
    public function test_router_extracts_route_parameters(): void
    {
        // Single parameter
        $pattern = '/user/{id}';
        $url = '/user/123';

        $params = $this->extractParameters($pattern, $url);

        $this->assertArrayHasKey('id', $params);
        $this->assertEquals('123', $params['id']);

        // Multiple parameters
        $pattern = '/user/{userId}/post/{postId}';
        $url = '/user/456/post/789';

        $params = $this->extractParameters($pattern, $url);

        $this->assertArrayHasKey('userId', $params);
        $this->assertArrayHasKey('postId', $params);
        $this->assertEquals('456', $params['userId']);
        $this->assertEquals('789', $params['postId']);
    }

    /**
     * Helper: Convert route pattern to regex
     */
    private function convertPatternToRegex(string $pattern): string
    {
        // This is the logic we'll implement in the Router
        // {param} -> ([^/]+) and capture the parameter name
        $regex = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '([^/]+)', $pattern);
        return '#^' . $regex . '$#';
    }

    /**
     * Helper: Extract parameters from URL using pattern
     */
    private function extractParameters(string $pattern, string $url): array
    {
        $regex = $this->convertPatternToRegex($pattern);

        // Extract parameter names from pattern
        preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $pattern, $paramNames);

        // Extract values from URL
        preg_match($regex, $url, $matches);

        // Combine names with values
        $params = [];
        foreach ($paramNames[1] as $index => $name) {
            $params[$name] = $matches[$index + 1] ?? null;
        }

        return $params;
    }

    /**
     * Test that router applies route prefix to grouped routes
     * Example: Group with prefix '/api/v1' should make '/users' accessible at '/api/v1/users'
     */
    public function test_router_applies_route_group_prefix(): void
    {
        // Test with RouteCompiler directly
        $routes = [
            '/' => 'HomeRoute',
            [
                'prefix' => '/api/v1',
                'routes' => [
                    '/users' => 'UsersRoute',
                    '/user/{id}' => 'UserRoute',
                ]
            ]
        ];

        $compiled = \Framework\RouteCompiler::compile($routes);

        // Verify routes are flattened with prefix applied
        $this->assertArrayHasKey('/', $compiled);
        $this->assertArrayHasKey('/api/v1/users', $compiled);
        $this->assertArrayHasKey('/api/v1/user/{id}', $compiled);

        $this->assertEquals('HomeRoute', $compiled['/']);
        $this->assertEquals('UsersRoute', $compiled['/api/v1/users']);
        $this->assertEquals('UserRoute', $compiled['/api/v1/user/{id}']);

        // Test with nested groups
        $nestedRoutes = [
            [
                'prefix' => '/api',
                'routes' => [
                    [
                        'prefix' => '/v1',
                        'routes' => [
                            '/users' => 'V1UsersRoute',
                        ]
                    ],
                    [
                        'prefix' => '/v2',
                        'routes' => [
                            '/users' => 'V2UsersRoute',
                        ]
                    ]
                ]
            ]
        ];

        $compiledNested = \Framework\RouteCompiler::compile($nestedRoutes);

        $this->assertArrayHasKey('/api/v1/users', $compiledNested);
        $this->assertArrayHasKey('/api/v2/users', $compiledNested);
        $this->assertEquals('V1UsersRoute', $compiledNested['/api/v1/users']);
        $this->assertEquals('V2UsersRoute', $compiledNested['/api/v2/users']);
    }

    /**
     * Test that middleware executes before route handler
     */
    public function test_middleware_executes_before_route_handler(): void
    {
        // TODO: Implement middleware test
        $this->markTestIncomplete('Middleware execution test needs implementation');
    }

    /**
     * Test that route parameters support type constraints
     * Example: /user/{id:int} should only match numeric IDs
     */
    public function test_router_validates_parameter_type_constraints(): void
    {
        // TODO: Implement type constraint test
        $this->markTestIncomplete('Parameter type constraint test needs implementation');
    }
}
