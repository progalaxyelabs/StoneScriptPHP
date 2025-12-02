# StoneScriptPHP API Reference

Complete reference guide for all framework classes, methods, and usage patterns.

## Table of Contents

- [Core Classes](#core-classes)
  - [Router](#router)
  - [ApiResponse](#apiresponse)
  - [IRouteHandler](#iroutehandler)
  - [Validator](#validator)
  - [Database](#database)
- [Middleware System](#middleware-system)
  - [MiddlewareInterface](#middlewareinterface)
  - [MiddlewarePipeline](#middlewarepipeline)
- [Helper Functions](#helper-functions)
- [Complete Examples](#complete-examples)

---

## Core Classes

### Router

**Namespace:** `Framework\Routing`

The Router class handles HTTP routing, middleware execution, and request dispatching.

#### Constructor

```php
public function __construct()
```

Creates a new Router instance with an empty global middleware pipeline.

**Example:**
```php
use Framework\Routing\Router;

$router = new Router();
```

#### Methods

##### `use(MiddlewareInterface $middleware): self`

Add global middleware that runs on all routes.

**Parameters:**
- `$middleware` - Middleware instance implementing `MiddlewareInterface`

**Returns:** Router instance for method chaining

**Example:**
```php
use Framework\Http\Middleware\CorsMiddleware;
use Framework\Http\Middleware\LoggingMiddleware;

$router = new Router();
$router->use(new CorsMiddleware())
       ->use(new LoggingMiddleware());
```

##### `useMany(array $middlewares): self`

Add multiple global middleware at once.

**Parameters:**
- `$middlewares` - Array of middleware instances

**Returns:** Router instance for method chaining

**Example:**
```php
$router->useMany([
    new CorsMiddleware(),
    new LoggingMiddleware(),
    new SecurityHeadersMiddleware()
]);
```

##### `get(string $path, string $handler, array $middleware = []): self`

Register a GET route.

**Parameters:**
- `$path` - Route path (e.g., `/users/:id`)
- `$handler` - Fully qualified handler class name
- `$middleware` - Optional route-specific middleware array

**Returns:** Router instance for method chaining

**Example:**
```php
use App\Routes\GetUserRoute;

$router->get('/users/:id', GetUserRoute::class);
```

##### `post(string $path, string $handler, array $middleware = []): self`

Register a POST route.

**Parameters:**
- `$path` - Route path
- `$handler` - Fully qualified handler class name
- `$middleware` - Optional route-specific middleware array

**Returns:** Router instance for method chaining

**Example:**
```php
use App\Routes\CreateUserRoute;
use Framework\Http\Middleware\AuthMiddleware;

$router->post('/users', CreateUserRoute::class, [
    new AuthMiddleware()
]);
```

##### `addRoute(string $method, string $path, string $handler, array $middleware = []): self`

Register a route for any HTTP method.

**Parameters:**
- `$method` - HTTP method (GET, POST, PUT, PATCH, DELETE)
- `$path` - Route path
- `$handler` - Fully qualified handler class name
- `$middleware` - Optional route-specific middleware array

**Returns:** Router instance for method chaining

**Example:**
```php
$router->addRoute('PUT', '/users/:id', UpdateUserRoute::class);
$router->addRoute('DELETE', '/users/:id', DeleteUserRoute::class);
```

##### `loadRoutes(array $routesConfig): self`

Load routes from a configuration array.

**Parameters:**
- `$routesConfig` - Array of routes organized by HTTP method

**Returns:** Router instance for method chaining

**Example:**
```php
$routesConfig = [
    'GET' => [
        '/users' => GetUsersRoute::class,
        '/users/:id' => GetUserRoute::class
    ],
    'POST' => [
        '/users' => CreateUserRoute::class
    ]
];

$router->loadRoutes($routesConfig);
```

##### `dispatch(): ApiResponse`

Process the incoming HTTP request and return an API response.

**Returns:** `ApiResponse` object

**Example:**
```php
$response = $router->dispatch();
echo json_encode($response);
```

#### Route Parameters

Routes support named parameters using the `:param` syntax.

**Example:**
```php
// Route definition
$router->get('/users/:id/posts/:postId', GetUserPostRoute::class);

// In your route handler
class GetUserPostRoute implements IRouteHandler {
    public string $id;      // Automatically populated
    public string $postId;  // Automatically populated

    public function process(): ApiResponse {
        // Access route parameters as properties
        $userId = $this->id;
        $postId = $this->postId;

        // Your logic here
        return res_ok(['userId' => $userId, 'postId' => $postId]);
    }
}
```

---

### ApiResponse

**Namespace:** `Framework`

A standardized response object for API responses.

#### Constructor

```php
public function __construct(string $status, string $message, $data = null)
```

**Parameters:**
- `$status` - Response status (e.g., 'ok', 'error')
- `$message` - Response message
- `$data` - Optional response data

#### Properties

```php
public string $status;
public string $message;
public mixed $data;
```

#### Examples

**Success Response:**
```php
use Framework\ApiResponse;

$response = new ApiResponse('ok', 'User created successfully', [
    'id' => 123,
    'name' => 'John Doe'
]);

// Output: {"status":"ok","message":"User created successfully","data":{"id":123,"name":"John Doe"}}
```

**Error Response:**
```php
$response = new ApiResponse('error', 'User not found');

// Output: {"status":"error","message":"User not found","data":null}
```

**Using Helper Functions (Recommended):**
```php
// Success
$response = res_ok(['user' => $userData], 'User fetched successfully');

// Error
$response = res_error('Invalid credentials');

// Not OK
$response = res_not_ok('Operation failed');
```

---

### IRouteHandler

**Namespace:** `Framework`

Interface that all route handlers must implement.

#### Interface Definition

```php
interface IRouteHandler {
    public function validation_rules(): array;
    public function process(): ApiResponse;
}
```

#### Methods

##### `validation_rules(): array`

Define validation rules for the incoming request.

**Returns:** Array of validation rules

**Example:**
```php
public function validation_rules(): array {
    return [
        'email' => 'required|email',
        'password' => 'required|min:8',
        'name' => 'required|string|min:2|max:100'
    ];
}
```

##### `process(): ApiResponse`

Process the request and return a response. Only called if validation passes.

**Returns:** `ApiResponse` object

**Example:**
```php
public function process(): ApiResponse {
    // Validated data is available as class properties
    $email = $this->email;
    $password = $this->password;
    $name = $this->name;

    // Your business logic here
    $user = createUser($email, $password, $name);

    return res_ok($user, 'User created successfully');
}
```

#### Complete Route Handler Example

```php
<?php

namespace App\Routes;

use Framework\ApiResponse;
use Framework\IRouteHandler;
use Framework\Database;

class CreateUserRoute implements IRouteHandler {
    // Request data properties
    public string $email;
    public string $password;
    public string $name;
    public ?int $age;

    /**
     * Define validation rules
     */
    public function validation_rules(): array {
        return [
            'email' => 'required|email',
            'password' => 'required|min:8',
            'name' => 'required|string|min:2|max:100',
            'age' => 'integer|min:18|max:120'
        ];
    }

    /**
     * Process the validated request
     */
    public function process(): ApiResponse {
        // Check if user already exists
        $existing = Database::fn('fn_get_user_by_email', [$this->email]);

        if (!empty($existing)) {
            return res_error('Email already registered');
        }

        // Create new user
        $userData = Database::fn('fn_create_user', [
            $this->email,
            password_hash($this->password, PASSWORD_DEFAULT),
            $this->name,
            $this->age ?? 0
        ]);

        if (empty($userData)) {
            return res_error('Failed to create user');
        }

        return res_ok($userData[0], 'User created successfully');
    }
}
```

---

### Validator

**Namespace:** `Framework`

Provides rules-based validation for request data.

#### Constructor

```php
public function __construct(array $data, array $rules)
```

**Parameters:**
- `$data` - Data to validate
- `$rules` - Validation rules

#### Static Factory Method

##### `make(array $data, array $rules): Validator`

Create a validator instance.

**Example:**
```php
use Framework\Validator;

$validator = Validator::make($_POST, [
    'email' => 'required|email',
    'age' => 'required|integer|min:18'
]);
```

#### Methods

##### `validate(): bool`

Validate data against rules.

**Returns:** `true` if validation passes, `false` otherwise

**Example:**
```php
if ($validator->validate()) {
    // Validation passed
    echo "Data is valid";
} else {
    // Validation failed
    $errors = $validator->errors();
}
```

##### `errors(): array`

Get all validation errors grouped by field.

**Returns:** Array of errors

**Example:**
```php
$errors = $validator->errors();
// [
//     'email' => ['The email must be a valid email address.'],
//     'age' => ['The age must be at least 18.']
// ]
```

##### `firstError(): ?string`

Get the first validation error message.

**Returns:** First error message or null

**Example:**
```php
$error = $validator->firstError();
// "The email must be a valid email address."
```

##### `errorMessages(): array`

Get all error messages as a flat array.

**Returns:** Array of error messages

**Example:**
```php
$messages = $validator->errorMessages();
// [
//     'The email must be a valid email address.',
//     'The age must be at least 18.'
// ]
```

##### `addCustomValidator(string $name, callable $callback): void`

Register a custom validation rule.

**Parameters:**
- `$name` - Validator name
- `$callback` - Validation callback (receives value and parameters)

**Example:**
```php
$validator = Validator::make($data, [
    'username' => 'required|unique_username'
]);

$validator->addCustomValidator('unique_username', function($value, $param) {
    // Check if username exists in database
    $result = Database::fn('fn_check_username', [$value]);
    return empty($result);
});

if (!$validator->validate()) {
    echo $validator->firstError();
}
```

#### Built-in Validation Rules

| Rule | Description | Example |
|------|-------------|---------|
| `required` | Field must be present and not empty | `'name' => 'required'` |
| `email` | Must be valid email address | `'email' => 'required\|email'` |
| `min:n` | Minimum length/value | `'password' => 'min:8'` |
| `max:n` | Maximum length/value | `'bio' => 'max:500'` |
| `numeric` | Must be numeric | `'price' => 'numeric'` |
| `integer` | Must be an integer | `'age' => 'integer'` |
| `string` | Must be a string | `'name' => 'string'` |
| `array` | Must be an array | `'tags' => 'array'` |
| `boolean` | Must be boolean | `'active' => 'boolean'` |
| `regex:pattern` | Must match regex | `'code' => 'regex:/^[A-Z]{3}$/'` |
| `in:val1,val2` | Must be in list | `'status' => 'in:active,inactive'` |
| `url` | Must be valid URL | `'website' => 'url'` |

#### Validation Examples

**Basic Validation:**
```php
$validator = Validator::make($_POST, [
    'email' => 'required|email',
    'password' => 'required|min:8|max:100',
    'age' => 'required|integer|min:18'
]);

if ($validator->validate()) {
    echo "Valid!";
} else {
    echo $validator->firstError();
}
```

**Optional Fields:**
```php
// Fields without 'required' are optional
$validator = Validator::make($data, [
    'name' => 'required|string',
    'bio' => 'string|max:500',  // Optional, validated only if provided
    'website' => 'url'           // Optional, validated only if provided
]);
```

**Complex Validation:**
```php
$rules = [
    'username' => 'required|string|min:3|max:20|regex:/^[a-zA-Z0-9_]+$/',
    'email' => 'required|email',
    'password' => 'required|string|min:8',
    'age' => 'integer|min:13|max:120',
    'country' => 'required|in:US,UK,CA,AU',
    'interests' => 'array|min:1|max:10',
    'website' => 'url'
];

$validator = Validator::make($data, $rules);

if (!$validator->validate()) {
    $response = res_error('Validation failed');
    $response->data = $validator->errors();
    return $response;
}
```

---

### Database

**Namespace:** `Framework`

Handles PostgreSQL database connections and function calls.

#### Static Methods

##### `fn(string $function_name, array $params): array`

Call a PostgreSQL function and return results.

**Parameters:**
- `$function_name` - PostgreSQL function name
- `$params` - Array of function parameters

**Returns:** Array of result rows

**Example:**
```php
use Framework\Database;

// Call fn_get_user(user_id)
$result = Database::fn('fn_get_user', [123]);

// Call fn_create_product(name, price, category)
$result = Database::fn('fn_create_product', [
    'Laptop',
    999.99,
    'Electronics'
]);
```

##### `query(string $sql): string`

Execute raw SQL query.

**Parameters:**
- `$sql` - SQL query string

**Returns:** Query result as string

**Example:**
```php
$result = Database::query('SELECT * FROM users WHERE active = true');
```

##### `internal_query(string $sql): array`

Execute raw SQL query and return array results.

**Parameters:**
- `$sql` - SQL query string

**Returns:** Array of result rows

**Example:**
```php
$users = Database::internal_query('SELECT id, name FROM users LIMIT 10');
foreach ($users as $user) {
    echo $user['name'];
}
```

##### `result_as_object(string $function_name, array $rows, string $class): ?object`

Convert first row to object instance.

**Parameters:**
- `$function_name` - Function name (for logging)
- `$rows` - Result rows from database
- `$class` - Class name to instantiate

**Returns:** Object instance or null

**Example:**
```php
class User {
    public int $id;
    public string $name;
    public string $email;
}

$rows = Database::fn('fn_get_user', [123]);
$user = Database::result_as_object('fn_get_user', $rows, User::class);

echo $user->name; // Access as object properties
```

##### `result_as_table(string $function_name, array $rows, string $class): array`

Convert all rows to array of object instances.

**Parameters:**
- `$function_name` - Function name (for logging)
- `$rows` - Result rows from database
- `$class` - Class name to instantiate

**Returns:** Array of objects

**Example:**
```php
$rows = Database::fn('fn_get_all_users', []);
$users = Database::result_as_table('fn_get_all_users', $rows, User::class);

foreach ($users as $user) {
    echo $user->name;
}
```

##### `copy_from(array $rows, string $tablename, string $delimiter): bool`

Bulk insert data using PostgreSQL COPY.

**Parameters:**
- `$rows` - Array of data rows
- `$tablename` - Target table name
- `$delimiter` - Field delimiter

**Returns:** Success boolean

**Example:**
```php
$data = [
    "1\tJohn\tjohn@example.com",
    "2\tJane\tjane@example.com"
];

Database::copy_from($data, 'users', "\t");
```

#### Complete Database Example

```php
<?php

namespace App\Routes;

use Framework\ApiResponse;
use Framework\IRouteHandler;
use Framework\Database;

// Define model class
class Product {
    public int $id;
    public string $name;
    public float $price;
    public string $category;
}

class GetProductsRoute implements IRouteHandler {
    public ?string $category;
    public ?int $limit;

    public function validation_rules(): array {
        return [
            'category' => 'string',
            'limit' => 'integer|min:1|max:100'
        ];
    }

    public function process(): ApiResponse {
        $limit = $this->limit ?? 20;
        $category = $this->category ?? 'all';

        // Call PostgreSQL function
        $rows = Database::fn('fn_get_products', [$category, $limit]);

        // Convert to objects
        $products = Database::result_as_table('fn_get_products', $rows, Product::class);

        return res_ok($products, 'Products fetched successfully');
    }
}
```

---

## Middleware System

### MiddlewareInterface

**Namespace:** `Framework\Http`

Interface for creating custom middleware.

#### Interface Definition

```php
interface MiddlewareInterface {
    public function handle(array $request, callable $next): ?ApiResponse;
}
```

#### Methods

##### `handle(array $request, callable $next): ?ApiResponse`

Process the request and optionally pass to next middleware.

**Parameters:**
- `$request` - Request data array containing:
  - `method` - HTTP method
  - `path` - Request path
  - `input` - Request input data
  - `params` - Route parameters
  - `headers` - Request headers
- `$next` - Callable to invoke next middleware

**Returns:**
- `ApiResponse` to short-circuit the pipeline
- `null` to continue (call `$next`)

#### Creating Custom Middleware

**Example: Authentication Middleware**
```php
<?php

namespace App\Middleware;

use Framework\Http\MiddlewareInterface;
use Framework\ApiResponse;
use Framework\Database;

class AuthMiddleware implements MiddlewareInterface {
    public function handle(array $request, callable $next): ?ApiResponse {
        // Get authorization header
        $headers = $request['headers'];
        $authHeader = $headers['Authorization'] ?? null;

        if (!$authHeader) {
            return new ApiResponse('error', 'Authorization required');
        }

        // Validate token
        $token = str_replace('Bearer ', '', $authHeader);
        $user = $this->validateToken($token);

        if (!$user) {
            return new ApiResponse('error', 'Invalid token');
        }

        // Add user to request
        $request['user'] = $user;

        // Continue to next middleware
        return $next($request);
    }

    private function validateToken(string $token): ?array {
        $result = Database::fn('fn_validate_token', [$token]);
        return $result[0] ?? null;
    }
}
```

**Example: Rate Limiting Middleware**
```php
<?php

namespace App\Middleware;

use Framework\Http\MiddlewareInterface;
use Framework\ApiResponse;

class RateLimitMiddleware implements MiddlewareInterface {
    private int $maxRequests = 100;
    private int $windowSeconds = 60;

    public function handle(array $request, callable $next): ?ApiResponse {
        $ip = $_SERVER['REMOTE_ADDR'];

        // Check rate limit
        if ($this->isRateLimited($ip)) {
            http_response_code(429);
            return new ApiResponse('error', 'Too many requests');
        }

        // Record request
        $this->recordRequest($ip);

        // Continue to next middleware
        return $next($request);
    }

    private function isRateLimited(string $ip): bool {
        // Implement rate limiting logic
        return false;
    }

    private function recordRequest(string $ip): void {
        // Record request timestamp
    }
}
```

**Example: Request Logging Middleware**
```php
<?php

namespace App\Middleware;

use Framework\Http\MiddlewareInterface;
use Framework\ApiResponse;

class LoggingMiddleware implements MiddlewareInterface {
    public function handle(array $request, callable $next): ?ApiResponse {
        $startTime = microtime(true);

        // Log request
        $method = $request['method'];
        $path = $request['path'];
        log_debug("Incoming: $method $path");

        // Process request
        $response = $next($request);

        // Log response
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        log_debug("Completed: $method $path - {$duration}ms");

        return $response;
    }
}
```

---

### MiddlewarePipeline

**Namespace:** `Framework\Http`

Manages and executes middleware in sequence.

#### Methods

##### `pipe(MiddlewareInterface $middleware): self`

Add middleware to the pipeline.

**Parameters:**
- `$middleware` - Middleware instance

**Returns:** Pipeline instance for chaining

**Example:**
```php
use Framework\Http\MiddlewarePipeline;

$pipeline = new MiddlewarePipeline();
$pipeline->pipe(new CorsMiddleware())
         ->pipe(new AuthMiddleware());
```

##### `pipes(array $middlewares): self`

Add multiple middleware at once.

**Parameters:**
- `$middlewares` - Array of middleware instances

**Returns:** Pipeline instance for chaining

**Example:**
```php
$pipeline->pipes([
    new CorsMiddleware(),
    new LoggingMiddleware(),
    new AuthMiddleware()
]);
```

##### `process(array $request, callable $finalHandler): ApiResponse`

Execute the middleware pipeline.

**Parameters:**
- `$request` - Request data array
- `$finalHandler` - Final handler to call after all middleware

**Returns:** `ApiResponse`

**Example:**
```php
$response = $pipeline->process($request, function($request) {
    // Final handler - route handler execution
    return $routeHandler->process();
});
```

##### `count(): int`

Get number of middleware in pipeline.

**Returns:** Middleware count

---

## Helper Functions

### Response Helpers

#### `res_ok($data, string $message = ''): ApiResponse`

Create a success response.

**Parameters:**
- `$data` - Response data
- `$message` - Optional success message

**Returns:** `ApiResponse` with status 'ok'

**Example:**
```php
return res_ok(['user' => $userData], 'User created successfully');
```

#### `res_error(string $message): ApiResponse`

Create an error response.

**Parameters:**
- `$message` - Error message

**Returns:** `ApiResponse` with status 'error'

**Example:**
```php
return res_error('Invalid credentials');
```

#### `res_not_ok(string $message): ApiResponse`

Create a not-ok response.

**Parameters:**
- `$message` - Message

**Returns:** `ApiResponse` with status 'not ok'

**Example:**
```php
return res_not_ok('Operation failed');
```

### Logging Helpers

#### `log_debug(string $message): void`

Log a debug message.

**Example:**
```php
log_debug('User authentication started');
log_debug('Query took ' . $duration . 'ms');
```

#### `log_error(string $message): void`

Log an error message.

**Example:**
```php
log_error('Database connection failed: ' . $error);
```

---

## Complete Examples

### Example 1: Simple CRUD API

```php
<?php

namespace App\Routes;

use Framework\ApiResponse;
use Framework\IRouteHandler;
use Framework\Database;

// GET /users
class GetUsersRoute implements IRouteHandler {
    public ?int $page;
    public ?int $limit;

    public function validation_rules(): array {
        return [
            'page' => 'integer|min:1',
            'limit' => 'integer|min:1|max:100'
        ];
    }

    public function process(): ApiResponse {
        $page = $this->page ?? 1;
        $limit = $this->limit ?? 20;
        $offset = ($page - 1) * $limit;

        $users = Database::fn('fn_get_users', [$limit, $offset]);

        return res_ok($users, 'Users fetched successfully');
    }
}

// GET /users/:id
class GetUserRoute implements IRouteHandler {
    public int $id; // Route parameter

    public function validation_rules(): array {
        return [];
    }

    public function process(): ApiResponse {
        $users = Database::fn('fn_get_user', [$this->id]);

        if (empty($users)) {
            return res_error('User not found');
        }

        return res_ok($users[0], 'User fetched successfully');
    }
}

// POST /users
class CreateUserRoute implements IRouteHandler {
    public string $name;
    public string $email;
    public string $password;

    public function validation_rules(): array {
        return [
            'name' => 'required|string|min:2|max:100',
            'email' => 'required|email',
            'password' => 'required|string|min:8'
        ];
    }

    public function process(): ApiResponse {
        // Check if email exists
        $existing = Database::fn('fn_get_user_by_email', [$this->email]);
        if (!empty($existing)) {
            return res_error('Email already exists');
        }

        // Hash password
        $hashedPassword = password_hash($this->password, PASSWORD_DEFAULT);

        // Create user
        $users = Database::fn('fn_create_user', [
            $this->name,
            $this->email,
            $hashedPassword
        ]);

        if (empty($users)) {
            return res_error('Failed to create user');
        }

        return res_ok($users[0], 'User created successfully');
    }
}

// PUT /users/:id
class UpdateUserRoute implements IRouteHandler {
    public int $id;    // Route parameter
    public string $name;
    public ?string $email;

    public function validation_rules(): array {
        return [
            'name' => 'required|string|min:2|max:100',
            'email' => 'email'
        ];
    }

    public function process(): ApiResponse {
        $users = Database::fn('fn_update_user', [
            $this->id,
            $this->name,
            $this->email
        ]);

        if (empty($users)) {
            return res_error('User not found or update failed');
        }

        return res_ok($users[0], 'User updated successfully');
    }
}

// DELETE /users/:id
class DeleteUserRoute implements IRouteHandler {
    public int $id; // Route parameter

    public function validation_rules(): array {
        return [];
    }

    public function process(): ApiResponse {
        $result = Database::fn('fn_delete_user', [$this->id]);

        if (empty($result)) {
            return res_error('User not found');
        }

        return res_ok(null, 'User deleted successfully');
    }
}
```

**Routes Configuration:**
```php
// src/config/routes.php
return [
    'GET' => [
        '/users' => App\Routes\GetUsersRoute::class,
        '/users/:id' => App\Routes\GetUserRoute::class
    ],
    'POST' => [
        '/users' => App\Routes\CreateUserRoute::class
    ],
    'PUT' => [
        '/users/:id' => App\Routes\UpdateUserRoute::class
    ],
    'DELETE' => [
        '/users/:id' => App\Routes\DeleteUserRoute::class
    ]
];
```

### Example 2: Authentication with Middleware

```php
<?php

namespace App\Middleware;

use Framework\Http\MiddlewareInterface;
use Framework\ApiResponse;
use Framework\Database;

class JwtAuthMiddleware implements MiddlewareInterface {
    public function handle(array $request, callable $next): ?ApiResponse {
        $headers = $request['headers'];
        $authHeader = $headers['Authorization'] ?? null;

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            http_response_code(401);
            return new ApiResponse('error', 'Unauthorized');
        }

        $token = substr($authHeader, 7);

        // Validate JWT token
        $userData = $this->validateJwt($token);

        if (!$userData) {
            http_response_code(401);
            return new ApiResponse('error', 'Invalid token');
        }

        // Add user data to request
        $request['user'] = $userData;

        return $next($request);
    }

    private function validateJwt(string $token): ?array {
        // Implement JWT validation
        $result = Database::fn('fn_validate_jwt', [$token]);
        return $result[0] ?? null;
    }
}
```

**Protected Route:**
```php
<?php

namespace App\Routes;

use Framework\ApiResponse;
use Framework\IRouteHandler;
use Framework\Database;

class GetProfileRoute implements IRouteHandler {
    // User data injected by middleware
    private ?array $user = null;

    public function validation_rules(): array {
        return [];
    }

    public function process(): ApiResponse {
        // Access user from middleware
        if (!$this->user) {
            return res_error('Unauthorized');
        }

        $userId = $this->user['id'];
        $profile = Database::fn('fn_get_user_profile', [$userId]);

        return res_ok($profile[0], 'Profile fetched');
    }
}
```

**Router Setup:**
```php
use Framework\Routing\Router;
use App\Middleware\JwtAuthMiddleware;

$router = new Router();

// Public routes
$router->post('/login', LoginRoute::class);
$router->post('/register', RegisterRoute::class);

// Protected routes with auth middleware
$router->get('/profile', GetProfileRoute::class, [
    new JwtAuthMiddleware()
]);

$router->put('/profile', UpdateProfileRoute::class, [
    new JwtAuthMiddleware()
]);

$response = $router->dispatch();
```

### Example 3: File Upload Handler

```php
<?php

namespace App\Routes;

use Framework\ApiResponse;
use Framework\IRouteHandler;
use Framework\Database;

class UploadAvatarRoute implements IRouteHandler {
    public int $userId;

    public function validation_rules(): array {
        return [
            'userId' => 'required|integer'
        ];
    }

    public function process(): ApiResponse {
        // Check if file was uploaded
        if (!isset($_FILES['avatar'])) {
            return res_error('No file uploaded');
        }

        $file = $_FILES['avatar'];

        // Validate file
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return res_error('File upload failed');
        }

        // Validate file type
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        if (!in_array($file['type'], $allowedTypes)) {
            return res_error('Invalid file type');
        }

        // Validate file size (max 5MB)
        if ($file['size'] > 5 * 1024 * 1024) {
            return res_error('File too large');
        }

        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid('avatar_') . '.' . $extension;
        $uploadPath = '/var/www/uploads/avatars/' . $filename;

        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
            return res_error('Failed to save file');
        }

        // Update user avatar in database
        $result = Database::fn('fn_update_user_avatar', [
            $this->userId,
            $filename
        ]);

        if (empty($result)) {
            return res_error('Failed to update avatar');
        }

        return res_ok([
            'filename' => $filename,
            'url' => '/uploads/avatars/' . $filename
        ], 'Avatar uploaded successfully');
    }
}
```

### Example 4: Custom Validation

```php
<?php

namespace App\Routes;

use Framework\ApiResponse;
use Framework\IRouteHandler;
use Framework\Validator;
use Framework\Database;

class RegisterRoute implements IRouteHandler {
    public string $username;
    public string $email;
    public string $password;
    public string $password_confirmation;

    public function validation_rules(): array {
        return [
            'username' => 'required|string|min:3|max:20',
            'email' => 'required|email',
            'password' => 'required|string|min:8',
            'password_confirmation' => 'required|string'
        ];
    }

    public function process(): ApiResponse {
        // Additional custom validation
        $validator = Validator::make([
            'username' => $this->username,
            'email' => $this->email,
            'password' => $this->password
        ], []);

        // Check password confirmation
        if ($this->password !== $this->password_confirmation) {
            return res_error('Passwords do not match');
        }

        // Custom validator: check username availability
        $validator->addCustomValidator('username_available', function($value) {
            $result = Database::fn('fn_check_username', [$value]);
            return empty($result);
        });

        // Custom validator: check email availability
        $validator->addCustomValidator('email_available', function($value) {
            $result = Database::fn('fn_check_email', [$value]);
            return empty($result);
        });

        // Apply custom validators
        $customRules = [
            'username' => 'username_available',
            'email' => 'email_available'
        ];

        $customValidator = Validator::make([
            'username' => $this->username,
            'email' => $this->email
        ], $customRules);

        $customValidator->addCustomValidator('username_available', function($value) {
            $result = Database::fn('fn_check_username', [$value]);
            return empty($result);
        });

        $customValidator->addCustomValidator('email_available', function($value) {
            $result = Database::fn('fn_check_email', [$value]);
            return empty($result);
        });

        if (!$customValidator->validate()) {
            return res_error($customValidator->firstError());
        }

        // Create user
        $hashedPassword = password_hash($this->password, PASSWORD_DEFAULT);
        $result = Database::fn('fn_create_user', [
            $this->username,
            $this->email,
            $hashedPassword
        ]);

        if (empty($result)) {
            return res_error('Failed to create account');
        }

        return res_ok($result[0], 'Account created successfully');
    }
}
```

### Example 5: API with Nested Resources

```php
<?php

namespace App\Routes;

use Framework\ApiResponse;
use Framework\IRouteHandler;
use Framework\Database;

// GET /users/:userId/posts
class GetUserPostsRoute implements IRouteHandler {
    public int $userId;      // Route parameter
    public ?int $page;
    public ?int $limit;

    public function validation_rules(): array {
        return [
            'page' => 'integer|min:1',
            'limit' => 'integer|min:1|max:50'
        ];
    }

    public function process(): ApiResponse {
        // Check if user exists
        $user = Database::fn('fn_get_user', [$this->userId]);
        if (empty($user)) {
            return res_error('User not found');
        }

        $page = $this->page ?? 1;
        $limit = $this->limit ?? 10;
        $offset = ($page - 1) * $limit;

        // Get user's posts
        $posts = Database::fn('fn_get_user_posts', [
            $this->userId,
            $limit,
            $offset
        ]);

        return res_ok([
            'user' => $user[0],
            'posts' => $posts,
            'pagination' => [
                'page' => $page,
                'limit' => $limit
            ]
        ], 'Posts fetched successfully');
    }
}

// GET /users/:userId/posts/:postId
class GetUserPostRoute implements IRouteHandler {
    public int $userId;      // Route parameter
    public int $postId;      // Route parameter

    public function validation_rules(): array {
        return [];
    }

    public function process(): ApiResponse {
        $posts = Database::fn('fn_get_user_post', [
            $this->userId,
            $this->postId
        ]);

        if (empty($posts)) {
            return res_error('Post not found');
        }

        return res_ok($posts[0], 'Post fetched successfully');
    }
}

// POST /users/:userId/posts
class CreateUserPostRoute implements IRouteHandler {
    public int $userId;      // Route parameter
    public string $title;
    public string $content;
    public ?array $tags;

    public function validation_rules(): array {
        return [
            'title' => 'required|string|min:5|max:200',
            'content' => 'required|string|min:10',
            'tags' => 'array|max:10'
        ];
    }

    public function process(): ApiResponse {
        // Check if user exists
        $user = Database::fn('fn_get_user', [$this->userId]);
        if (empty($user)) {
            return res_error('User not found');
        }

        // Create post
        $tags = json_encode($this->tags ?? []);
        $posts = Database::fn('fn_create_post', [
            $this->userId,
            $this->title,
            $this->content,
            $tags
        ]);

        if (empty($posts)) {
            return res_error('Failed to create post');
        }

        return res_ok($posts[0], 'Post created successfully');
    }
}
```

**Nested Routes Configuration:**
```php
// src/config/routes.php
return [
    'GET' => [
        '/users/:userId/posts' => App\Routes\GetUserPostsRoute::class,
        '/users/:userId/posts/:postId' => App\Routes\GetUserPostRoute::class
    ],
    'POST' => [
        '/users/:userId/posts' => App\Routes\CreateUserPostRoute::class
    ],
    'PUT' => [
        '/users/:userId/posts/:postId' => App\Routes\UpdateUserPostRoute::class
    ],
    'DELETE' => [
        '/users/:userId/posts/:postId' => App\Routes\DeleteUserPostRoute::class
    ]
];
```

---

## Best Practices

### 1. Always Use Validation

```php
// Good
public function validation_rules(): array {
    return [
        'email' => 'required|email',
        'name' => 'required|string|min:2'
    ];
}

// Bad - No validation
public function validation_rules(): array {
    return [];
}
```

### 2. Use Helper Functions

```php
// Good
return res_ok($data, 'Success');
return res_error('Failed');

// Less concise
return new ApiResponse('ok', 'Success', $data);
return new ApiResponse('error', 'Failed');
```

### 3. Handle Database Errors

```php
public function process(): ApiResponse {
    $result = Database::fn('fn_get_user', [$this->id]);

    // Always check for empty results
    if (empty($result)) {
        return res_error('User not found');
    }

    return res_ok($result[0]);
}
```

### 4. Use Type Hints

```php
// Good
public string $email;
public int $age;
public ?array $tags;

// Less clear
public $email;
public $age;
public $tags;
```

### 5. Organize Routes Logically

```php
// Group related routes
namespace App\Routes\Users;
namespace App\Routes\Posts;
namespace App\Routes\Auth;
```

### 6. Use Middleware for Cross-Cutting Concerns

```php
// Good - Reusable middleware
$router->useMany([
    new CorsMiddleware(),
    new LoggingMiddleware(),
    new SecurityHeadersMiddleware()
]);

// Bad - Duplicate logic in every route
```

---

## See Also

- [Getting Started Guide](getting-started.md)
- [Validation Guide](validation.md)
- [Middleware Guide](MIDDLEWARE.md)
- [CLI Usage](../CLI-USAGE.md)
