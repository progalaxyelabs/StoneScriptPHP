# Middleware System Implementation

This document describes the middleware system implementation for StoneScriptPHP framework.

## Overview

A complete middleware system has been added to StoneScriptPHP, providing a clean and extensible way to handle cross-cutting concerns like authentication, logging, CORS, and rate limiting.

## What Was Implemented

### 1. Core Middleware Infrastructure

**Location:** `/src/Framework/Http/`

- **MiddlewareInterface.php** - Interface that all middleware must implement
- **MiddlewarePipeline.php** - Pipeline class for chaining and executing middleware

### 2. Built-in Middleware Classes

**Location:** `/src/Framework/Http/Middleware/`

1. **CorsMiddleware.php** - Handles CORS headers and preflight requests
2. **AuthMiddleware.php** - Token-based authentication with path exclusions
3. **LoggingMiddleware.php** - Request/response logging with timing
4. **RateLimitMiddleware.php** - IP-based rate limiting with configurable windows
5. **JsonBodyParserMiddleware.php** - Automatic JSON body parsing and validation
6. **SecurityHeadersMiddleware.php** - Adds common security headers

### 3. Enhanced Router with Middleware Support

**Location:** `/src/Framework/Routing/Router.php`

- New Router class with full middleware support
- Global middleware (runs on all routes)
- Route-specific middleware
- URL parameter extraction (e.g., `/users/:id`)
- Compatible with existing `IRouteHandler` interface

### 4. Supporting Classes

**Location:** `/src/Framework/`

- **ApiResponse.php** - Standard API response class
- **IRouteHandler.php** - Interface for route handlers

### 5. Documentation

**Location:** `/docs/MIDDLEWARE.md`

Comprehensive documentation covering:
- Overview of middleware system
- Detailed description of each built-in middleware
- Usage examples (global and route-specific)
- Creating custom middleware
- Best practices and troubleshooting

### 6. Examples

**Location:** `/examples/middleware/`

- **index.php** - Complete working example with all middleware
- **README.md** - Instructions for testing the example

Demonstrates:
- Global middleware setup
- Route-specific middleware
- Protected and public routes
- URL parameters
- JSON body parsing
- Authentication flow

### 7. Tests

**Location:** `/tests/middleware-test.php`

Test suite covering:
- MiddlewarePipeline creation and execution
- Middleware ordering
- Short-circuiting
- Request modification
- AuthMiddleware behavior
- All tests passing (10/10)

## File Structure

```
StoneScriptPHP/
├── src/
│   └── Framework/
│       ├── ApiResponse.php
│       ├── IRouteHandler.php
│       ├── Http/
│       │   ├── MiddlewareInterface.php
│       │   ├── MiddlewarePipeline.php
│       │   └── Middleware/
│       │       ├── AuthMiddleware.php
│       │       ├── CorsMiddleware.php
│       │       ├── JsonBodyParserMiddleware.php
│       │       ├── LoggingMiddleware.php
│       │       ├── RateLimitMiddleware.php
│       │       └── SecurityHeadersMiddleware.php
│       └── Routing/
│           └── Router.php
├── docs/
│   └── MIDDLEWARE.md
├── examples/
│   └── middleware/
│       ├── index.php
│       └── README.md
└── tests/
    └── middleware-test.php
```

## Key Features

### 1. Middleware Pipeline

```php
$pipeline = new MiddlewarePipeline();
$pipeline->pipe(new LoggingMiddleware())
         ->pipe(new AuthMiddleware())
         ->pipe(new RateLimitMiddleware());

$response = $pipeline->process($request, $finalHandler);
```

### 2. Global Middleware

Runs on every request:

```php
$router = new Router();
$router->use(new LoggingMiddleware())
       ->use(new CorsMiddleware($origins));
```

### 3. Route-Specific Middleware

Runs only on specific routes:

```php
$router->get('/protected', 'ProtectedRoute', [
    new AuthMiddleware($validator)
]);
```

### 4. Middleware Execution Order

Middleware executes in the order added:
1. Global middleware (in order)
2. Route-specific middleware (in order)
3. Route handler

### 5. Short-Circuiting

Middleware can return early to block requests:

