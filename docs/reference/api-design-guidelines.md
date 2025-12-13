# API Design Guidelines

This document provides comprehensive guidelines for designing consistent, maintainable, and well-structured APIs using StoneScriptPHP.

## Table of Contents

- [API Design Principles](#api-design-principles)
- [RESTful API Design](#restful-api-design)
- [Request/Response Patterns](#requestresponse-patterns)
- [Validation Strategy](#validation-strategy)
- [Authentication & Authorization](#authentication--authorization)
- [Error Handling](#error-handling)
- [Versioning](#versioning)
- [Documentation Standards](#documentation-standards)
- [Rate Limiting](#rate-limiting)
- [Common Patterns](#common-patterns)

---

## API Design Principles

### 1. Consistency

Maintain consistency across all endpoints:

- Use the same response format
- Follow naming conventions
- Apply uniform error handling
- Keep authentication patterns consistent

### 2. Clarity

Design APIs that are intuitive and self-documenting:

- Use descriptive endpoint names
- Provide clear error messages
- Include helpful validation feedback
- Document expected behavior

### 3. Simplicity

Keep API design as simple as possible:

- Minimize required parameters
- Use sensible defaults
- Avoid unnecessary complexity
- One endpoint = one responsibility

### 4. Security

Security must be built in from the start:

- Validate all inputs
- Use authentication/authorization
- Protect sensitive data
- Follow OWASP guidelines

---

## RESTful API Design

### HTTP Methods

Use HTTP methods according to their semantic meaning:

```php
// GET - Retrieve resources (read-only)
'GET' => [
    '/users' => GetUsersRoute::class,              // List users
    '/users/profile' => GetUserProfileRoute::class, // Get single user
]

// POST - Create new resources or actions
'POST' => [
    '/users' => CreateUserRoute::class,            // Create user
    '/users/login' => LoginRoute::class,           // Login action
    '/users/logout' => LogoutRoute::class,         // Logout action
]

// PUT - Full resource update (not commonly used in StoneScriptPHP)
// PATCH - Partial resource update
// DELETE - Remove resources
```

### URL Structure

Design clean, hierarchical URLs:

```php
// Good - Clear, hierarchical structure
'/users'                    // List all users
'/users/profile'            // Get current user profile
'/users/settings'           // Get user settings
'/orders'                   // List orders
'/orders/recent'            // Get recent orders

// Bad - Confusing, inconsistent
'/get-users'               // Verb in URL (GET already indicates this)
'/userProfile'             // Inconsistent casing
'/user_settings'           // Mixed naming convention
'/api/v1/users/list'       // Redundant 'list'
```

### Resource Naming

Follow these conventions for resource names:

```php
// Use nouns, not verbs
'/products' not '/getProducts'
'/orders' not '/createOrder'

// Use plural for collections
'/users' not '/user'
'/products' not '/product'

// Use sub-resources for relationships
'/users/orders' not '/user-orders'
'/products/reviews' not '/product-reviews'

// Keep URLs lowercase with hyphens
'/user-profiles' not '/userProfiles' or '/user_profiles'
'/order-history' not '/orderHistory'
```

---

## Request/Response Patterns

### Standard Response Format

All API responses should follow the `ApiResponse` format:

```php
class ApiResponse
{
    public string $status;      // 'ok' or 'error'
    public string $message;     // Human-readable message
    public array $data;         // Response payload
}
```

### Success Responses

```php
function process(): ApiResponse
{
    // Simple success
    return res_ok(['user_id' => 123]);

    // Success with message
    return res_ok(['user' => $user], 'User created successfully');

    // List response with metadata
    return res_ok([
        'users' => $users,
        'total' => $total_count,
        'page' => $page,
        'per_page' => $per_page
    ]);

    // Empty success response
    return res_ok([]);
}
```

### Error Responses

```php
function process(): ApiResponse
{
    // Generic error
    return res_not_ok('Operation failed');

    // HTTP status code errors
    return e400('Invalid request parameters');
    return e401('Authentication required');
    return e403('Insufficient permissions');
    return e404('User not found');
    return e500('Internal server error');

    // Validation errors (handled automatically by framework)
    // Returns: { "status": "error", "message": "Validation failed", "data": {...} }
}
```

### Request Body Format

Always expect JSON for POST requests:

```php
// Content-Type: application/json

// Simple request
{
    "email": "user@example.com",
    "password": "secret123"
}

// Complex request
{
    "user": {
        "email": "user@example.com",
        "profile": {
            "first_name": "John",
            "last_name": "Doe"
        }
    },
    "preferences": {
        "notifications": true,
        "theme": "dark"
    }
}
```

### Query Parameters

Use query parameters for filtering and pagination:

```php
// GET /users?status=active&role=admin&page=1&limit=20

class GetUsersRoute implements IRouteHandler
{
    public ?string $status = null;
    public ?string $role = null;
    public int $page = 1;
    public int $limit = 20;

    function validation_rules(): array
    {
        return [
            'status' => 'string|in:active,inactive,pending',
            'role' => 'string',
            'page' => 'integer|min:1',
            'limit' => 'integer|min:1|max:100'
        ];
    }

    function process(): ApiResponse
    {
        $users = FnGetUsers::run(
            $this->status,
            $this->role,
            $this->page,
            $this->limit
        );

        return res_ok([
            'users' => $users,
            'page' => $this->page,
            'limit' => $this->limit
        ]);
    }
}
```

---

## Validation Strategy

### Validation Rules

Define comprehensive validation rules for all inputs:

```php
function validation_rules(): array
{
    return [
        // Required fields
        'email' => 'required|email',
        'username' => 'required|string|min:3|max:50',

        // Optional fields
        'bio' => 'string|max:500',
        'age' => 'integer|min:18|max:120',

        // Specific formats
        'phone' => 'regex:/^\+?[1-9]\d{1,14}$/',
        'url' => 'url',

        // Enums
        'status' => 'required|in:active,inactive,pending',
        'role' => 'required|in:admin,user,guest',

        // Arrays
        'tags' => 'array|min:1|max:10',
        'preferences' => 'array',

        // Nested validation
        'address.street' => 'required|string',
        'address.city' => 'required|string',
        'address.zip' => 'required|regex:/^\d{5}$/'
    ];
}
```

### Input Sanitization

Always sanitize and validate at multiple levels:

```php
function process(): ApiResponse
{
    // 1. Framework validates via validation_rules()

    // 2. Sanitize string inputs
    $username = trim($this->username);
    $email = strtolower(trim($this->email));

    // 3. Database function validates business rules
    $user_id = FnCreateUser::run($username, $email);

    // Database function should handle constraints:
    // - Unique email
    // - Username format
    // - Business logic validation

    return res_ok(['user_id' => $user_id]);
}
```

### Custom Validation

Create custom validators for complex rules:

```php
namespace App\Validators;

class CustomValidators
{
    /**
     * Validate that username is not in reserved list
     */
    public static function not_reserved(string $value): bool
    {
        $reserved = ['admin', 'root', 'system', 'api'];
        return !in_array(strtolower($value), $reserved);
    }

    /**
     * Validate strong password
     */
    public static function strong_password(string $value): bool
    {
        // At least 8 chars, 1 uppercase, 1 lowercase, 1 number, 1 special
        return preg_match(
            '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/',
            $value
        );
    }
}
```

---

## Authentication & Authorization

### JWT Authentication

Implement JWT authentication for protected endpoints:

```php
class GetUserProfileRoute implements IRouteHandler
{
    function validation_rules(): array
    {
        return [];
    }

    function process(): ApiResponse
    {
        // Verify JWT token
        $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (!$auth_header || !str_starts_with($auth_header, 'Bearer ')) {
            return e401('Missing or invalid authorization header');
        }

        $token = substr($auth_header, 7); // Remove 'Bearer '

        try {
            $decoded = JWT::decode($token, JWT_PUBLIC_KEY);
            $user_id = $decoded['user_id'];
        } catch (\Exception $e) {
            log_debug('JWT decode failed: ' . $e->getMessage());
            return e401('Invalid token');
        }

        // Get user profile
        $profile = FnGetUserProfile::run($user_id);

        return res_ok(['profile' => $profile]);
    }
}
```

### Middleware Pattern

Use middleware for authentication:

```php
namespace App\Middleware;

class AuthMiddleware
{
    public static function verify(): ?ApiResponse
    {
        $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (!$auth_header || !str_starts_with($auth_header, 'Bearer ')) {
            return e401('Authentication required');
        }

        $token = substr($auth_header, 7);

        try {
            $decoded = JWT::decode($token, JWT_PUBLIC_KEY);
            // Store user info for route handlers
            $_SERVER['AUTH_USER_ID'] = $decoded['user_id'];
            return null; // Continue processing
        } catch (\Exception $e) {
            return e401('Invalid token');
        }
    }
}

// In route handler
class ProtectedRoute implements IRouteHandler
{
    function process(): ApiResponse
    {
        // Apply middleware
        $auth_result = AuthMiddleware::verify();
        if ($auth_result !== null) {
            return $auth_result; // Auth failed
        }

        $user_id = $_SERVER['AUTH_USER_ID'];
        // Process authenticated request...
    }
}
```

### Authorization Levels

Implement role-based access control:

```php
class AdminOnlyRoute implements IRouteHandler
{
    function process(): ApiResponse
    {
        // Authenticate
        $auth_result = AuthMiddleware::verify();
        if ($auth_result !== null) {
            return $auth_result;
        }

        $user_id = $_SERVER['AUTH_USER_ID'];

        // Authorize - check user role
        $user_role = FnGetUserRole::run($user_id);

        if ($user_role !== 'admin') {
            return e403('Admin access required');
        }

        // Process admin action...
        return res_ok(['message' => 'Admin action completed']);
    }
}
```

---

## Error Handling

### Error Response Structure

Provide consistent error responses:

```php
// Production error (DEBUG_MODE = false)
{
    "status": "error",
    "message": "Validation failed",
    "data": []
}

// Development error (DEBUG_MODE = true)
{
    "status": "error",
    "message": "Validation failed",
    "data": {
        "email": ["Email is required", "Email must be valid"],
        "password": ["Password must be at least 8 characters"]
    }
}
```

### HTTP Status Codes

Use appropriate HTTP status codes:

```php
// Success codes
200 OK              // Successful GET, PUT, PATCH
201 Created         // Successful POST (resource created)
204 No Content      // Successful DELETE

// Client error codes
400 Bad Request     // Invalid request format or parameters
401 Unauthorized    // Authentication required or failed
403 Forbidden       // Authenticated but not authorized
404 Not Found       // Resource doesn't exist
409 Conflict        // Duplicate resource (e.g., email exists)
422 Unprocessable   // Validation failed
429 Too Many        // Rate limit exceeded

// Server error codes
500 Internal Error  // Server-side error
503 Unavailable     // Service temporarily unavailable
```

### Exception Handling

Handle exceptions gracefully:

```php
function process(): ApiResponse
{
    try {
        $result = FnComplexDatabaseOperation::run($this->param);

        if (empty($result)) {
            return e404('Resource not found');
        }

        return res_ok(['result' => $result]);

    } catch (\PDOException $e) {
        // Database errors
        log_debug('Database error: ' . $e->getMessage());

        if (DEBUG_MODE) {
            return e500('Database error: ' . $e->getMessage());
        }
        return e500('A database error occurred');

    } catch (\InvalidArgumentException $e) {
        // Business logic errors
        log_debug('Invalid argument: ' . $e->getMessage());
        return e400($e->getMessage());

    } catch (\Exception $e) {
        // Unexpected errors
        log_debug('Unexpected error: ' . $e->getMessage());
        return e500('An unexpected error occurred');
    }
}
```

### Error Messages

Provide helpful error messages:

```php
// Good error messages
return e400('Email address is required');
return e400('Password must be at least 8 characters');
return e404('User with ID 123 not found');
return e409('Email address already registered');

// Bad error messages
return e400('Bad request');
return e500('Error');
return res_not_ok('Something went wrong');
```

---

## Versioning

### URL-Based Versioning

While StoneScriptPHP doesn't enforce versioning, consider this pattern for API evolution:

```php
// Version 1 routes
'GET' => [
    '/v1/users' => V1\GetUsersRoute::class,
    '/v1/products' => V1\GetProductsRoute::class,
]

// Version 2 routes (with breaking changes)
'GET' => [
    '/v2/users' => V2\GetUsersRoute::class,
    '/v2/products' => V2\GetProductsRoute::class,
]

// Default to latest version
'GET' => [
    '/users' => V2\GetUsersRoute::class,  // Points to latest
]
```

### Deprecation Strategy

When deprecating old endpoints:

```php
class DeprecatedRoute implements IRouteHandler
{
    function process(): ApiResponse
    {
        // Add deprecation header
        header('Warning: 299 - "This endpoint is deprecated. Use /v2/users instead"');

        // Still process the request
        $result = FnOldMethod::run();
        return res_ok(['result' => $result]);
    }
}
```

---

## Documentation Standards

### Endpoint Documentation

Document each endpoint clearly:

```php
/**
 * Create User Endpoint
 *
 * Creates a new user account with the provided credentials.
 *
 * Method: POST
 * Path: /users
 * Auth: None (public endpoint)
 *
 * Request Body:
 * {
 *   "username": "johndoe",       // 3-50 characters, alphanumeric
 *   "email": "john@example.com", // Valid email address
 *   "password": "secret123"      // Minimum 8 characters
 * }
 *
 * Success Response (201):
 * {
 *   "status": "ok",
 *   "message": "User created successfully",
 *   "data": {
 *     "user_id": 123,
 *     "username": "johndoe",
 *     "email": "john@example.com"
 *   }
 * }
 *
 * Error Responses:
 * - 400: Invalid input or validation failed
 * - 409: Email or username already exists
 * - 500: Internal server error
 */
class CreateUserRoute implements IRouteHandler
{
    // Implementation...
}
```

### API Reference

Maintain a comprehensive API reference document (see `api-reference.md`).

---

## Rate Limiting

### Implement Rate Limiting

Protect your API from abuse:

```php
namespace App\Middleware;

class RateLimitMiddleware
{
    private const LIMIT = 100;      // Requests per window
    private const WINDOW = 3600;    // 1 hour in seconds

    public static function check(string $identifier): ?ApiResponse
    {
        $key = "rate_limit:$identifier";

        // Get current count
        $count = apcu_fetch($key, $success);

        if (!$success) {
            // First request in window
            apcu_store($key, 1, self::WINDOW);
            return null;
        }

        if ($count >= self::LIMIT) {
            return new ApiResponse(
                'error',
                'Rate limit exceeded',
                ['retry_after' => apcu_fetch("{$key}:ttl")]
            );
        }

        // Increment counter
        apcu_inc($key);
        return null;
    }
}

// Usage in route
class ApiRoute implements IRouteHandler
{
    function process(): ApiResponse
    {
        $identifier = $_SERVER['REMOTE_ADDR']; // Or user ID for authenticated

        $rate_limit_result = RateLimitMiddleware::check($identifier);
        if ($rate_limit_result !== null) {
            http_response_code(429);
            return $rate_limit_result;
        }

        // Process request...
    }
}
```

---

## Common Patterns

### Pagination

Implement consistent pagination:

```php
class GetUsersRoute implements IRouteHandler
{
    public int $page = 1;
    public int $limit = 20;

    function validation_rules(): array
    {
        return [
            'page' => 'integer|min:1',
            'limit' => 'integer|min:1|max:100'
        ];
    }

    function process(): ApiResponse
    {
        $offset = ($this->page - 1) * $this->limit;

        $users = FnGetUsers::run($this->limit, $offset);
        $total = FnGetUsersCount::run();

        return res_ok([
            'users' => $users,
            'pagination' => [
                'page' => $this->page,
                'limit' => $this->limit,
                'total' => $total,
                'pages' => ceil($total / $this->limit)
            ]
        ]);
    }
}
```

### Search and Filtering

Implement flexible search:

```php
class SearchProductsRoute implements IRouteHandler
{
    public ?string $q = null;          // Search query
    public ?string $category = null;   // Filter by category
    public ?float $min_price = null;   // Min price
    public ?float $max_price = null;   // Max price
    public string $sort = 'name';      // Sort field
    public string $order = 'asc';      // Sort order
    public int $page = 1;
    public int $limit = 20;

    function validation_rules(): array
    {
        return [
            'q' => 'string|min:2',
            'category' => 'string',
            'min_price' => 'numeric|min:0',
            'max_price' => 'numeric|min:0',
            'sort' => 'in:name,price,created_at',
            'order' => 'in:asc,desc',
            'page' => 'integer|min:1',
            'limit' => 'integer|min:1|max:100'
        ];
    }

    function process(): ApiResponse
    {
        $products = FnSearchProducts::run(
            $this->q,
            $this->category,
            $this->min_price,
            $this->max_price,
            $this->sort,
            $this->order,
            $this->limit,
            ($this->page - 1) * $this->limit
        );

        return res_ok([
            'products' => $products,
            'filters' => [
                'query' => $this->q,
                'category' => $this->category,
                'price_range' => [$this->min_price, $this->max_price]
            ],
            'pagination' => [
                'page' => $this->page,
                'limit' => $this->limit
            ]
        ]);
    }
}
```

### Batch Operations

Handle batch operations efficiently:

```php
class BatchUpdateUsersRoute implements IRouteHandler
{
    public array $user_updates;

    function validation_rules(): array
    {
        return [
            'user_updates' => 'required|array|min:1|max:100',
            'user_updates.*.user_id' => 'required|integer',
            'user_updates.*.status' => 'required|in:active,inactive'
        ];
    }

    function process(): ApiResponse
    {
        $results = [];
        $errors = [];

        foreach ($this->user_updates as $update) {
            try {
                FnUpdateUserStatus::run(
                    $update['user_id'],
                    $update['status']
                );
                $results[] = $update['user_id'];
            } catch (\Exception $e) {
                $errors[] = [
                    'user_id' => $update['user_id'],
                    'error' => $e->getMessage()
                ];
            }
        }

        return res_ok([
            'updated' => $results,
            'failed' => $errors,
            'total' => count($this->user_updates),
            'success_count' => count($results),
            'error_count' => count($errors)
        ]);
    }
}
```

### File Uploads

Handle file uploads (when needed):

```php
class UploadAvatarRoute implements IRouteHandler
{
    function validation_rules(): array
    {
        return [];
    }

    function process(): ApiResponse
    {
        // Authenticate user
        $auth_result = AuthMiddleware::verify();
        if ($auth_result !== null) {
            return $auth_result;
        }

        $user_id = $_SERVER['AUTH_USER_ID'];

        // Validate file upload
        if (!isset($_FILES['avatar'])) {
            return e400('No file uploaded');
        }

        $file = $_FILES['avatar'];

        // Validate file type
        $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($file['type'], $allowed_types)) {
            return e400('Invalid file type. Allowed: JPEG, PNG, WebP');
        }

        // Validate file size (max 5MB)
        if ($file['size'] > 5 * 1024 * 1024) {
            return e400('File too large. Maximum size: 5MB');
        }

        // Process upload
        $filename = $user_id . '_' . time() . '.jpg';
        $destination = UPLOAD_PATH . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            return e500('Failed to save file');
        }

        // Update user avatar in database
        FnUpdateUserAvatar::run($user_id, $filename);

        return res_ok([
            'avatar_url' => "/uploads/avatars/$filename"
        ]);
    }
}
```

---

## Best Practices Summary

### Do's

✅ Use validation rules for all inputs
✅ Return consistent response formats
✅ Use appropriate HTTP status codes
✅ Implement authentication for protected endpoints
✅ Log errors with context
✅ Document all endpoints
✅ Use database functions for business logic
✅ Sanitize and validate at multiple levels
✅ Provide helpful error messages
✅ Implement rate limiting for public APIs

### Don'ts

❌ Don't expose sensitive information in errors
❌ Don't put business logic in routes
❌ Don't use raw SQL in routes
❌ Don't return different formats for similar endpoints
❌ Don't ignore validation
❌ Don't use inconsistent naming
❌ Don't skip authentication checks
❌ Don't return stack traces in production
❌ Don't allow unlimited request sizes
❌ Don't forget to log important events

---

## Related Documentation

- [Coding Standards](coding-standards.md)
- [Security Best Practices](security-best-practices.md)
- [Performance Guidelines](performance-guidelines.md)
- [API Reference](api-reference.md)
- [Validation Guide](validation.md)
- [Middleware Guide](MIDDLEWARE.md)
