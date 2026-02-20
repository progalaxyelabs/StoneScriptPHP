<?php
/**
 * StoneScriptPHP CLI - Gateway Register Main Database (V2 API)
 *
 * Three-step registration flow for the main (platform) database:
 *   1. Register platform (idempotent)
 *   2. Upload main schema archive (from main/postgresql/)
 *   3. Create main database from stored schema
 *
 * Usage:
 *   php stone gateway:register-main [options]
 *
 * Environment variables (required):
 *   DB_GATEWAY_URL    - Gateway URL (e.g., http://localhost:9000)
 *   PLATFORM_ID       - Platform identifier (e.g., myapp)
 *   SCHEMA_NAME       - Schema version name (e.g., v1_0)
 *   ADMIN_TOKEN       - Admin token for /admin/* endpoints
 *
 * Environment variables (optional):
 *   DATABASE_ID       - Database identifier (default: main)
 *
 * Options:
 *   --retry=<n>        Number of retry attempts (default: 3)
 *   --delay=<s>        Delay between retries in seconds (default: 5)
 *   --quiet            Suppress output
 *   --database-id=<id> Override DATABASE_ID
 *   --schema-name=<n>  Override SCHEMA_NAME
 */

require_once __DIR__ . '/helpers/gateway-common.php';

$options = parseGatewayOptions($argv);
$env = loadGatewayEnv($options);

if (!$options['quiet']) {
    echo "=== Gateway Register Main Database (V2) ===\n";
    echo "Platform:    {$env['platform_id']}\n";
    echo "Schema:      {$env['schema_name']}\n";
    echo "Database ID: {$env['database_id']}\n";
    echo "Gateway:     {$env['gateway_url']}\n\n";
}

// Build main schema archive
$archive = buildGatewayArchive('main', $env['platform_id'], 'register_main', $options['quiet']);

// Step 1: Register platform
if (!$options['quiet']) echo "Step 1/3: ";
stepRegisterPlatform($env['gateway_url'], $env['platform_id'], $options['quiet']);

// Step 2: Upload main schema
if (!$options['quiet']) echo "Step 2/3: ";
stepUploadSchema($env['gateway_url'], $env['platform_id'], $env['schema_name'], $archive['tar_file'], $options['quiet']);

// Step 3: Create main database
if (!$options['quiet']) echo "Step 3/3: ";
stepCreateDatabase(
    $env['gateway_url'], $env['platform_id'], $env['schema_name'],
    $env['database_id'], $env['admin_token'],
    $options['retry'], $options['delay'], $options['quiet']
);

exit(0);
