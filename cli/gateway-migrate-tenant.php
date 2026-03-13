<?php
/**
 * StoneScriptPHP CLI - Gateway Migrate Single Tenant Database (V2 API)
 *
 * Migrates ONE specific tenant database. --database-id is REQUIRED.
 * For migrating all tenant databases, use gateway:migrate-all-tenants.
 *
 * Usage:
 *   php stone gateway:migrate-tenant --database-id=<uuid>
 *
 * Environment variables (required):
 *   DB_GATEWAY_URL        - Gateway URL (e.g., http://localhost:9000)
 *   PLATFORM_ID           - Platform identifier (e.g., myapp)
 *   TENANT_SCHEMA_NAME    - Tenant schema version name (e.g., v1_0) [preferred]
 *   SCHEMA_NAME           - Schema version name (fallback if TENANT_SCHEMA_NAME not set)
 *
 * Options:
 *   --database-id=<id>         REQUIRED. Tenant database ID (UUID)
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

// --database-id is REQUIRED for migrate-tenant
$databaseId = $options['database_id'] ?: null;

if (!$databaseId) {
    fwrite(STDERR, "ERROR: --database-id=<uuid> is required for gateway:migrate-tenant.\n");
    fwrite(STDERR, "  To migrate a single tenant:       php stone gateway:migrate-tenant --database-id=<uuid>\n");
    fwrite(STDERR, "  To migrate ALL tenant databases:   php stone gateway:migrate-all-tenants\n");
    exit(1);
}

if ($databaseId === 'main') {
    fwrite(STDERR, "ERROR: Cannot use 'main' as database-id with gateway:migrate-tenant.\n");
    fwrite(STDERR, "  Use gateway:migrate-main for the main database.\n");
    exit(1);
}

if (str_contains($databaseId, '-')) {
    $suggested = str_replace('-', '_', $databaseId);
    fwrite(STDERR, "ERROR: Database IDs use underscores, not hyphens.\n");
    fwrite(STDERR, "  Did you mean: php stone gateway:migrate-tenant --database-id={$suggested}\n");
    exit(1);
}

if (!$options['quiet']) {
    echo "=== Gateway Migrate Tenant Database (V2) ===\n";
    echo "Platform:    {$env['platform_id']}\n";
    echo "Schema:      {$tenantSchemaName}\n";
    echo "Database ID: {$databaseId}\n";
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

// Step 2: Migrate specific tenant database
if (!$options['quiet']) echo "Step 2/2: ";
stepMigrateDatabase(
    $env['gateway_url'], $env['platform_id'], $tenantSchemaName,
    $databaseId, $options['force'],
    $options['retry'], $options['delay'], $options['quiet']
);

exit(0);
