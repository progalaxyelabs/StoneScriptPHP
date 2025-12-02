# StoneScriptPHP Middleware System

The StoneScriptPHP framework includes a powerful middleware system for handling cross-cutting concerns like authentication, logging, CORS, and rate limiting.

## Table of Contents

- [Overview](#overview)
- [Built-in Middleware](#built-in-middleware)
- [Usage](#usage)
- [Creating Custom Middleware](#creating-custom-middleware)
- [Middleware Pipeline](#middleware-pipeline)

## Overview

Middleware provides a way to filter HTTP requests entering your application. Each middleware can:
- Inspect the request
- Modify the request data
- Short-circuit the request (return early)
- Pass the request to the next middleware in the chain

## Built-in Middleware

StoneScriptPHP provides several built-in middleware classes:

### 1. CorsMiddleware

Handles Cross-Origin Resource Sharing (CORS) headers.

```php
use Framework\Http\Middleware\CorsMiddleware;

$corsMiddleware = new CorsMiddleware(
    allowedOrigins: ['https://example.com', 'https://app.example.com'],
    allowedMethods: ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
    allowedHeaders: ['Content-Type', 'Authorization'],
    allowCredentials: true,
    maxAge: 900
);
```

**Features:**
- Configurable allowed origins, methods, and headers
- Automatic handling of preflight OPTIONS requests
- Support for credentials
- Configurable cache duration

### 2. AuthMiddleware

Provides authentication based on bearer tokens or custom validation.

```php
use Framework\Http\Middleware\AuthMiddleware;

// With custom validator
$authMiddleware = new AuthMiddleware(
    validator: function($token) {
        // Your token validation logic
        return validateJWT($token);
    },
    excludedPaths: ['/login', '/register', '/public/*'],
    headerName: 'Authorization'
);
```

**Features:**
- Custom token validation
- Path exclusion (with wildcard support)
- Automatic token extraction from headers
- Support for Bearer token format

### 3. LoggingMiddleware

Logs incoming requests and responses.

```php
use Framework\Http\Middleware\LoggingMiddleware;

$loggingMiddleware = new LoggingMiddleware(
    logRequests: true,
    logResponses: true,
    logTiming: true
);
```

**Features:**
- Request logging (method, path, body)
- Response logging
- Request timing measurement
- Configurable log levels

### 4. RateLimitMiddleware

Implements rate limiting to prevent abuse.

```php
use Framework\Http\Middleware\RateLimitMiddleware;

$rateLimitMiddleware = new RateLimitMiddleware(
    maxRequests: 60,
    windowSeconds: 60,
    storageFile: '/tmp/ratelimit.json',
    excludedPaths: ['/health', '/metrics']
);
```

**Features:**
- Configurable request limits
- Time window-based limiting
- Per-IP tracking
- Automatic cleanup of old data
- Standard rate limit headers (X-RateLimit-*)

### 5. JsonBodyParserMiddleware

Parses JSON request bodies.

```php
use Framework\Http\Middleware\JsonBodyParserMiddleware;

$jsonParser = new JsonBodyParserMiddleware(
    strict: true  // Only accept application/json content type
);
```

**Features:**
- Automatic JSON parsing
- Validation of content type
- Error handling for invalid JSON

### 6. SecurityHeadersMiddleware

Adds security headers to responses.

```php
use Framework\Http\Middleware\SecurityHeadersMiddleware;

$securityHeaders = new SecurityHeadersMiddleware([
    'X-Content-Type-Options' => 'nosniff',
    'X-Frame-Options' => 'DENY',
    'X-XSS-Protection' => '1; mode=block',
]);
```

**Features:**
- Common security headers by default
- Customizable headers
- Protection against common vulnerabilities

## Usage

### Using the New Router with Middleware

```php
<?php

require_once 'vendor/autoload.php';

use Framework\Routing\Router;
use Framework\Http\Middleware\CorsMiddleware;
use Framework\Http\Middleware\LoggingMiddleware;
use Framework\Http\Middleware\AuthMiddleware;
use Framework\Http\Middleware\RateLimitMiddleware;

// Create router
$router = new Router();

// Add global middleware (runs on all routes)
$router->use(new LoggingMiddleware())
       ->use(new CorsMiddleware(['https://example.com']))
       ->use(new RateLimitMiddleware(100, 60));

// Add routes with route-specific middleware
$router->get('/public', 'App\\Routes\\PublicRoute');

$router->get('/protected', 'App\\Routes\\ProtectedRoute', [
    new AuthMiddleware(
        validator: fn($token) => validateToken($token),
        excludedPaths: []
    )
]);

$router->post('/api/users', 'App\\Routes\\CreateUserRoute', [
    new AuthMiddleware(),
    new JsonBodyParserMiddleware(strict: true)
]);

// Dispatch the request
$response = $router->dispatch();

// Send response
header('Content-Type: application/json');
echo json_encode($response);
```

### Global vs Route-Specific Middleware

**Global Middleware** runs on every request:
```php
$router->use(new LoggingMiddleware());
$router->use(new CorsMiddleware($allowedOrigins));
```

**Route-Specific Middleware** only runs on specific routes:
```php
$router->get('/admin', 'AdminRoute', [
    new AuthMiddleware($adminValidator)
]);
```

### Middleware Execution Order

Middleware executes in the order it's added:

```php
$router->use(new LoggingMiddleware());      // Runs first
$router->use(new CorsMiddleware($origins)); // Runs second
$router->use(new AuthMiddleware());         // Runs third

$router->get('/api/data', 'DataRoute', [
    new RateLimitMiddleware()               // Runs fourth (route-specific)
]);
```

## Creating Custom Middleware

To create custom middleware, implement the `MiddlewareInterface`:

```php
<?php

namespace App\Middleware;

use Framework\Http\MiddlewareInterface;
use Framework\ApiResponse;

class CustomMiddleware implements MiddlewareInterface
{
    public function handle(array $request, callable $next): ?ApiResponse
    {
        // Before processing
        log_debug('Before request processing');

        // Validate something
        if (!$this->isValid($request)) {
            http_response_code(403);
            return new ApiResponse('error', 'Forbidden');
        }

        // Modify request
        $request['custom_data'] = 'value';

        // Continue to next middleware
        $response = $next($request);

        // After processing
        log_debug('After request processing');

        return $response;
    }

    private function isValid(array $request): bool
    {
        // Your validation logic
        return true;
    }
}
```

### Middleware Best Practices

1. **Keep middleware focused**: Each middleware should handle one concern
2. **Use early returns**: Return error responses early to avoid unnecessary processing
3. **Pass modified request**: Middleware can add data to the request array for downstream use
4. **Don't forget to call $next()**: Always call the next middleware unless you want to short-circuit
5. **Handle errors gracefully**: Use try-catch blocks for error-prone operations

### Example: API Key Middleware

```php
<?php

namespace App\Middleware;

use Framework\Http\MiddlewareInterface;
use Framework\ApiResponse;

class ApiKeyMiddleware implements MiddlewareInterface
{
    private array $validKeys;

    public function __construct(array $validKeys)
    {
        $this->validKeys = $validKeys;
    }

    public function handle(array $request, callable $next): ?ApiResponse
    {
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';

        if (!in_array($apiKey, $this->validKeys)) {
            http_response_code(401);
            return new ApiResponse('error', 'Invalid API key');
        }

        $request['api_key'] = $apiKey;

        return $next($request);
    }
}
```

## Middleware Pipeline

The `MiddlewarePipeline` class handles the execution of middleware chains:

```php
use Framework\Http\MiddlewarePipeline;

$pipeline = new MiddlewarePipeline();
$pipeline->pipe(new LoggingMiddleware())
         ->pipe(new AuthMiddleware())
         ->pipe(new RateLimitMiddleware());

$response = $pipeline->process($request, function($request) {
    // Final handler
    return new ApiResponse('ok', 'Success', ['data' => 'value']);
});
```

## Migration from Legacy Router

If you're using the legacy `Router.php` with `RequestParser` classes, you can migrate to the new middleware-based router:

**Before (Legacy):**
```php
// CORS headers added in RequestParser
protected function add_cors_headers(): void {
    // Manual CORS handling
}
```

**After (With Middleware):**
```php
$router = new Router();
$router->use(new CorsMiddleware($allowedOrigins));
```

The new router is backward compatible with existing route handlers that implement `IRouteHandler`.

## Advanced Usage

### Conditional Middleware

```php
class ConditionalMiddleware implements MiddlewareInterface
{
    public function handle(array $request, callable $next): ?ApiResponse
    {
        if ($request['path'] === '/special') {
            // Special handling
            return new ApiResponse('ok', 'Special path', []);
        }

        return $next($request);
    }
}
```

### Middleware with Dependencies

```php
class DatabaseMiddleware implements MiddlewareInterface
{
    private $db;

    public function __construct($database)
    {
        $this->db = $database;
    }

    public function handle(array $request, callable $next): ?ApiResponse
    {
        // Ensure DB connection is ready
        if (!$this->db->isConnected()) {
            $this->db->connect();
        }

        $request['db'] = $this->db;

        return $next($request);
    }
}
```

## Troubleshooting

### Middleware Not Executing

Ensure middleware is added before calling `dispatch()`:
```php
$router->use($middleware);  // Add middleware first
$router->dispatch();         // Then dispatch
```

### Request Data Not Available in Handler

Make sure middleware passes the modified request to `$next()`:
```php
$request['custom_data'] = 'value';
return $next($request);  // Pass modified request
```

### CORS Issues

Ensure `CorsMiddleware` is one of the first middleware in the pipeline:
```php
$router->use(new CorsMiddleware($origins));  // Add early
```

## Performance Considerations

- Middleware runs on every request, so keep processing lightweight
- Use caching in middleware when possible (e.g., rate limit data)
- Consider excluding paths that don't need certain middleware
- Order middleware from lightest to heaviest processing

## Security Considerations

- Always validate input in middleware
- Use HTTPS in production when using AuthMiddleware
- Store sensitive data (API keys, tokens) securely
- Use rate limiting to prevent abuse
- Add security headers with SecurityHeadersMiddleware

## Summary

The middleware system in StoneScriptPHP provides a clean, extensible way to handle cross-cutting concerns. By composing middleware, you can build robust request handling pipelines without cluttering your route handlers.
