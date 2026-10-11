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
 *   DB_GATEWAY_URL             - Gateway URL (e.g., http://localhost:9000)
 *   PLATFORM_ID                - Platform identifier (e.g., myapp)
 *   DB_GATEWAY_TENANT_SCHEMA_NAME - Tenant schema name, a descriptive name like "tenant" (legacy name TENANT_SCHEMA_NAME also accepted)
 *   DB_GATEWAY_PLATFORM_TOKEN  - Platform token (required by gateway v4.1.0+; auto-provisioned if absent and DB_GATEWAY_ADMIN_TOKEN is set)
 *
 * Options:
 *   --retry=<n>                Number of retry attempts (default: 3)
 *   --delay=<s>                Delay between retries in seconds (default: 5)
 *   --quiet                    Suppress output
 *   --force                    Bypass schema validation (use with caution)
 *   --tenant-schema-name=<n>   Override the tenant schema name
 */

require_once __DIR__ . '/helpers/gateway-common.php';

$options = parseGatewayOptions($argv, ['tenant-schema-name']);
$env = loadGatewayEnv($options, false);

$tenantSchemaName = $env['tenant_schema_name'];

if (!$tenantSchemaName) {
    fwrite(STDERR, "ERROR: DB_GATEWAY_TENANT_SCHEMA_NAME (or TENANT_SCHEMA_NAME) is required (or use --tenant-schema-name=...)\n");
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

// Step 2: Migrate ALL tenant databases.
// Gateway v4.1.0+ requires a platform token (not admin token) for POST /v2/migrate-all.
if (!$options['quiet']) echo "Step 2/2: ";
stepMigrateAllDatabases(
    $env['gateway_url'], $env['platform_id'], $tenantSchemaName,
    resolveGatewayPlatformToken($env, $options['quiet']), $options['force'],
    $options['retry'], $options['delay'], $options['quiet'],
    $options['allow'], $options['skip_verification'],
    $env['cross_db_link']
);

exit(0);
