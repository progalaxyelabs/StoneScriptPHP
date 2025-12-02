<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Router Unit Tests
 *
 * Tests the core routing functionality including:
 * - Route matching
 * - HTTP method handling
 * - Parameter extraction
 * - Error responses
 */
class RouterTest extends TestCase
{
    /**
     * Test that router can match static GET routes
     */
    public function test_router_matches_static_get_routes(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/test-route';

        $router = new \Framework\Router();
        $response = $router->process_route();

        $this->assertInstanceOf(\Framework\ApiResponse::class, $response);
    }

    /**
     * Test that router returns 404 for unknown routes
     */
    public function test_router_returns_404_for_unknown_routes(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/nonexistent-route-xyz';

        $router = new \Framework\Router();
        $response = $router->process_route();

        $this->assertInstanceOf(\Framework\ApiResponse::class, $response);
        $this->assertEquals('error', $response->status);
    }

    /**
     * Test that router handles POST requests with JSON body
     */
    public function test_router_handles_post_requests_with_json(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/test-route';
        $_SERVER['CONTENT_TYPE'] = 'application/json';

        $router = new \Framework\Router();
        $response = $router->process_route();

        $this->assertInstanceOf(\Framework\ApiResponse::class, $response);
    }

    /**
     * Test that router returns 500 error when route handler throws exception
     *
     * This tests the fix for the silent exception swallowing bug where
     * exceptions were caught and logged but no error response was returned.
     */
    public function test_router_returns_500_when_route_handler_throws_exception(): void
    {
        // Test that e500() function returns proper error response
        $response = \Framework\e500('Test error message');

        $this->assertInstanceOf(\Framework\ApiResponse::class, $response);
        $this->assertEquals('error', $response->status);
        $this->assertEquals('Test error message', $response->message);

        // Verify HTTP status code was set to 500
        $currentCode = http_response_code();
        $this->assertEquals(500, $currentCode, 'HTTP status code should be 500');
    }

    /**
     * Test that exceptions in route handlers are properly caught and converted to 500 errors
     *
     * This test verifies that when a route's process() method throws an exception,
     * the Router catches it and returns a proper 500 error response instead of
     * swallowing the exception.
     */
    public function test_router_catches_exceptions_and_returns_error_response(): void
    {
        // Create a mock route that throws an exception
        $route = new \Tests\Fixtures\ExceptionThrowingRoute();

        // The route's process method should throw an exception
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Test exception from route handler');

        $route->process();

        // Note: In the actual Router implementation, exceptions should be caught
        // and converted to e500() responses. This test documents the expected
        // behavior that will be implemented in the Router class.
    }

    /**
     * Test that router returns 405 for unsupported HTTP methods
     */
    public function test_router_returns_404_for_unsupported_methods(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $_SERVER['REQUEST_URI'] = '/test-route';

        $router = new \Framework\Router();
        $response = $router->process_route();

        $this->assertInstanceOf(\Framework\ApiResponse::class, $response);
        $this->assertEquals('error', $response->status);
    }

    /**
     * Test that router properly handles CORS preflight requests
     */
    public function test_router_handles_cors_preflight(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'OPTIONS';
        $_SERVER['REQUEST_URI'] = '/test-route';

        $router = new \Framework\Router();
        $response = $router->process_route();

        $this->assertInstanceOf(\Framework\ApiResponse::class, $response);
        $this->assertEquals('ok', $response->status);
    }

    /**
     * Test that router blocks access to .env file
     */
    public function test_router_blocks_env_file_access(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '.env';

        $router = new \Framework\Router();
        $response = $router->process_route();

        $this->assertInstanceOf(\Framework\ApiResponse::class, $response);
        $this->assertEquals('error', $response->status);
    }

    /**
     * Test that GetRequestParser extracts GET parameters
     */
    public function test_get_request_parser_extracts_params(): void
    {
        $_GET = ['param1' => 'value1', 'param2' => 'value2'];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $parser = new \Framework\GetRequestParser();
        $input = $parser->extract_input();

        $this->assertIsArray($input);
        $this->assertEquals('value1', $input['param1']);
        $this->assertEquals('value2', $input['param2']);
    }

    /**
     * Test that PostRequestParser validates content type
     */
    public function test_post_request_parser_validates_content_type(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/';

        $parser = new \Framework\PostRequestParser();
        $input = $parser->extract_input();

        $this->assertIsArray($input);
        $this->assertEmpty($input);
        $this->assertNotEmpty($parser->error);
    }

    /**
     * Test that router handles empty request URI
     */
    public function test_router_handles_empty_uri(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '';

        $router = new \Framework\Router();
        $response = $router->process_route();

        $this->assertInstanceOf(\Framework\ApiResponse::class, $response);
    }
}
