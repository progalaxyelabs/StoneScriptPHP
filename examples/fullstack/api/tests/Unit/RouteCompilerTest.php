<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Framework\RouteCompiler;

/**
 * RouteCompiler Tests
 *
 * Tests for compiling grouped/nested route structures into flat arrays
 */
class RouteCompilerTest extends TestCase
{
    /**
     * Test that simple routes (no groups) pass through unchanged
     */
    public function test_compiles_simple_routes_without_groups(): void
    {
        $routes = [
            '/' => 'HomeRoute',
            '/about' => 'AboutRoute',
            '/contact' => 'ContactRoute',
        ];

        $compiled = RouteCompiler::compile($routes);

        $this->assertCount(3, $compiled);
        $this->assertEquals('HomeRoute', $compiled['/']);
        $this->assertEquals('AboutRoute', $compiled['/about']);
        $this->assertEquals('ContactRoute', $compiled['/contact']);
    }

    /**
     * Test single-level group with prefix
     */
    public function test_compiles_single_level_group_with_prefix(): void
    {
        $routes = [
            '/' => 'HomeRoute',
            [
                'prefix' => '/api/v1',
                'routes' => [
                    '/users' => 'UsersRoute',
                    '/posts' => 'PostsRoute',
                ]
            ]
        ];

        $compiled = RouteCompiler::compile($routes);

        $this->assertCount(3, $compiled);
        $this->assertEquals('HomeRoute', $compiled['/']);
        $this->assertEquals('UsersRoute', $compiled['/api/v1/users']);
        $this->assertEquals('PostsRoute', $compiled['/api/v1/posts']);
    }

    /**
     * Test group with dynamic route parameters
     */
    public function test_compiles_group_with_dynamic_parameters(): void
    {
        $routes = [
            [
                'prefix' => '/api/v1',
                'routes' => [
                    '/user/{id}' => 'UserRoute',
                    '/post/{postId}/comment/{commentId}' => 'CommentRoute',
                ]
            ]
        ];

        $compiled = RouteCompiler::compile($routes);

        $this->assertEquals('UserRoute', $compiled['/api/v1/user/{id}']);
        $this->assertEquals('CommentRoute', $compiled['/api/v1/post/{postId}/comment/{commentId}']);
    }

    /**
     * Test nested groups (groups within groups)
     */
    public function test_compiles_nested_groups(): void
    {
        $routes = [
            '/' => 'HomeRoute',
            [
                'prefix' => '/admin',
                'routes' => [
                    '/dashboard' => 'DashboardRoute',
                    [
                        'prefix' => '/settings',
                        'routes' => [
                            '/general' => 'GeneralSettingsRoute',
                            '/security' => 'SecuritySettingsRoute',
                        ]
                    ]
                ]
            ]
        ];

        $compiled = RouteCompiler::compile($routes);

        $this->assertEquals('HomeRoute', $compiled['/']);
        $this->assertEquals('DashboardRoute', $compiled['/admin/dashboard']);
        $this->assertEquals('GeneralSettingsRoute', $compiled['/admin/settings/general']);
        $this->assertEquals('SecuritySettingsRoute', $compiled['/admin/settings/security']);
    }

    /**
     * Test multiple groups at same level
     */
    public function test_compiles_multiple_groups_at_same_level(): void
    {
        $routes = [
            [
                'prefix' => '/api/v1',
                'routes' => [
                    '/users' => 'V1UsersRoute',
                ]
            ],
            [
                'prefix' => '/api/v2',
                'routes' => [
                    '/users' => 'V2UsersRoute',
                ]
            ]
        ];

        $compiled = RouteCompiler::compile($routes);

        $this->assertEquals('V1UsersRoute', $compiled['/api/v1/users']);
        $this->assertEquals('V2UsersRoute', $compiled['/api/v2/users']);
    }

    /**
     * Test mixed: simple routes + groups + nested groups
     */
    public function test_compiles_complex_mixed_structure(): void
    {
        $routes = [
            '/' => 'HomeRoute',
            '/about' => 'AboutRoute',

            [
                'prefix' => '/api/v1',
                'routes' => [
                    '/health' => 'HealthRoute',
                    '/user/{id}' => 'UserRoute',

                    [
                        'prefix' => '/admin',
                        'routes' => [
                            '/users' => 'AdminUsersRoute',
                        ]
                    ]
                ]
            ],

            '/contact' => 'ContactRoute',
        ];

        $compiled = RouteCompiler::compile($routes);

        $this->assertEquals('HomeRoute', $compiled['/']);
        $this->assertEquals('AboutRoute', $compiled['/about']);
        $this->assertEquals('HealthRoute', $compiled['/api/v1/health']);
        $this->assertEquals('UserRoute', $compiled['/api/v1/user/{id}']);
        $this->assertEquals('AdminUsersRoute', $compiled['/api/v1/admin/users']);
        $this->assertEquals('ContactRoute', $compiled['/contact']);
    }

    /**
     * Test that prefix normalization works (handles missing/extra slashes)
     */
    public function test_normalizes_prefix_slashes(): void
    {
        $routes = [
            [
                'prefix' => 'api/v1',  // Missing leading slash
                'routes' => [
                    'users' => 'UsersRoute',  // Missing leading slash
                ]
            ]
        ];

        $compiled = RouteCompiler::compile($routes);

        // Should normalize to /api/v1/users
        $this->assertEquals('UsersRoute', $compiled['/api/v1/users']);
    }

    /**
     * Test empty prefix (group without prefix acts as organization only)
     */
    public function test_handles_empty_prefix(): void
    {
        $routes = [
            [
                'prefix' => '',
                'routes' => [
                    '/users' => 'UsersRoute',
                    '/posts' => 'PostsRoute',
                ]
            ]
        ];

        $compiled = RouteCompiler::compile($routes);

        $this->assertEquals('UsersRoute', $compiled['/users']);
        $this->assertEquals('PostsRoute', $compiled['/posts']);
    }

    /**
     * Test that compiler preserves array structure for other HTTP methods
     */
    public function test_compiles_per_http_method(): void
    {
        // This test verifies the compiler works with individual method arrays
        // The Router will call compile() separately for each method (GET, POST, etc.)

        $getRoutes = [
            '/' => 'HomeRoute',
            [
                'prefix' => '/api',
                'routes' => [
                    '/users' => 'GetUsersRoute',
                ]
            ]
        ];

        $postRoutes = [
            [
                'prefix' => '/api',
                'routes' => [
                    '/users' => 'CreateUserRoute',
                ]
            ]
        ];

        $compiledGet = RouteCompiler::compile($getRoutes);
        $compiledPost = RouteCompiler::compile($postRoutes);

        $this->assertEquals('GetUsersRoute', $compiledGet['/api/users']);
        $this->assertEquals('CreateUserRoute', $compiledPost['/api/users']);
    }
}
