<?php
/**
 * StoneScriptPHP CLI - Gateway Migrate Tenant Databases (V2 API)
 *
 * Two-step migration flow for tenant databases:
 *   1. Upload tenant schema archive (from tenant/postgresql/)
 *   2. Migrate tenant databases using stored schema
 *
 * When called without --database-id, migrates ALL tenant databases
 * for the platform. Use --database-id to migrate a specific tenant.
 *
 * Usage:
 *   php stone gateway:migrate-tenant [options]
 *
 * Environment variables (required):
 *   DB_GATEWAY_URL    - Gateway URL (e.g., http://localhost:9000)
 *   PLATFORM_ID       - Platform identifier (e.g., myapp)
 *   SCHEMA_NAME       - Schema version name (e.g., v1_0)
 *
 * Environment variables (optional):
 *   DATABASE_ID       - Specific tenant database to migrate (omit to migrate all)
 *
 * Options:
 *   --retry=<n>        Number of retry attempts (default: 3)
 *   --delay=<s>        Delay between retries in seconds (default: 5)
 *   --quiet            Suppress output
 *   --force            Bypass schema validation (use with caution)
 *   --database-id=<id> Override DATABASE_ID (specific tenant)
 *   --schema-name=<n>  Override SCHEMA_NAME
 */

require_once __DIR__ . '/helpers/gateway-common.php';

$options = parseGatewayOptions($argv);
$env = loadGatewayEnv($options, false);

// For tenant migration, database_id is optional (migrate all if not specified)
$databaseId = $options['database_id'] ?: (getenv('DATABASE_ID') ?: null);

if (!$options['quiet']) {
    echo "=== Gateway Migrate Tenant Databases (V2) ===\n";
    echo "Platform:    {$env['platform_id']}\n";
    echo "Schema:      {$env['schema_name']}\n";
    if ($databaseId) {
        echo "Database ID: {$databaseId} (single tenant)\n";
    } else {
        echo "Database ID: all tenants\n";
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
stepUploadSchema($env['gateway_url'], $env['platform_id'], $env['schema_name'], $archive['tar_file'], $options['quiet']);

// Step 2: Migrate tenant database(s)
if (!$options['quiet']) echo "Step 2/2: ";

if ($databaseId) {
    // Migrate specific tenant
    stepMigrateDatabase(
        $env['gateway_url'], $env['platform_id'], $env['schema_name'],
        $databaseId, $options['force'],
        $options['retry'], $options['delay'], $options['quiet']
    );
} else {
    // Migrate all tenants — the gateway handles this when database_id is omitted
    // For now, we pass 'all' which the gateway interprets as "migrate all tenant databases"
    stepMigrateDatabase(
        $env['gateway_url'], $env['platform_id'], $env['schema_name'],
        'all', $options['force'],
        $options['retry'], $options['delay'], $options['quiet']
    );
}

exit(0);