```php
public function handle(array $request, callable $next): ?ApiResponse
{
    if (!$this->isValid($request)) {
        return new ApiResponse('error', 'Forbidden');
    }
    return $next($request);
}
```

## Usage Example

```php
use Framework\Routing\Router;
use Framework\Http\Middleware\CorsMiddleware;
use Framework\Http\Middleware\AuthMiddleware;

$router = new Router();

// Global middleware
$router->use(new CorsMiddleware(['https://example.com']))
       ->use(new LoggingMiddleware());

// Public route
$router->get('/public', 'PublicRoute');

// Protected route
$router->get('/protected', 'ProtectedRoute', [
    new AuthMiddleware(fn($token) => validateToken($token))
]);

// Dispatch
$response = $router->dispatch();
header('Content-Type: application/json');
echo json_encode($response);
```

## Built-in Middleware Features

### CorsMiddleware
- Configurable allowed origins, methods, headers
- Automatic preflight handling
- Credentials support

### AuthMiddleware
- Custom token validation
- Path exclusions with wildcards
- Bearer token support
- Automatic token extraction

### RateLimitMiddleware
- Per-IP rate limiting
- Configurable time windows
- Standard rate limit headers
- Automatic cleanup
- Path exclusions

### LoggingMiddleware
- Request logging (method, path, body)
- Response logging
- Request timing
- Configurable log levels

### JsonBodyParserMiddleware
- Automatic JSON parsing
- Content-type validation
- Error handling for invalid JSON

### SecurityHeadersMiddleware
- X-Content-Type-Options
- X-Frame-Options
- X-XSS-Protection
- Referrer-Policy
- Permissions-Policy
- Customizable headers

## Creating Custom Middleware

Implement the `MiddlewareInterface`:

```php
use Framework\Http\MiddlewareInterface;
use Framework\ApiResponse;

class CustomMiddleware implements MiddlewareInterface
{
    public function handle(array $request, callable $next): ?ApiResponse
    {
        // Before processing

        // Validate, modify request, etc.

        // Continue to next middleware
        $response = $next($request);

        // After processing

        return $response;
    }
}
```

## Testing

Run the test suite:

```bash
cd /ssd2/projects/progalaxy-elabs/foundation/stonescriptphp/StoneScriptPHP
php tests/middleware-test.php
```

Expected output:
```
🎉 All tests passed!
✅ Passed: 10
❌ Failed: 0
```

## Running the Example

```bash
cd examples/middleware
php -S localhost:8080 index.php
```

Test endpoints:
- `GET /public` - Public endpoint
- `GET /protected` - Requires auth token
- `GET /users/:userId` - URL parameters
- `POST /data` - JSON body parsing

## Migration Guide

### From Legacy Router

The new router is backward compatible with existing route handlers.

**Old way:**
```php
$routes = [
    'GET' => [
        '/api/users' => 'App\\Routes\\UsersRoute'
    ]
];
```

**New way:**
```php
$router = new Router();
$router->get('/api/users', 'App\\Routes\\UsersRoute');

// Or load from config
$router->loadRoutes($routes);
```

## Performance Considerations

- Middleware runs on every request
- Order middleware from lightest to heaviest
- Use path exclusions to skip unnecessary middleware
- Rate limiting uses file-based storage (can be extended for Redis/Memcached)

## Security Best Practices

1. Always use HTTPS in production with AuthMiddleware
2. Store sensitive data securely
3. Use rate limiting to prevent abuse
4. Add security headers with SecurityHeadersMiddleware
5. Validate all input in middleware
6. Use strong token validation in AuthMiddleware

## Future Enhancements

Potential improvements:
- Database/Redis-based rate limiting
- Middleware groups
- Conditional middleware
- Middleware aliases
- Built-in CSRF middleware
- Request/response transformation middleware
- Caching middleware

## Conclusion

The middleware system provides a powerful, flexible way to handle cross-cutting concerns in StoneScriptPHP applications. It's designed to be:

- **Easy to use** - Simple API with sensible defaults
- **Extensible** - Create custom middleware easily
- **Composable** - Chain middleware together
- **Testable** - Well-tested with comprehensive test suite
- **Production-ready** - Includes essential middleware out of the box

For detailed documentation, see `/docs/MIDDLEWARE.md`.

For working examples, see `/examples/middleware/`.
