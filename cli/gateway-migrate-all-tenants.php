<?php
/**
 * StoneScriptPHP CLI - Gateway Migrate ALL Tenant Databases (V2 API)
 *
 * Migrates ALL existing tenant databases for the platform sequentially.
 * This is a bulk operation — use with care.
 *
 * For migrating a single tenant, use gateway:migrate-tenant --database-id=<uuid>.
 *
 * Usage:
 *   php stone gateway:migrate-all-tenants
 *
 * Environment variables (required):
 *   DB_GATEWAY_URL        - Gateway URL (e.g., http://localhost:9000)
 *   PLATFORM_ID           - Platform identifier (e.g., myapp)
 *   TENANT_SCHEMA_NAME    - Tenant schema version name (e.g., v1_0) [preferred]
 *   SCHEMA_NAME           - Schema version name (fallback if TENANT_SCHEMA_NAME not set)
 *
 * Options:
 *   --retry=<n>                Number of retry attempts (default: 3)
 *   --delay=<s>                Delay between retries in seconds (default: 5)
 *   --quiet                    Suppress output
 *   --force                    Bypass schema validation (use with caution)
 *   --tenant-schema-name=<n>   Override TENANT_SCHEMA_NAME
 *   --schema-name=<n>          Override SCHEMA_NAME (fallback)
 */

require_once __DIR__ . '/helpers/gateway-common.php';

$options = parseGatewayOptions($argv);
$env = loadGatewayEnv($options, false);

$tenantSchemaName = $env['tenant_schema_name'];

if (!$tenantSchemaName) {
    fwrite(STDERR, "ERROR: TENANT_SCHEMA_NAME (or SCHEMA_NAME) environment variable is required (or use --tenant-schema-name=...)\n");
    exit(1);
}

if (!$options['quiet']) {
    echo "=== Gateway Migrate ALL Tenant Databases (V2) ===\n";
    echo "Platform:    {$env['platform_id']}\n";
    echo "Schema:      {$tenantSchemaName}\n";
    echo "Target:      ALL tenant databases\n";
    echo "Gateway:     {$env['gateway_url']}\n";
    if ($options['force']) {
        echo "Force:       enabled (bypassing schema validation)\n";
    }
    echo "\n";
}

// Build tenant schema archive
$archive = buildGatewayArchive('tenant', $env['platform_id'], 'migrate_all_tenants', $options['quiet']);

// Step 1: Upload tenant schema
if (!$options['quiet']) echo "Step 1/2: ";
stepUploadSchema($env['gateway_url'], $env['platform_id'], $tenantSchemaName, $archive['tar_file'], $options['quiet']);

// Step 2: Migrate ALL tenant databases
if (!$options['quiet']) echo "Step 2/2: ";
stepMigrateAllDatabases(
    $env['gateway_url'], $env['platform_id'], $tenantSchemaName,
    $options['force'],
    $options['retry'], $options['delay'], $options['quiet']
);

exit(0);
