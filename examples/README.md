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

### 7. Multi-Tenancy Example

StoneScriptPHP supports multiple tenancy strategies out of the box:

#### **Strategy 1: Per-Tenant Database Isolation**

Each tenant gets their own dedicated PostgreSQL database for complete data isolation.

```php
<?php
use Framework\Routing\Router;
use Framework\Tenancy\TenantResolver;
use Framework\Tenancy\TenantConnectionManager;
use Framework\Http\Middleware\JwtAuthMiddleware;
use Framework\Http\Middleware\TenantMiddleware;

// Connect to central auth database
$authDb = new PDO('pgsql:host=localhost;dbname=auth', 'user', 'pass');

// Setup tenant resolver (tries JWT, then header, then subdomain)
$tenantResolver = new TenantResolver($authDb, ['jwt', 'header', 'subdomain']);

// Setup router with tenant middleware
$router = new Router();

// Add JWT auth middleware (extracts user from token)
$router->use(new JwtAuthMiddleware($jwtHandler, ['/api/health']));

// Add tenant middleware (resolves tenant from JWT or other sources)
$router->use(new TenantMiddleware($tenantResolver, ['/api/health']));

// All routes automatically have tenant context
$router->get('/api/products', function($request) {
    // Get current tenant
    $tenant = tenant();

    // Get tenant database connection (automatically uses connection pooling)
    $db = tenant_db();

    // Query tenant's products from their dedicated database
    $stmt = $db->query('SELECT * FROM products ORDER BY created_at DESC');
    $products = $stmt->fetchAll();

    return new Framework\ApiResponse('success', 'Products retrieved', [
        'tenant_id' => tenant_id(),
        'tenant_slug' => tenant_slug(),
        'products' => $products
    ]);
});

$router->post('/api/products', function($request) {
    $db = tenant_db();
    $data = $request['body'];

    $stmt = $db->prepare('INSERT INTO products (name, price) VALUES (?, ?) RETURNING *');
    $stmt->execute([$data['name'], $data['price']]);
    $product = $stmt->fetch();

    return new Framework\ApiResponse('success', 'Product created', $product);
});

$router->handle();
```

#### **Strategy 2: Shared Database with Automatic Filtering**

All tenants share the same database, but data is isolated using `tenant_id` column with automatic filtering.

```php
<?php
use Framework\Tenancy\TenantQueryBuilder;

$router->get('/api/products', function($request) {
    // Get shared database connection
    $db = get_db_connection();

    // TenantQueryBuilder automatically adds tenant_id filter to all queries
    $builder = new TenantQueryBuilder($db, 'products');

    // SELECT * FROM products WHERE tenant_id = ? (automatically added)
    $products = $builder->all();

    return new Framework\ApiResponse('success', 'Products', ['products' => $products]);
});

$router->post('/api/products', function($request) {
    $db = get_db_connection();
    $builder = new TenantQueryBuilder($db, 'products');

    // INSERT INTO products (name, price, tenant_id) VALUES (?, ?, ?)
    // tenant_id is automatically added
    $productId = $builder->insert([
        'name' => $request['body']['name'],
        'price' => $request['body']['price']
    ]);

    $product = $builder->find($productId);

    return new Framework\ApiResponse('success', 'Product created', $product);
});
```

#### **Tenant Provisioning via CLI**

Create and manage tenants using the built-in CLI:

```bash
# Create new tenant with dedicated database
php stone tenant:create "Acme Corporation" acme --email=admin@acme.com

# List all tenants
php stone tenant:list

# Check tenant status
php stone tenant:status acme

# Run migrations on tenant database
php stone tenant:migrate acme

# Seed tenant database
php stone tenant:seed acme

# Suspend tenant access
php stone tenant:suspend acme

# Reactivate tenant
php stone tenant:activate acme

# Delete tenant (optionally drop database)
php stone tenant:delete acme --drop-db
```

#### **Tenant Resolution Strategies**

Tenants can be resolved from multiple sources (tried in order):

1. **JWT Token Claims**: `tenant_id`, `tenant_uuid`, `tenant_slug`
2. **HTTP Headers**: `X-Tenant-ID`, `X-Tenant-UUID`, `X-Tenant-Slug`
3. **Subdomain**: `{tenant}.example.com` → resolves `tenant` slug
4. **Domain**: `tenant.com` → looks up domain in database
5. **Route Parameter**: `/tenant/{slug}/...` → extracts from route

#### **Tenant Helper Functions**

```php
// Get current tenant object
$tenant = tenant();

// Get tenant properties
$id = tenant_id();           // Tenant ID
$uuid = tenant_uuid();       // Tenant UUID
$slug = tenant_slug();       // Tenant slug
$dbName = tenant_db_name();  // Database name

// Check if tenant context is set
if (tenant_check()) {
    echo "Tenant: " . tenant()->slug;
}

// Get tenant database connection
$db = tenant_db();

// Get tenant metadata
$plan = tenant_get('subscription_plan', 'free');
$maxUsers = tenant_get('max_users', 10);
```

#### **Use Cases by Application Type**

**B2B SaaS (MedStoreApp-style)**: Use per-tenant database isolation
- Each customer gets dedicated database
- Complete data isolation and security
- Easier compliance (HIPAA, SOC 2)
- Can customize schema per tenant if needed

**B2C Social Platform (ProGalaxy-style)**: Use shared database
- All users share same database
- Filter by `tenant_id` (can represent organization, workspace, etc.)
- Better resource utilization
- Simpler backups and migrations

**Website Builder (WebMeteor-style)**: Use hybrid approach
- Free tier: Shared database with `tenant_id`
- Pro tier: Dedicated database per tenant
- Automatically upgrade tenant's database on plan change

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
