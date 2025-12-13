# Testing Multi-Tenancy

This guide explains how to test the multi-tenancy implementation in StoneScriptPHP.

## Quick Tests (No Database Required)

Run the unit tests that don't require a database:

```bash
php test-multi-tenancy.php
```

This tests:
- ✅ Tenant value object creation from JWT and database rows
- ✅ TenantContext global state management
- ✅ Helper functions (tenant(), tenant_id(), etc.)
- ✅ Tenant resolution from JWT claims
- ✅ Tenant serialization (toArray(), toJSON())
- ✅ Multiple tenant switching

Expected output: All tests pass with green checkmarks ✓

## Integration Tests (Requires PostgreSQL)

### Prerequisites

1. **PostgreSQL running** (default: localhost:5432)
2. **Create auth database**:
   ```bash
   psql -U postgres -c 'CREATE DATABASE auth;'
   ```

3. **Set environment variables** (optional):
   ```bash
   export DB_HOST=localhost
   export DB_PORT=5432
   export DB_USER=postgres
   export DB_PASSWORD=your_password
   ```

### Run Integration Tests

```bash
php test-integration.php
```

This tests:
- ✅ Database connection to auth DB
- ✅ Tenants table creation
- ✅ Tenant provisioning with TenantProvisioner
- ✅ Tenant resolution from headers/subdomain
- ✅ Middleware integration
- ✅ Shared database query builder with automatic filtering
- ✅ Connection pooling
- ✅ Listing all tenants

## Manual Testing with CLI

### 1. Create a Test Tenant

```bash
php stone tenant:create "Test Company" test-company --email=admin@test.com
```

Expected output:
```
Creating tenant: Test Company (test-company)
✓ Tenant created successfully!
  ID: 1
  UUID: 550e8400-e29b-41d4-a716-446655440000
  Slug: test-company
  Database: tenant_550e8400e29b41d4a716446655440000
```

### 2. List All Tenants

```bash
php stone tenant:list
```

Expected output:
```
Tenants:
----------------------------------------------------------------------------------------------------
ID    UUID                                  Slug                 Name                      Database
----------------------------------------------------------------------------------------------------
1     550e8400-e29b-41d4-a716-446655440000 test-company         Test Company              tenant_...
----------------------------------------------------------------------------------------------------
Total: 1 tenant(s)
```

### 3. Check Tenant Status

```bash
php stone tenant:status test-company
```

Expected output:
```
Tenant Details:
------------------------------------------------------------
  ID:             1
  UUID:           550e8400-e29b-41d4-a716-446655440000
  Slug:           test-company
  Name:           Test Company
  Database:       tenant_550e8400e29b41d4a716446655440000
  Status:         active
  Email:          admin@test.com
  Created:        2024-01-15 10:30:00
  Updated:        2024-01-15 10:30:00
  DB Created:     2024-01-15 10:30:01
------------------------------------------------------------
```

### 4. Test Tenant Lifecycle

```bash
# Suspend tenant
php stone tenant:suspend test-company

# Reactivate tenant
php stone tenant:activate test-company

# Delete tenant (keeps database)
php stone tenant:delete test-company

# Delete tenant and drop database (DESTRUCTIVE!)
php stone tenant:delete test-company --drop-db
```

## Testing with a Real Application

### Example 1: Per-Tenant Database Strategy

Create a simple test API:

```php
<?php
// public/index.php
require_once __DIR__ . '/../vendor/autoload.php';

use Framework\Routing\Router;
use Framework\Tenancy\TenantResolver;
use Framework\Http\Middleware\TenantMiddleware;

// Setup
$authDb = new PDO('pgsql:host=localhost;dbname=auth', 'postgres', 'password');
$resolver = new TenantResolver($authDb, ['header', 'subdomain']);
$router = new Router();

// Add tenant middleware
$router->use(new TenantMiddleware($resolver, ['/api/health']));

// Health check (no tenant required)
$router->get('/api/health', function() {
    return new Framework\ApiResponse('success', 'OK');
});

// Tenant-aware route
$router->get('/api/products', function($request) {
    $db = tenant_db();

    $stmt = $db->query('SELECT * FROM products ORDER BY created_at DESC LIMIT 10');
    $products = $stmt->fetchAll();

    return new Framework\ApiResponse('success', 'Products retrieved', [
        'tenant' => tenant_slug(),
        'products' => $products,
        'count' => count($products)
    ]);
});

$router->handle();
```

Test with curl:

```bash
# Without tenant header - should return 404
curl http://localhost:8000/api/products

# With tenant header - should work
curl -H "X-Tenant-Slug: test-company" http://localhost:8000/api/products

# Health check works without tenant
curl http://localhost:8000/api/health
```

### Example 2: Shared Database Strategy

