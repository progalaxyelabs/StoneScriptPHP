# StoneScriptPHP Examples

This directory contains code examples demonstrating framework features.

## Available Examples

### 1. Middleware Example
**Location:** `middleware/`

A complete working example demonstrating the middleware system.

**Run it:**
```bash
cd examples/middleware
php -S localhost:8080 index.php
```

**Features demonstrated:**
- Authentication middleware
- CORS handling
- Rate limiting
- Security headers
- JSON body parsing
- Request logging

**See:** [middleware/README.md](middleware/README.md) for full documentation.

### 2. Validation Example
**Location:** `validation-example.php`

Demonstrates route validation with custom rules.

```php
<?php
use Framework\Validator;

class CreateUserRoute implements Framework\IRouteHandler
{
    public string $name;
    public string $email;
    public int $age;

    public function validation_rules(): array
    {
        return [
            'name' => ['required', 'min:3', 'max:50'],
            'email' => ['required', 'email'],
            'age' => ['required', 'integer', 'min:18']
        ];
    }

    public function process(): Framework\ApiResponse
    {
        // Validation passed, create user
        return new Framework\ApiResponse('success', 'User created', [
            'name' => $this->name,
            'email' => $this->email,
            'age' => $this->age
        ]);
    }
}
```

### 3. Cache Example
**Location:** `cache-example.php`

Demonstrates caching with tags and invalidation.

```php
<?php
use Framework\Cache;

// Basic caching
$cache = Cache::get_instance();
$cache->set('user:123', ['name' => 'John'], 3600);
$user = $cache->get('user:123');

// Tagged caching
$cache->tags(['users', 'active'])->set('user:123', $data);
$cache->tags(['users'])->flush(); // Invalidate all user cache

// Helper functions
cache_remember('key', fn() => expensiveOperation(), 3600);
cache_forget('key');
```

### 4. JWT Authentication Example

```php
<?php
use Framework\Auth\RsaJwtHandler;
use Framework\Http\Middleware\JwtAuthMiddleware;

// Setup JWT handler
$jwtHandler = new RsaJwtHandler();

// Generate token (e.g., during login)
$token = $jwtHandler->generateToken([
    'user_id' => 123,
    'email' => 'user@example.com',
    'display_name' => 'John Doe',
    'user_role' => 'admin',
    'tenant_id' => 456
]);

// Use middleware to protect routes
$router->use(new JwtAuthMiddleware($jwtHandler, [
    '/api/public/*' // Excluded paths
]));

// Access authenticated user in route handlers
$user = auth();
echo $user->user_id;        // 123
echo $user->email;          // user@example.com
echo $user->display_name;   // John Doe
echo $user->user_role;      // admin

// Load full user from database
$dbUser = auth_load($db, function($user, $db) {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user->user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
});
```

### 5. Database Functions Example

```php
<?php
// Define PostgreSQL function
// src/postgresql/functions/get_user_by_id.pgsql
CREATE OR REPLACE FUNCTION get_user_by_id(i_user_id INTEGER)
RETURNS TABLE (
    o_id INTEGER,
    o_name TEXT,
    o_email TEXT
)
LANGUAGE plpgsql
AS $$
BEGIN
    RETURN QUERY
    SELECT id, name, email
    FROM users
    WHERE id = i_user_id;
END;
$$;

// Generate PHP model
// $ php stone generate model get_user_by_id.pgsql

// Use in your code
use App\Database\Functions\FnGetUserById;

$user = FnGetUserById::run(123);
echo $user->name;  // Access user data
echo $user->email;
```

### 6. Router Example

```php
<?php
use Framework\Routing\Router;
use Framework\Http\Middleware\CorsMiddleware;

$router = new Router();

// Add global middleware
$router->use(new CorsMiddleware(['http://localhost:3000']));

// Define routes
$router->get('/api/users', function($request) {
    return new Framework\ApiResponse('success', 'Users list');
});

$router->post('/api/users', function($request) {
    $data = $request['body'];
    return new Framework\ApiResponse('success', 'User created', $data);
});

// Route with parameters
$router->get('/api/users/:id', function($request) {
    $userId = $request['params']['id'];
    return new Framework\ApiResponse('success', 'User detail', ['id' => $userId]);
});

// Process request
$response = $router->handle();
```

## More Examples

For more examples, see:
- **Documentation:** `/docs` directory
- **Tests:** `/tests` directory
- **Server Skeleton:** [StoneScriptPHP-Server](https://github.com/progalaxyelabs/stonescriptphp-server) repository

## Running Examples

Most examples are code snippets for reference. The `middleware/` example is a complete runnable application.

To create your own working project:

```bash
composer create-project progalaxyelabs/stonescriptphp-server myproject
cd myproject
php stone setup
php -S localhost:8000 -t public
```
