<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Route Generator Namespace Consistency Tests
 *
 * Tests to ensure route generator creates routes with consistent namespaces
 * that match the autoloader configuration and existing route conventions.
 */
class RouteGeneratorTest extends TestCase
{
    private string $testRoutesDir;
    private string $generatorScript;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generatorScript = ROOT_PATH . 'Framework/cli/generate-route.php';
        $this->testRoutesDir = ROOT_PATH . 'Routes';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Clean up any test routes created
        if (is_dir($this->testRoutesDir)) {
            $files = glob($this->testRoutesDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (count(scandir($this->testRoutesDir)) === 2) {
                rmdir($this->testRoutesDir);
            }
        }

        // Clean up test routes in correct location
        $correctTestDir = SRC_PATH . 'App/Routes/TestRoute.php';
        if (file_exists($correctTestDir)) {
            unlink($correctTestDir);
        }
    }

    /**
     * Test that existing routes use App\Routes namespace
     *
     * This establishes the expected namespace convention for all routes.
     */
    public function test_existing_routes_use_app_routes_namespace(): void
    {
        $homeRoutePath = SRC_PATH . 'App/Routes/HomeRoute.php';

        $this->assertFileExists($homeRoutePath, 'HomeRoute.php should exist');

        $content = file_get_contents($homeRoutePath);

        // Check for correct namespace
        $this->assertStringContainsString(
            'namespace App\Routes;',
            $content,
            'HomeRoute should use App\Routes namespace'
        );

        // Verify it doesn't use wrong namespace
        $this->assertStringNotContainsString(
            'namespace Routes;',
            $content,
            'HomeRoute should NOT use Routes namespace without App prefix'
        );
    }

    /**
     * Test that routes config expects App\Routes namespace
     */
    public function test_routes_config_uses_app_routes_namespace(): void
    {
        $routesConfigPath = CONFIG_PATH . 'routes.php';

        $this->assertFileExists($routesConfigPath);

        $content = file_get_contents($routesConfigPath);

        // Check that routes are imported with App\Routes namespace
        $this->assertStringContainsString(
            'use App\Routes\HomeRoute;',
            $content,
            'Routes config should import routes from App\Routes namespace'
        );
    }

    /**
     * Test that autoloader can load App\Routes classes
     *
     * This verifies the autoloader is configured to handle App\Routes namespace.
     */
    public function test_autoloader_loads_app_routes_namespace(): void
    {
        // Test that autoloader can load the HomeRoute class
        $this->assertTrue(
            class_exists('App\Routes\HomeRoute'),
            'Autoloader should be able to load App\Routes\HomeRoute'
        );

        // Verify the class is from the correct namespace
        $reflection = new \ReflectionClass('App\Routes\HomeRoute');
        $this->assertEquals(
            'App\Routes',
            $reflection->getNamespaceName(),
            'HomeRoute should be in App\Routes namespace'
        );
    }

    /**
     * Test that autoloader maps App\Routes to src/App/Routes directory
     */
    public function test_autoloader_maps_app_routes_to_correct_directory(): void
    {
        // The autoloader should map App\Routes\HomeRoute to src/App/Routes/HomeRoute.php
        $expectedPath = SRC_PATH . 'App/Routes/HomeRoute.php';

        $reflection = new \ReflectionClass('App\Routes\HomeRoute');
        $actualPath = $reflection->getFileName();

        $this->assertEquals(
            $expectedPath,
            $actualPath,
            'App\Routes\HomeRoute should be loaded from src/App/Routes/HomeRoute.php'
        );
    }

    /**
     * Test that route generator creates files with correct namespace
     *
     * This is the critical test that verifies the route generator bug.
     */
    public function test_route_generator_creates_correct_namespace(): void
    {
        // Read the route generator template
        $generatorContent = file_get_contents($this->generatorScript);

        // Check what namespace the generator uses
        preg_match('/namespace\s+([^;]+);/', $generatorContent, $matches);

        $this->assertNotEmpty($matches, 'Generator should define a namespace');

        $generatedNamespace = $matches[1];

        // The generator should use App\Routes, not just Routes
        $this->assertEquals(
            'App\Routes',
            $generatedNamespace,
            'Route generator should create routes with App\Routes namespace, not Routes'
        );
    }

    /**
     * Test that route generator creates files in correct directory
     */
    public function test_route_generator_uses_correct_directory(): void
    {
        // Read the route generator to check what directory it uses
        $generatorContent = file_get_contents($this->generatorScript);

        // The generator should create routes in src/App/Routes, not Routes
        $expectedDir = SRC_PATH . 'App/Routes';

        // Check if generator references the correct directory
        // We expect it to NOT use 'ROOT_PATH . 'Routes'' pattern
        $this->assertStringNotContainsString(
            "ROOT_PATH . 'Routes'",
            $generatorContent,
            'Generator should not use ROOT_PATH . Routes directory'
        );

        // Should use SRC_PATH with App/Routes pattern
        $this->assertStringContainsString(
            "SRC_PATH . 'App'",
            $generatorContent,
            'Generator should use SRC_PATH . \'App\' pattern for directory'
        );
    }

    /**
     * Test that route generator uses correct ApiResponse import
     */
    public function test_route_generator_uses_correct_imports(): void
    {
        $generatorContent = file_get_contents($this->generatorScript);

        // Should use Framework\ApiResponse
        $this->assertStringContainsString(
            'Framework\ApiResponse',
            $generatorContent,
            'Generator should import Framework\ApiResponse'
        );

        // Should NOT use Models\ApiResponse (which doesn't exist)
        $this->assertStringNotContainsString(
            'Models\ApiResponse',
            $generatorContent,
            'Generator should NOT import Models\ApiResponse (incorrect namespace)'
        );
    }

    /**
     * Test that Framework\ApiResponse exists and Models\ApiResponse doesn't
     */
    public function test_api_response_is_in_framework_namespace(): void
    {
        // Verify Framework\ApiResponse exists
        $this->assertTrue(
            class_exists('Framework\ApiResponse'),
            'Framework\ApiResponse should exist'
        );

        // Verify Models\ApiResponse does NOT exist
        $this->assertFalse(
            class_exists('Models\ApiResponse'),
            'Models\ApiResponse should NOT exist (incorrect namespace)'
        );
    }
}