```php
<?php
use Framework\Tenancy\TenantQueryBuilder;

$router->get('/api/products', function($request) {
    // Uses shared database with automatic tenant_id filtering
    $sharedDb = new PDO('pgsql:host=localhost;dbname=shared_app', 'user', 'pass');
    $builder = new TenantQueryBuilder($sharedDb, 'products');

    // Automatically adds: WHERE tenant_id = {current_tenant_id}
    $products = $builder->all();

    return new Framework\ApiResponse('success', 'Products', [
        'tenant_id' => tenant_id(),
        'products' => $products
    ]);
});
```

Test with different tenants:

```bash
# Tenant 1
curl -H "X-Tenant-ID: 1" http://localhost:8000/api/products

# Tenant 2 (different data)
curl -H "X-Tenant-ID: 2" http://localhost:8000/api/products
```

## Testing with JWT Tokens

Generate a test JWT with tenant claims:

```php
<?php
use Framework\Auth\RsaJwtHandler;

$jwtHandler = new RsaJwtHandler();

$token = $jwtHandler->generateToken([
    'user_id' => 123,
    'email' => 'admin@acme.com',
    'tenant_id' => 1,
    'tenant_uuid' => '550e8400-e29b-41d4-a716-446655440000',
    'tenant_slug' => 'acme'
]);

echo "Test token: {$token}\n";
```

Use the token:

```bash
curl -H "Authorization: Bearer YOUR_TOKEN_HERE" \
     http://localhost:8000/api/products
```

## Automated Testing with PHPUnit

Create tests in your application:

```php
<?php
// tests/TenancyTest.php
use PHPUnit\Framework\TestCase;
use Framework\Tenancy\Tenant;
use Framework\Tenancy\TenantContext;

class TenancyTest extends TestCase
{
    public function testTenantFromJWT()
    {
        $tenant = Tenant::fromJWT([
            'tenant_id' => 123,
            'tenant_slug' => 'test'
        ]);

        $this->assertEquals(123, $tenant->id);
        $this->assertEquals('test', $tenant->slug);
    }

    public function testTenantContext()
    {
        $tenant = Tenant::fromJWT(['tenant_id' => 456, 'tenant_slug' => 'acme']);
        TenantContext::setTenant($tenant);

        $this->assertTrue(tenant_check());
        $this->assertEquals(456, tenant_id());
        $this->assertEquals('acme', tenant_slug());

        TenantContext::clear();
        $this->assertFalse(tenant_check());
    }
}
```

Run with:

```bash
vendor/bin/phpunit tests/TenancyTest.php
```

## Common Issues & Solutions

### Issue: "Tenant not found"
**Solution**: Make sure tenant exists in database and header/JWT has correct tenant identifier.

```bash
# Check if tenant exists
php stone tenant:list
```

### Issue: "Failed to connect to database"
**Solution**: Verify PostgreSQL is running and credentials are correct.

```bash
# Test connection
psql -U postgres -d auth -c "SELECT 1;"
```

### Issue: "No tenant database context available"
**Solution**: TenantMiddleware must be applied before accessing tenant_db().

```php
// Add middleware BEFORE routes
$router->use(new TenantMiddleware($resolver));

// Then routes can use tenant_db()
$router->get('/api/products', function() {
    $db = tenant_db(); // ✓ Works
});
```

### Issue: Connection pooling not working
**Solution**: Ensure same database name is used consistently.

```php
// Good - uses cached connection
$db1 = TenantConnectionManager::getConnection('tenant_123abc', $config);
$db2 = TenantConnectionManager::getConnection('tenant_123abc', $config); // Cached!

// Check cache
echo TenantConnectionManager::getConnectionCount(); // Should be 1
```

## Performance Testing

Test connection pooling performance:

```php
<?php
// Simulate 100 requests to same tenant
$start = microtime(true);

for ($i = 0; $i < 100; $i++) {
    $db = TenantConnectionManager::getConnection('tenant_test', $config);
    $stmt = $db->query('SELECT 1');
}

$elapsed = microtime(true) - $start;
echo "100 queries in {$elapsed}s\n";
echo "Average: " . ($elapsed / 100 * 1000) . "ms per query\n";
echo "Connections created: " . TenantConnectionManager::getConnectionCount() . "\n";
```

Expected: Only 1 connection created for 100 requests (connection pooling working).

## Next Steps

1. ✅ Run `php test-multi-tenancy.php` - Verify all components work
2. ✅ Run `php test-integration.php` - Test with real database
3. ✅ Create test tenant with CLI
4. ✅ Build a test API endpoint
5. ✅ Test with curl/Postman
6. ✅ Deploy to staging environment
7. ✅ Load test with multiple tenants

## Documentation

For more details, see:
- [examples/README.md](examples/README.md#7-multi-tenancy-example) - Usage examples
- Source code documentation in `src/Tenancy/`
- CLI help: `php stone tenant:help`
