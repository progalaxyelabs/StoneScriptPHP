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
 *   SCHEMA_NAME       - Schema version name (e.g., v1_0)
 *
 * Options:
 *   --quiet            Suppress output
 *   --schema-name=<n>  Override SCHEMA_NAME
 */

require_once __DIR__ . '/helpers/gateway-common.php';

$options = parseGatewayOptions($argv);
$env = loadGatewayEnv($options, false);

if (!$options['quiet']) {
    echo "=== Gateway Register Tenant Schema (V2) ===\n";
    echo "Platform:    {$env['platform_id']}\n";
    echo "Schema:      {$env['schema_name']}\n";
    echo "Gateway:     {$env['gateway_url']}\n\n";
}

// Build tenant schema archive
$archive = buildGatewayArchive('tenant', $env['platform_id'], 'register_tenant', $options['quiet']);

// Step 1: Register platform
if (!$options['quiet']) echo "Step 1/2: ";
stepRegisterPlatform($env['gateway_url'], $env['platform_id'], $options['quiet']);

// Step 2: Upload tenant schema
if (!$options['quiet']) echo "Step 2/2: ";
stepUploadSchema($env['gateway_url'], $env['platform_id'], $env['schema_name'], $archive['tar_file'], $options['quiet']);

if (!$options['quiet']) {
    echo "Tenant schema registered. Tenant databases will be created on demand.\n";
}

exit(0);
