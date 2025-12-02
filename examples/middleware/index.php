<?php

/**
 * StoneScriptPHP Middleware Example
 *
 * This example demonstrates how to use the middleware system
 * with various built-in middleware classes.
 */

// Setup paths
define('ROOT_PATH', realpath(__DIR__ . '/../..') . DIRECTORY_SEPARATOR);
define('SRC_PATH', ROOT_PATH . 'src' . DIRECTORY_SEPARATOR);

// Simple autoloader
spl_autoload_register(function ($class) {
    $path = SRC_PATH . str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
    if (file_exists($path)) {
        require_once $path;
    }
});

// Define DEBUG_MODE for error handlers
define('DEBUG_MODE', true);

// Simple logging function
function log_debug($message) {
    error_log("[DEBUG] $message");
}

use Framework\Routing\Router;
use Framework\Http\Middleware\CorsMiddleware;
use Framework\Http\Middleware\LoggingMiddleware;
use Framework\Http\Middleware\AuthMiddleware;
use Framework\Http\Middleware\RateLimitMiddleware;
use Framework\Http\Middleware\SecurityHeadersMiddleware;
use Framework\Http\Middleware\JsonBodyParserMiddleware;
use Framework\ApiResponse;
use Framework\IRouteHandler;

// Example route handlers
class PublicRoute implements IRouteHandler
{
    public function validation_rules(): array
    {
        return [];
    }

    public function process(): ApiResponse
    {
        return new ApiResponse('ok', 'Public endpoint - no auth required', [
            'timestamp' => time(),
            'message' => 'This is a public endpoint'
        ]);
    }
}

class ProtectedRoute implements IRouteHandler
{
    public function validation_rules(): array
    {
        return [];
    }

    public function process(): ApiResponse
    {
        return new ApiResponse('ok', 'Protected endpoint - auth required', [
            'timestamp' => time(),
            'message' => 'You are authenticated!',
            'user' => 'authenticated_user'
        ]);
    }
}

class UserDataRoute implements IRouteHandler
{
    public $userId;

    public function validation_rules(): array
    {
        return [];
    }

    public function process(): ApiResponse
    {
        return new ApiResponse('ok', 'User data retrieved', [
            'userId' => $this->userId,
            'name' => 'John Doe',
            'email' => 'john@example.com'
        ]);
    }
}

class CreateDataRoute implements IRouteHandler
{
    public $name;
    public $email;

    public function validation_rules(): array
    {
        return [];
    }

    public function process(): ApiResponse
    {
        return new ApiResponse('ok', 'Data created successfully', [
            'id' => uniqid(),
            'name' => $this->name,
            'email' => $this->email,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }
}

// Custom token validator
function validateToken($token): bool
{
    // In production, validate against your token store/database
    // For demo purposes, accept a simple token
    return $token === 'demo-token-12345';
}

// Create router instance
$router = new Router();

// Add global middleware (runs on all routes)
$router->use(new SecurityHeadersMiddleware())
       ->use(new LoggingMiddleware(
           logRequests: true,
           logResponses: true,
           logTiming: true
       ))
       ->use(new CorsMiddleware(
           allowedOrigins: ['http://localhost:3000', 'https://example.com'],
           allowedMethods: ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
           allowedHeaders: ['Content-Type', 'Authorization'],
           allowCredentials: true
       ))
       ->use(new RateLimitMiddleware(
           maxRequests: 100,
           windowSeconds: 60,
           excludedPaths: ['/health', '/public']
       ));

// Public routes (no auth required)
$router->get('/public', PublicRoute::class);
$router->get('/health', PublicRoute::class);

// Protected routes (auth required)
$router->get('/protected', ProtectedRoute::class, [
    new AuthMiddleware(
        validator: fn($token) => validateToken($token),
        excludedPaths: []
    )
]);

// Route with URL parameters
$router->get('/users/:userId', UserDataRoute::class, [
    new AuthMiddleware(fn($token) => validateToken($token))
]);

// POST route with JSON body parsing
$router->post('/data', CreateDataRoute::class, [
    new AuthMiddleware(fn($token) => validateToken($token)),
    new JsonBodyParserMiddleware(strict: true)
]);

// Dispatch the request
try {
    $response = $router->dispatch();

    // Send response
    header('Content-Type: application/json');
    http_response_code(200);
    echo json_encode($response, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => DEBUG_MODE ? $e->getMessage() : 'Internal server error'
    ], JSON_PRETTY_PRINT);
}
