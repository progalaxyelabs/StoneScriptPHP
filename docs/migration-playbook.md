# Migration Playbook: Converting PHP Projects to StoneScriptPHP

This comprehensive guide provides step-by-step instructions for migrating existing PHP projects (vanilla PHP or Laravel) to StoneScriptPHP.

## Table of Contents

1. [Pre-Migration Assessment](#pre-migration-assessment)
2. [Migration Strategy](#migration-strategy)
3. [Migration Path 1: Vanilla PHP to StoneScriptPHP](#migration-path-1-vanilla-php-to-stonescriptphp)
4. [Migration Path 2: Laravel to StoneScriptPHP](#migration-path-2-laravel-to-stonescriptphp)
5. [Database Migration](#database-migration)
6. [Authentication Migration](#authentication-migration)
7. [Testing Strategy](#testing-strategy)
8. [Deployment and Rollback](#deployment-and-rollback)
9. [Common Pitfalls](#common-pitfalls)
10. [Post-Migration Optimization](#post-migration-optimization)

## Pre-Migration Assessment

Before starting the migration, conduct a thorough assessment of your existing project.

### 1. Inventory Your Current Project

Create a comprehensive inventory:

```bash
# Document your current project structure
tree -L 3 > project-structure.txt

# Count PHP files
find . -name "*.php" | wc -l

# Identify database usage
grep -r "mysql\|mysqli\|PDO" --include="*.php" | wc -l

# Find all route definitions
grep -r "Route::\|->get\|->post" --include="*.php"

# List external dependencies
cat composer.json | jq '.require'
```

### 2. Assessment Checklist

Create a migration assessment document:

- [ ] **Current PHP Version**: _________
- [ ] **Database**: MySQL / PostgreSQL / Other: _________
- [ ] **Authentication Method**: Session / JWT / OAuth / Other: _________
- [ ] **Number of Routes/Endpoints**: _________
- [ ] **Number of Database Tables**: _________
- [ ] **External Dependencies**: List all critical packages
- [ ] **Third-party APIs**: List all integrations
- [ ] **File Upload Handling**: Yes / No
- [ ] **Background Jobs/Queues**: Yes / No
- [ ] **WebSocket/Real-time Features**: Yes / No
- [ ] **Testing Coverage**: _____%

### 3. Decision Matrix

| Criteria | Keep Current | Migrate to StoneScriptPHP |
|----------|-------------|---------------------------|
| Database is PostgreSQL | | ✓ Best fit |
| Database is MySQL | ✓ Consider staying | May require DB migration |
| Complex ORM relationships | ✓ Laravel Eloquent | SQL functions approach |
| Need rapid API development | | ✓ CLI generators |
| Need Angular-like DX | | ✓ Perfect match |
| Team knows PostgreSQL | | ✓ Good fit |
| Microservices architecture | | ✓ Excellent fit |

## Migration Strategy

### Recommended Approaches

#### 1. **Strangler Fig Pattern** (Recommended)
Gradually replace old code with new StoneScriptPHP code:
- Run both systems in parallel
- Migrate routes one at a time
- Use reverse proxy to route requests
- Lowest risk, longer timeline

#### 2. **Big Bang Migration**
Complete rewrite in StoneScriptPHP:
- Faster overall
- Higher risk
- Requires comprehensive testing
- Best for smaller projects (<50 endpoints)

#### 3. **Hybrid Approach**
Keep existing system, add new features in StoneScriptPHP:
- New endpoints use StoneScriptPHP
- Legacy endpoints remain unchanged
- Gradual team learning curve

### Migration Timeline Template

```
Week 1-2: Setup and Planning
  - Set up StoneScriptPHP development environment
  - Database schema analysis
  - Create migration plan

Week 3-4: Database Migration
  - Convert to PostgreSQL (if needed)
  - Create .pssql schema files
  - Migrate business logic to SQL functions

Week 5-8: Route Migration (25% per week)
  - Migrate authentication first
  - Migrate public routes second
  - Migrate protected routes third
  - Migrate admin routes last

Week 9: Testing and Bug Fixes
  - Integration testing
  - Load testing
  - Security audit

Week 10: Deployment
  - Deploy to staging
  - User acceptance testing
  - Production deployment
```

## Migration Path 1: Vanilla PHP to StoneScriptPHP

### Step 1: Environment Setup

#### 1.1 Install StoneScriptPHP

```bash
# Create new StoneScriptPHP project
composer create-project progalaxyelabs/stonescriptphp my-api-v2

cd my-api-v2

# Run interactive setup
php stone setup
```

#### 1.2 Configure Database

If using MySQL, you need to migrate to PostgreSQL:

```bash
# Install PostgreSQL
sudo apt-get install postgresql postgresql-contrib

# Create database
sudo -u postgres createdb my_api_db
sudo -u postgres createuser my_api_user
sudo -u postgres psql -c "ALTER USER my_api_user WITH PASSWORD 'secure_password';"
sudo -u postgres psql -c "GRANT ALL PRIVILEGES ON DATABASE my_api_db TO my_api_user;"
```

Update `.env`:

```ini
DATABASE_HOST=localhost
DATABASE_PORT=5432
DATABASE_USER=my_api_user
DATABASE_PASSWORD=secure_password
DATABASE_DBNAME=my_api_db
DATABASE_SSLMODE=prefer
DATABASE_TIMEOUT=30
DATABASE_APPNAME=StoneScriptPHP
```

### Step 2: Database Schema Migration

#### 2.1 Export Existing Schema

For MySQL:

```bash
# Export schema
mysqldump -u root -p --no-data my_old_db > schema.sql

# Export data
mysqldump -u root -p my_old_db > data.sql
```

#### 2.2 Convert to PostgreSQL

Use a conversion tool like `mysql2postgres` or convert manually.

**Example conversion:**

MySQL:
```sql
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

PostgreSQL:
```sql
-- src/postgresql/tables/users.pssql
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);
```

#### 2.3 Create .pssql Files

For each table, create a `.pssql` file in `src/postgresql/tables/`:

```bash
# Example structure
src/postgresql/tables/
├── users.pssql
├── products.pssql
├── orders.pssql
└── order_items.pssql
```

#### 2.4 Apply Schema

```bash
# Apply all table schemas
for file in src/postgresql/tables/*.pssql; do
    psql -h localhost -U my_api_user -d my_api_db -f "$file"
done
```

### Step 3: Migrate Business Logic to SQL Functions

#### 3.1 Identify Business Logic in PHP

**Before (Vanilla PHP):**
```php
// old-code/get_user.php
function getUserByEmail($email) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
```

#### 3.2 Convert to SQL Function

**After (StoneScriptPHP):**
```sql
-- src/postgresql/functions/get_user_by_email.pssql
CREATE OR REPLACE FUNCTION get_user_by_email(
    p_email VARCHAR
)
RETURNS TABLE (
    id INTEGER,
    email VARCHAR,
    name VARCHAR,
    created_at TIMESTAMP
) AS $$
BEGIN
    RETURN QUERY
    SELECT
        u.id,
        u.email,
        u.name,
        u.created_at
    FROM users u
    WHERE u.email = p_email;
END;
$$ LANGUAGE plpgsql;
```

Apply the function:
```bash
psql -h localhost -U my_api_user -d my_api_db -f src/postgresql/functions/get_user_by_email.pssql
```

#### 3.3 Generate PHP Model

```bash
php stone generate model get_user_by_email.pssql
```

This creates `src/App/Models/FnGetUserByEmail.php`.

### Step 4: Migrate Routes

#### 4.1 Map Old Routes to New Routes

Create a mapping document:

| Old Route | Method | New Route | Handler Class |
|-----------|--------|-----------|---------------|
| `/api/user.php?email=x` | GET | `/api/user` | GetUserRoute |
| `/api/login.php` | POST | `/api/login` | LoginRoute |
| `/api/products.php` | GET | `/api/products` | ListProductsRoute |

#### 4.2 Generate Route Handlers

```bash
php stone generate route get-user
php stone generate route login
php stone generate route list-products
```

#### 4.3 Implement Route Logic

**Before (Vanilla PHP):**
```php
// old-code/api/user.php
<?php
require_once '../config.php';
require_once '../functions.php';

$email = $_GET['email'] ?? null;

if (!$email) {
    http_response_code(400);
    echo json_encode(['error' => 'Email required']);
    exit;
}

$user = getUserByEmail($email);

if (!$user) {
    http_response_code(404);
    echo json_encode(['error' => 'User not found']);
    exit;
}

echo json_encode(['success' => true, 'user' => $user]);
```

**After (StoneScriptPHP):**
```php
// src/App/Routes/GetUserRoute.php
<?php

namespace App\Routes;

use App\Models\FnGetUserByEmail;
use Framework\Http\Request;
use Framework\Http\ApiResponse;

class GetUserRoute
{
    public function process(Request $request)
    {
        $email = $request->query['email'] ?? null;

        if (!$email) {
            return new ApiResponse('error', 'Email parameter required', null);
        }

        $user = FnGetUserByEmail::run($email);

        if (!$user) {
            return new ApiResponse('error', 'User not found', null);
        }

        return new ApiResponse('ok', 'User found', $user);
    }
}
```

#### 4.4 Register Routes

Update `src/config/routes.php`:

```php
<?php

use App\Routes\GetUserRoute;
use App\Routes\LoginRoute;
use App\Routes\ListProductsRoute;

return [
    'GET' => [
        '/api/user' => GetUserRoute::class,
        '/api/products' => ListProductsRoute::class,
    ],
    'POST' => [
        '/api/login' => LoginRoute::class,
    ],
];
```

### Step 5: Configure CORS

Update `src/config/allowed_origins.php`:

```php
<?php

return [
    'http://localhost:3000',
    'http://localhost:4200',
    'https://your-production-domain.com',
];
```

### Step 6: Testing

Create tests for each migrated route:

```php
// tests/Feature/GetUserTest.php
<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use App\Routes\GetUserRoute;
use Framework\Http\Request;

class GetUserTest extends TestCase
{
    public function testGetUserSuccess()
    {
        $request = new Request('GET', '/api/user', [], ['email' => 'test@example.com'], '');
        $route = new GetUserRoute();
        $response = $route->process($request);

        $this->assertEquals('ok', $response->status);
        $this->assertNotNull($response->data);
    }

    public function testGetUserMissingEmail()
    {
        $request = new Request('GET', '/api/user', [], [], '');
        $route = new GetUserRoute();
        $response = $route->process($request);

        $this->assertEquals('error', $response->status);
        $this->assertEquals('Email parameter required', $response->message);
    }
}
```

Run tests:
```bash
php stone test
```

## Migration Path 2: Laravel to StoneScriptPHP

### Step 1: Analyze Laravel Project

#### 1.1 Map Laravel Concepts to StoneScriptPHP

| Laravel | StoneScriptPHP |
|---------|----------------|
| Controllers | Route Classes |
| Eloquent Models | SQL Functions + PHP Models |
| Migrations | .pssql Files |
| Routes (web.php) | src/config/routes.php |
| Middleware | Middleware Classes |
| Service Providers | Manual DI (if needed) |
| Validation | Request Validation |
| Jobs/Queues | External Queue System |

### Step 2: Database Migration

#### 2.1 Export Laravel Migrations

```bash
# List all migrations
php artisan migrate:status

# Export schema
php artisan schema:dump
```

#### 2.2 Convert Eloquent Models to SQL Functions

**Before (Laravel Eloquent):**
```php
// app/Models/User.php
class User extends Model
{
    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    public function getActivePostsAttribute()
    {
        return $this->posts()->where('status', 'active')->get();
    }
}

// Usage
$user = User::find(1);
$activePosts = $user->activePosts;
```

**After (StoneScriptPHP):**

Create SQL function:
```sql
-- src/postgresql/functions/get_user_with_active_posts.pssql
CREATE OR REPLACE FUNCTION get_user_with_active_posts(
    p_user_id INTEGER
)
RETURNS TABLE (
    user_id INTEGER,
    user_name VARCHAR,
    post_id INTEGER,
    post_title VARCHAR,
    post_status VARCHAR
) AS $$
BEGIN
    RETURN QUERY
    SELECT
        u.id AS user_id,
        u.name AS user_name,
        p.id AS post_id,
        p.title AS post_title,
        p.status AS post_status
    FROM users u
    LEFT JOIN posts p ON u.id = p.user_id
    WHERE u.id = p_user_id
      AND p.status = 'active';
END;
$$ LANGUAGE plpgsql;
```

Generate model:
```bash
php stone generate model get_user_with_active_posts.pssql
```

Usage:
```php
$result = FnGetUserWithActivePosts::run(1);
```

### Step 3: Migrate Laravel Controllers to Routes

#### 3.1 Convert Controller Methods

**Before (Laravel Controller):**
```php
// app/Http/Controllers/UserController.php
namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function show($id)
    {
        $user = User::findOrFail($id);
        return response()->json($user);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
        ]);

        $user = User::create($validated);
        return response()->json($user, 201);
    }
}
```

**After (StoneScriptPHP Routes):**

Create SQL functions:
```sql
-- src/postgresql/functions/get_user_by_id.pssql
CREATE OR REPLACE FUNCTION get_user_by_id(
    p_id INTEGER
)
RETURNS TABLE (
    id INTEGER,
    name VARCHAR,
    email VARCHAR,
    created_at TIMESTAMP
) AS $$
BEGIN
    RETURN QUERY
    SELECT u.id, u.name, u.email, u.created_at
    FROM users u
    WHERE u.id = p_id;
END;
$$ LANGUAGE plpgsql;
```

```sql
-- src/postgresql/functions/create_user.pssql
CREATE OR REPLACE FUNCTION create_user(
    p_name VARCHAR,
    p_email VARCHAR
)
RETURNS TABLE (
    id INTEGER,
    name VARCHAR,
    email VARCHAR,
    created_at TIMESTAMP
) AS $$
DECLARE
    v_user_id INTEGER;
BEGIN
    INSERT INTO users (name, email)
    VALUES (p_name, p_email)
    RETURNING id INTO v_user_id;

    RETURN QUERY
    SELECT u.id, u.name, u.email, u.created_at
    FROM users u
    WHERE u.id = v_user_id;
END;
$$ LANGUAGE plpgsql;
```

Generate models:
```bash
php stone generate model get_user_by_id.pssql
php stone generate model create_user.pssql
```

Create routes:
```bash
php stone generate route get-user
php stone generate route create-user
```

Implement routes with validation:
```php
// src/App/Routes/GetUserRoute.php
<?php

namespace App\Routes;

use App\Models\FnGetUserById;
use Framework\Http\Request;
use Framework\Http\ApiResponse;

class GetUserRoute
{
    public function process(Request $request)
    {
        $id = $request->params['id'] ?? null;

        if (!$id || !is_numeric($id)) {
            return new ApiResponse('error', 'Valid user ID required', null);
        }

        $user = FnGetUserById::run((int)$id);

        if (!$user) {
            return new ApiResponse('error', 'User not found', null);
        }

        return new ApiResponse('ok', 'User found', $user);
    }
}
```

```php
// src/App/Routes/CreateUserRoute.php
<?php

namespace App\Routes;

use App\Models\FnCreateUser;
use Framework\Http\Request;
use Framework\Http\ApiResponse;
use Framework\Validation\Validator;

class CreateUserRoute
{
    public function process(Request $request)
    {
        $validator = new Validator($request->body, [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
        ]);

        if (!$validator->validate()) {
            return new ApiResponse('error', 'Validation failed', $validator->errors());
        }

        try {
            $user = FnCreateUser::run(
                $request->body['name'],
                $request->body['email']
            );

            return new ApiResponse('ok', 'User created', $user);
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'duplicate key') !== false) {
                return new ApiResponse('error', 'Email already exists', null);
            }
            return new ApiResponse('error', 'Failed to create user', null);
        }
    }
}
```

### Step 4: Migrate Laravel Routes

**Before (Laravel):**
```php
// routes/api.php
Route::get('/users/{id}', [UserController::class, 'show']);
Route::post('/users', [UserController::class, 'store']);
Route::put('/users/{id}', [UserController::class, 'update']);
Route::delete('/users/{id}', [UserController::class, 'destroy']);
```

**After (StoneScriptPHP):**
```php
// src/config/routes.php
<?php

use App\Routes\GetUserRoute;
use App\Routes\CreateUserRoute;
use App\Routes\UpdateUserRoute;
use App\Routes\DeleteUserRoute;

return [
    'GET' => [
        '/users/{id}' => GetUserRoute::class,
    ],
    'POST' => [
        '/users' => CreateUserRoute::class,
    ],
    'PUT' => [
        '/users/{id}' => UpdateUserRoute::class,
    ],
    'DELETE' => [
        '/users/{id}' => DeleteUserRoute::class,
    ],
];
```

### Step 5: Migrate Laravel Middleware

**Before (Laravel):**
```php
// app/Http/Middleware/CheckApiKey.php
namespace App\Http\Middleware;

class CheckApiKey
{
    public function handle($request, Closure $next)
    {
        if ($request->header('X-API-Key') !== config('app.api_key')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
```

**After (StoneScriptPHP):**
```php
// src/App/Middleware/ApiKeyMiddleware.php
<?php

namespace App\Middleware;

use Framework\Http\MiddlewareInterface;
use Framework\Http\ApiResponse;
use Framework\Env;

class ApiKeyMiddleware implements MiddlewareInterface
{
    public function handle(array $request, callable $next): ?ApiResponse
    {
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
        $validKey = Env::get('API_KEY');

        if ($apiKey !== $validKey) {
            http_response_code(401);
            return new ApiResponse('error', 'Unauthorized', null);
        }

        return $next($request);
    }
}
```

Apply middleware:
```php
// public/index.php
$router = new Router();
$router->use(new ApiKeyMiddleware());
```

### Step 6: Migrate Authentication

**Before (Laravel Sanctum):**
```php
// Using Laravel Sanctum tokens
$token = $user->createToken('api-token')->plainTextToken;
```

**After (StoneScriptPHP JWT):**
```php
// src/App/Routes/LoginRoute.php
use Framework\Security\JWT;

$payload = [
    'user_id' => $user['id'],
    'email' => $user['email'],
    'exp' => time() + (60 * 60 * 24 * 7), // 7 days
];

$token = JWT::encode($payload);

return new ApiResponse('ok', 'Login successful', [
    'token' => $token,
    'user' => $user,
]);
```

Protected route:
```php
// src/App/Routes/ProtectedRoute.php
use Framework\Security\JWT;

public function process(Request $request)
{
    $authHeader = $request->headers['Authorization'] ?? '';

    if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        return new ApiResponse('error', 'No token provided', null);
    }

    try {
        $payload = JWT::decode($matches[1]);
        $userId = $payload['user_id'];

        // Your protected logic here
        return new ApiResponse('ok', 'Access granted', ['user_id' => $userId]);

    } catch (\Exception $e) {
        return new ApiResponse('error', 'Invalid token', null);
    }
}
```

## Database Migration

### MySQL to PostgreSQL Data Migration

#### Option 1: Using pgloader

```bash
# Install pgloader
sudo apt-get install pgloader

# Create pgloader config
cat > migrate.load << EOF
LOAD DATABASE
    FROM mysql://user:password@localhost/old_db
    INTO postgresql://user:password@localhost/new_db
    WITH include drop, create tables, create indexes, reset sequences
    SET maintenance_work_mem to '512MB', work_mem to '64MB'
    CAST type datetime to timestamptz drop default drop not null using zero-dates-to-null,
         type date drop not null drop default using zero-dates-to-null;
EOF

# Run migration
pgloader migrate.load
```

#### Option 2: Manual Export/Import

```bash
# Export from MySQL
mysqldump -u root -p --compatible=postgresql --no-create-info old_db > data.sql

# Manual conversion (fix syntax differences)
# AUTO_INCREMENT -> SERIAL
# TINYINT(1) -> BOOLEAN
# datetime -> timestamp

# Import to PostgreSQL
psql -U user -d new_db -f data_converted.sql
```

### Data Validation

```sql
-- Compare record counts
-- MySQL
SELECT table_name, table_rows
FROM information_schema.tables
WHERE table_schema = 'old_db';

-- PostgreSQL
SELECT schemaname, tablename, n_live_tup
FROM pg_stat_user_tables;
```

## Authentication Migration

### Session-based to JWT Migration

#### Before (Session-based):
```php
session_start();
$_SESSION['user_id'] = $user['id'];
```

#### After (JWT):
```php
$token = JWT::encode([
    'user_id' => $user['id'],
    'exp' => time() + 3600
]);
```

### OAuth Migration

StoneScriptPHP includes Google OAuth support. For other providers, create custom OAuth classes:

```php
// src/App/Oauth/FacebookOauth.php
<?php

namespace App\Oauth;

class FacebookOauth
{
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;

    public function __construct()
    {
        $this->clientId = Env::get('FACEBOOK_CLIENT_ID');
        $this->clientSecret = Env::get('FACEBOOK_CLIENT_SECRET');
        $this->redirectUri = Env::get('FACEBOOK_REDIRECT_URI');
    }

    public function getAuthUrl(): string
    {
        $params = http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => 'email',
            'response_type' => 'code',
        ]);

        return "https://www.facebook.com/v12.0/dialog/oauth?{$params}";
    }

    public function getUserInfo(string $code): array
    {
        // Exchange code for access token
        // Fetch user info
        // Return user data
    }
}
```

## Testing Strategy

### Test Migration Process

1. **Unit Tests**: Test SQL functions directly in PostgreSQL
2. **Integration Tests**: Test route handlers
3. **API Tests**: Test full request/response cycle
4. **Performance Tests**: Compare with old system

### Example Test Suite

```php
// tests/Integration/UserApiTest.php
<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

class UserApiTest extends TestCase
{
    private string $baseUrl = 'http://localhost:9100';

    public function testGetUser()
    {
        $ch = curl_init("{$this->baseUrl}/api/user?email=test@example.com");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $data = json_decode($response, true);

        $this->assertEquals('ok', $data['status']);
        $this->assertArrayHasKey('data', $data);
    }

    public function testCreateUser()
    {
        $ch = curl_init("{$this->baseUrl}/api/user");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'name' => 'Test User',
            'email' => 'new@example.com',
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $data = json_decode($response, true);

        $this->assertEquals('ok', $data['status']);
    }
}
```

### Performance Testing

```bash
# Install Apache Bench
sudo apt-get install apache2-utils

# Test endpoint performance
ab -n 1000 -c 10 http://localhost:9100/api/products

# Compare with old system
ab -n 1000 -c 10 http://old-api.com/api/products
```

## Deployment and Rollback

### Blue-Green Deployment

```
┌─────────────┐
│ Load Balancer│
└──────┬──────┘
       │
   ┌───┴────┐
   │        │
┌──▼──┐  ┌─▼───┐
│Blue │  │Green│
│(Old)│  │(New)│
└─────┘  └─────┘
```

Steps:
1. Deploy StoneScriptPHP to Green environment
2. Run smoke tests
3. Switch load balancer to Green
4. Monitor for issues
5. Keep Blue running for quick rollback

### Rollback Plan

```bash
# Create rollback script
cat > rollback.sh << 'EOF'
#!/bin/bash
echo "Rolling back to old system..."

# Switch load balancer
# or switch DNS
# or switch reverse proxy config

echo "Rollback complete"
EOF

chmod +x rollback.sh
```

### Gradual Traffic Switching

Use nginx for gradual traffic switching:

```nginx
upstream backend {
    server old-api:8000 weight=9;  # 90% traffic
    server new-api:9100 weight=1;  # 10% traffic
}
```

Gradually increase weight to new API.

## Common Pitfalls

### 1. Date/Time Handling

**Problem**: MySQL and PostgreSQL handle dates differently

**Solution**:
```sql
-- Use explicit timestamp with time zone
CREATE TABLE logs (
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
```

### 2. Auto-Increment IDs

**Problem**: MySQL AUTO_INCREMENT vs PostgreSQL SERIAL

**Solution**:
```sql
-- Don't forget to reset sequences after data import
SELECT setval('users_id_seq', (SELECT MAX(id) FROM users));
```

### 3. String Comparison

**Problem**: PostgreSQL is case-sensitive by default

**Solution**:
```sql
-- Use ILIKE for case-insensitive matching
WHERE email ILIKE '%example.com';

-- Or use LOWER()
WHERE LOWER(email) = LOWER($1);
```

### 4. Transaction Handling

**Problem**: Long-running transactions in SQL functions

**Solution**:
```sql
-- Keep functions small and focused
-- Don't mix DDL and DML in functions
-- Use EXCEPTION blocks carefully
```

### 5. N+1 Query Problems

**Problem**: Multiple database calls in loops

**Solution**:
```sql
-- Use JOINs in SQL functions instead of multiple queries
CREATE OR REPLACE FUNCTION get_users_with_posts()
RETURNS TABLE (...) AS $$
BEGIN
    RETURN QUERY
    SELECT u.*, p.*
    FROM users u
    LEFT JOIN posts p ON u.id = p.user_id;
END;
$$ LANGUAGE plpgsql;
```

## Post-Migration Optimization

### 1. Database Indexing

```sql
-- Analyze query performance
EXPLAIN ANALYZE SELECT * FROM users WHERE email = 'test@example.com';

-- Add indexes as needed
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_posts_user_id ON posts(user_id);
CREATE INDEX idx_posts_created_at ON posts(created_at DESC);
```

### 2. Connection Pooling

Use PgBouncer for connection pooling:

```ini
# pgbouncer.ini
[databases]
my_api_db = host=localhost port=5432 dbname=my_api_db

[pgbouncer]
listen_port = 6432
listen_addr = *
auth_type = md5
auth_file = /etc/pgbouncer/userlist.txt
pool_mode = transaction
max_client_conn = 100
default_pool_size = 20
```

Update `.env`:
```ini
DATABASE_PORT=6432
```

### 3. Caching Strategy

Implement caching for frequently accessed data:

```php
// src/App/Routes/GetProductsRoute.php
use Framework\Cache\RedisCache;

public function process(Request $request)
{
    $cacheKey = 'products:all';
    $cache = new RedisCache();

    $products = $cache->get($cacheKey);

    if (!$products) {
        $products = FnGetAllProducts::run();
        $cache->set($cacheKey, $products, 300); // Cache for 5 minutes
    }

    return new ApiResponse('ok', 'Products retrieved', $products);
}
```

### 4. Monitoring and Logging

Set up monitoring:

```php
// src/App/Routes/BaseRoute.php
abstract class BaseRoute
{
    protected function logRequest(Request $request): void
    {
        error_log(json_encode([
            'timestamp' => date('c'),
            'method' => $request->method,
            'path' => $request->path,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]), 3, ROOT_PATH . '/logs/requests.log');
    }
}
```

### 5. Performance Benchmarks

Document performance improvements:

```
Before Migration (Laravel + MySQL):
- Average response time: 120ms
- Throughput: 500 req/s
- 95th percentile: 250ms

After Migration (StoneScriptPHP + PostgreSQL):
- Average response time: 45ms
- Throughput: 1200 req/s
- 95th percentile: 95ms
```

## Migration Checklist

Use this checklist to track your migration progress:

### Pre-Migration
- [ ] Project assessment completed
- [ ] Migration strategy selected
- [ ] Timeline created
- [ ] Team trained on StoneScriptPHP
- [ ] Development environment set up

### Database
- [ ] PostgreSQL installed and configured
- [ ] Schema converted to .pssql files
- [ ] Data migrated and validated
- [ ] Indexes created
- [ ] SQL functions created for business logic

### Application
- [ ] All routes generated
- [ ] All route handlers implemented
- [ ] Validation rules added
- [ ] Authentication migrated
- [ ] Middleware implemented
- [ ] CORS configured
- [ ] Error handling implemented

### Testing
- [ ] Unit tests written
- [ ] Integration tests written
- [ ] Performance tests completed
- [ ] Security audit completed
- [ ] User acceptance testing completed

### Deployment
- [ ] Staging environment deployed
- [ ] Production environment prepared
- [ ] Rollback plan created
- [ ] Monitoring set up
- [ ] Logging configured
- [ ] Production deployment completed

### Post-Migration
- [ ] Performance optimized
- [ ] Indexes tuned
- [ ] Caching implemented
- [ ] Documentation updated
- [ ] Team training completed

## Conclusion

Migrating to StoneScriptPHP provides:
- **Performance**: PostgreSQL + SQL functions = fast queries
- **Developer Experience**: Angular-like CLI and structure
- **Maintainability**: Clear separation of concerns
- **Scalability**: Microservices-ready architecture

The migration requires careful planning but delivers significant long-term benefits. Follow this playbook step-by-step, and you'll have a successful migration.

## Additional Resources

- [StoneScriptPHP Documentation](https://stonescriptphp.org/docs)
- [Getting Started Guide](getting-started.md)
- [API Reference](api-reference.md)
- [CLI Usage](../CLI-USAGE.md)
- [Middleware Guide](MIDDLEWARE.md)
- [Validation Guide](validation.md)

## Support

If you encounter issues during migration:
- [GitHub Issues](https://github.com/progalaxyelabs/StoneScriptPHP/issues)
- [Community Forum](https://stonescriptphp.org/community)
- [Documentation](https://stonescriptphp.org/docs)
