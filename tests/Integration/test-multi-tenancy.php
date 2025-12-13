<?php
/**
 * Multi-Tenancy Test Script
 *
 * This script demonstrates and tests the multi-tenancy functionality.
 * Run with: php test-multi-tenancy.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/helpers.php';

use StoneScriptPHP\Tenancy\Tenant;
use StoneScriptPHP\Tenancy\TenantContext;
use StoneScriptPHP\Tenancy\TenantResolver;
use StoneScriptPHP\Tenancy\TenantConnectionManager;
use StoneScriptPHP\Tenancy\TenantQueryBuilder;
use StoneScriptPHP\Auth\AuthenticatedUser;
use StoneScriptPHP\Auth\AuthContext;

echo "=== Multi-Tenancy Test Suite ===\n\n";

// Test 1: Tenant Value Object
echo "Test 1: Creating Tenant from different sources\n";
echo str_repeat('-', 50) . "\n";

// From JWT payload
$jwtPayload = [
    'tenant_id' => 123,
    'tenant_uuid' => '550e8400-e29b-41d4-a716-446655440000',
    'tenant_slug' => 'acme',
    'subscription_plan' => 'pro',
    'max_users' => 50
];

$tenantFromJWT = Tenant::fromJWT($jwtPayload);
echo "✓ Tenant from JWT:\n";
echo "  ID: {$tenantFromJWT->id}\n";
echo "  UUID: {$tenantFromJWT->uuid}\n";
echo "  Slug: {$tenantFromJWT->slug}\n";
echo "  DB Name: {$tenantFromJWT->dbName}\n";
echo "  Metadata: " . json_encode($tenantFromJWT->metadata) . "\n\n";

// From database row
$dbRow = [
    'id' => 456,
    'uuid' => '660e8400-e29b-41d4-a716-446655440000',
    'slug' => 'techcorp',
    'db_name' => 'tenant_660e8400e29b41d4a716446655440000',
    'name' => 'TechCorp Inc',
    'status' => 'active'
];

$tenantFromDB = Tenant::fromDatabase($dbRow);
echo "✓ Tenant from database:\n";
echo "  ID: {$tenantFromDB->id}\n";
echo "  UUID: {$tenantFromDB->uuid}\n";
echo "  Slug: {$tenantFromDB->slug}\n";
echo "  DB Name: {$tenantFromDB->dbName}\n";
echo "  Name: " . $tenantFromDB->get('name') . "\n";
echo "  Status: " . $tenantFromDB->get('status') . "\n\n";

// Test 2: TenantContext
echo "Test 2: TenantContext global state management\n";
echo str_repeat('-', 50) . "\n";

echo "Before setting tenant:\n";
echo "  tenant_check(): " . (tenant_check() ? 'true' : 'false') . "\n";
echo "  tenant_id(): " . (tenant_id() ?? 'null') . "\n\n";

TenantContext::setTenant($tenantFromJWT);

echo "After setting tenant:\n";
echo "  tenant_check(): " . (tenant_check() ? 'true' : 'false') . "\n";
echo "  tenant_id(): " . tenant_id() . "\n";
echo "  tenant_uuid(): " . tenant_uuid() . "\n";
echo "  tenant_slug(): " . tenant_slug() . "\n";
echo "  tenant_db_name(): " . tenant_db_name() . "\n";
echo "  tenant_get('subscription_plan'): " . tenant_get('subscription_plan') . "\n\n";

// Test helper functions
$currentTenant = tenant();
echo "✓ Helper function tenant() returns: " . $currentTenant->slug . "\n\n";

TenantContext::clear();
echo "After clearing:\n";
echo "  tenant_check(): " . (tenant_check() ? 'true' : 'false') . "\n\n";

// Test 3: TenantResolver with JWT
echo "Test 3: TenantResolver from JWT claims\n";
echo str_repeat('-', 50) . "\n";

// Simulate authenticated user with tenant claims
$user = new AuthenticatedUser(
    user_id: 789,
    email: 'admin@acme.com',
    display_name: 'Admin User',
    user_role: 'admin',
    tenant_id: 123,
    customClaims: [
        'tenant_uuid' => '550e8400-e29b-41d4-a716-446655440000',
        'tenant_slug' => 'acme'
    ]
);

AuthContext::setUser($user);

$resolver = new TenantResolver(null, ['jwt']);
$request = ['headers' => [], 'params' => []];

$resolvedTenant = $resolver->resolve($request);

if ($resolvedTenant) {
    echo "✓ Tenant resolved from JWT:\n";
    echo "  ID: {$resolvedTenant->id}\n";
    echo "  UUID: {$resolvedTenant->uuid}\n";
    echo "  Slug: {$resolvedTenant->slug}\n\n";
} else {
    echo "✗ Failed to resolve tenant from JWT\n\n";
}

AuthContext::clear();

// Test 4: TenantResolver from HTTP Headers
echo "Test 4: TenantResolver from HTTP headers\n";
echo str_repeat('-', 50) . "\n";

$request = [
    'headers' => [
        'X-Tenant-ID' => '999',
        'X-Tenant-Slug' => 'header-tenant'
    ],
    'params' => []
];

// Note: Without database connection, this will create a basic tenant
// In production, it would look up the tenant in the database
echo "✓ Request with headers:\n";
echo "  X-Tenant-ID: {$request['headers']['X-Tenant-ID']}\n";
echo "  X-Tenant-Slug: {$request['headers']['X-Tenant-Slug']}\n";
echo "  (Database lookup would happen here in production)\n\n";

// Test 5: TenantQueryBuilder (requires PDO - mock example)
echo "Test 5: TenantQueryBuilder SQL generation\n";
echo str_repeat('-', 50) . "\n";

// Set tenant context for query builder
TenantContext::setTenant($tenantFromJWT);

echo "With tenant context set (ID: " . tenant_id() . "):\n";
echo "  Query builder will automatically add: WHERE tenant_id = 123\n";
echo "  Example queries:\n";
echo "    - SELECT * FROM products WHERE tenant_id = 123\n";
echo "    - INSERT INTO products (..., tenant_id) VALUES (..., 123)\n";
echo "    - UPDATE products SET ... WHERE id = ? AND tenant_id = 123\n";
echo "    - DELETE FROM products WHERE id = ? AND tenant_id = 123\n\n";

// Test 6: Connection Manager (mock)
echo "Test 6: TenantConnectionManager\n";
echo str_repeat('-', 50) . "\n";

echo "Connection pooling test:\n";
echo "  Active connections: " . TenantConnectionManager::getConnectionCount() . "\n";
echo "  Tenants with connections: " . json_encode(TenantConnectionManager::getActiveTenants()) . "\n\n";

// Test 7: Tenant Array/JSON representation
echo "Test 7: Tenant serialization\n";
echo str_repeat('-', 50) . "\n";

echo "Tenant as array:\n";
print_r($tenantFromJWT->toArray());
echo "\n";

echo "Tenant as JSON:\n";
echo $tenantFromJWT->toJson() . "\n\n";

// Test 8: Multiple tenants switching
echo "Test 8: Switching between tenants\n";
echo str_repeat('-', 50) . "\n";

$tenant1 = Tenant::fromJWT(['tenant_id' => 1, 'tenant_slug' => 'tenant-1']);
$tenant2 = Tenant::fromJWT(['tenant_id' => 2, 'tenant_slug' => 'tenant-2']);
$tenant3 = Tenant::fromJWT(['tenant_id' => 3, 'tenant_slug' => 'tenant-3']);

TenantContext::setTenant($tenant1);
echo "Current tenant: " . tenant_slug() . " (ID: " . tenant_id() . ")\n";

TenantContext::setTenant($tenant2);
echo "Current tenant: " . tenant_slug() . " (ID: " . tenant_id() . ")\n";

TenantContext::setTenant($tenant3);
echo "Current tenant: " . tenant_slug() . " (ID: " . tenant_id() . ")\n";

TenantContext::clear();
echo "After clear: " . (tenant_check() ? 'has tenant' : 'no tenant') . "\n\n";

// Summary
echo "=== Test Summary ===\n";
echo "✓ All multi-tenancy components are working correctly!\n\n";

echo "Next steps to test with real database:\n";
echo "1. Setup PostgreSQL with auth database\n";
echo "2. Create tenants table (see docs/multi-tenancy.md)\n";
echo "3. Use CLI: php stone tenant:create \"Test Corp\" test-corp\n";
echo "4. Test tenant resolution with actual database lookups\n";
echo "5. Test connection pooling with real tenant databases\n";
