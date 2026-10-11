<?php
/**
 * StoneScriptPHP CLI - Gateway Register Tenant Schema (V2 API)
 *
 * Two-step registration flow for the tenant schema:
 *   1. Register platform (idempotent)
 *   2. Upload tenant schema archive (from tenant/postgresql/)
 *
 * This uploads the tenant schema so the gateway knows how to create
 * tenant databases. Actual tenant databases are created at runtime
 * when tenants sign up (via the gateway API or tenant:create CLI).
 *
 * Usage:
 *   php stone gateway:register-tenant [options]
 *
 * Environment variables (required):
 *   DB_GATEWAY_URL    - Gateway URL (e.g., http://localhost:9000)
 *   PLATFORM_ID       - Platform identifier (e.g., myapp)
 *   SCHEMA_NAME       - Schema name, use a descriptive name like "tenant" (not a version-tagged name like "v1_0")
 *
 * Options:
 *   --quiet            Suppress output
 *   --schema-name=<n>  Override the tenant schema name
 *   --tenant-schema-name=<n>  Same, by its other name
 *
 * The tenant schema name resolves as: --schema-name, then the tenant schema (TENANT_SCHEMA_NAME /
 * DB_GATEWAY_TENANT_SCHEMA_NAME), then the generic SCHEMA_NAME. It is NEVER the main schema's name.
 */

require_once __DIR__ . '/helpers/gateway-common.php';

$options = parseGatewayOptions($argv, ['schema-name', 'tenant-schema-name']);
$env = loadGatewayEnv($options, false);

$tenantSchema = $options['schema_name'] ?: ($env['tenant_schema_name'] ?: $env['schema_name']);
$mainName = $env['main_schema_name'];
if (!$tenantSchema) {
    fwrite(STDERR, "ERROR: no tenant schema name: set DB_GATEWAY_TENANT_SCHEMA_NAME (or TENANT_SCHEMA_NAME / SCHEMA_NAME) "
        . "or pass --schema-name=...\nThe main schema name is never used for the tenant schema.\n");
    exit(1);
}
if ($mainName && $tenantSchema === $mainName) {
    fwrite(STDERR, "ERROR: the tenant schema name resolves to the same value as the main schema name "
        . "(MAIN_SCHEMA_NAME / DB_GATEWAY_SCHEMA_NAME). Set a distinct DB_GATEWAY_TENANT_SCHEMA_NAME (or TENANT_SCHEMA_NAME, "
        . "or pass --schema-name=...): uploading the tenant archive under the main name would overwrite the main schema.\n");
    exit(1);
}

if (!$options['quiet']) {
    echo "=== Gateway Register Tenant Schema (V2) ===\n";
    echo "Platform:    {$env['platform_id']}\n";
    echo "Schema:      {$tenantSchema}\n";
    echo "Gateway:     {$env['gateway_url']}\n\n";
}

// Build tenant schema archive
$archive = buildGatewayArchive('tenant', $env['platform_id'], 'register_tenant', $options['quiet']);

// Step 1: Register platform
if (!$options['quiet']) echo "Step 1/2: ";
stepRegisterPlatform($env['gateway_url'], $env['platform_id'], $options['quiet']);

// Step 2: Upload tenant schema
if (!$options['quiet']) echo "Step 2/2: ";
stepUploadSchema($env['gateway_url'], $env['platform_id'], $tenantSchema, $archive['tar_file'], $options['quiet']);

if (!$options['quiet']) {
    echo "Tenant schema registered. Tenant databases will be created on demand.\n";
}

exit(0);
