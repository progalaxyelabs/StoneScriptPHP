<?php
/**
 * StoneScriptPHP CLI - Gateway Migrate Tenant Databases (V2 API)
 *
 * Two-step migration flow for tenant databases:
 *   1. Upload tenant schema archive (from tenant/postgresql/)
 *   2. Migrate tenant databases using stored schema
 *
 * When called without --database-id, calls POST /v2/migrate-all which migrates
 * ALL existing tenant databases sequentially, skipping if none exist.
 * Use --database-id to migrate a single specific tenant.
 *
 * Usage:
 *   php stone gateway:migrate-tenant [options]
 *
 * Environment variables (required):
 *   DB_GATEWAY_URL        - Gateway URL (e.g., http://localhost:9000)
 *   PLATFORM_ID           - Platform identifier (e.g., myapp)
 *   TENANT_SCHEMA_NAME    - Tenant schema version name (e.g., v1_0) [preferred]
 *   SCHEMA_NAME           - Schema version name (fallback if TENANT_SCHEMA_NAME not set)
 *
 * Environment variables (optional):
 *   DATABASE_ID           - Specific tenant database to migrate (omit to migrate all)
 *
 * Options:
 *   --retry=<n>                Number of retry attempts (default: 3)
 *   --delay=<s>                Delay between retries in seconds (default: 5)
 *   --quiet                    Suppress output
 *   --force                    Bypass schema validation (use with caution)
 *   --database-id=<id>         Override DATABASE_ID (specific tenant)
 *   --tenant-schema-name=<n>   Override TENANT_SCHEMA_NAME
 *   --schema-name=<n>          Override SCHEMA_NAME (fallback)
 */

require_once __DIR__ . '/helpers/gateway-common.php';

$options = parseGatewayOptions($argv);
$env = loadGatewayEnv($options, false);

// Use TENANT_SCHEMA_NAME for tenant databases; falls back to SCHEMA_NAME for backward compat
$tenantSchemaName = $env['tenant_schema_name'];

if (!$tenantSchemaName) {
    fwrite(STDERR, "ERROR: TENANT_SCHEMA_NAME (or SCHEMA_NAME) environment variable is required (or use --tenant-schema-name=...)\n");
    exit(1);
}

// For tenant migration, database_id is optional (migrate all if not specified)
$databaseId = $options['database_id'] ?: (getenv('DATABASE_ID') ?: null);

if (!$options['quiet']) {
    echo "=== Gateway Migrate Tenant Databases (V2) ===\n";
    echo "Platform:    {$env['platform_id']}\n";
    echo "Schema:      {$tenantSchemaName}\n";
    if ($databaseId) {
        echo "Database ID: {$databaseId} (single tenant)\n";
    } else {
        echo "Database ID: all tenants (POST /v2/migrate-all)\n";
    }
    echo "Gateway:     {$env['gateway_url']}\n";
    if ($options['force']) {
        echo "Force:       enabled (bypassing schema validation)\n";
    }
    echo "\n";
}

// Build tenant schema archive
$archive = buildGatewayArchive('tenant', $env['platform_id'], 'migrate_tenant', $options['quiet']);

// Step 1: Upload tenant schema
if (!$options['quiet']) echo "Step 1/2: ";
stepUploadSchema($env['gateway_url'], $env['platform_id'], $tenantSchemaName, $archive['tar_file'], $options['quiet']);

// Step 2: Migrate tenant database(s)
if (!$options['quiet']) echo "Step 2/2: ";

if ($databaseId) {
    // Migrate specific tenant via POST /v2/migrate with database_id
    stepMigrateDatabase(
        $env['gateway_url'], $env['platform_id'], $tenantSchemaName,
        $databaseId, $options['force'],
        $options['retry'], $options['delay'], $options['quiet']
    );
} else {
    // Migrate ALL tenants via POST /v2/migrate-all (no database_id)
    // Skips gracefully if no tenant databases exist yet
    stepMigrateAllDatabases(
        $env['gateway_url'], $env['platform_id'], $tenantSchemaName,
        $options['force'],
        $options['retry'], $options['delay'], $options['quiet']
    );
}

exit(0);
