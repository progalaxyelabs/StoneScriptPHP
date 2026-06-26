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
 *   DB_GATEWAY_URL         - Gateway URL (e.g., http://localhost:9000)
 *   PLATFORM_ID            - Platform identifier (e.g., myapp)
 *   MAIN_SCHEMA_NAME       - Main schema name, use a descriptive name like "main" (not a version-tagged name like "main_v1") [preferred]
 *   SCHEMA_NAME            - Schema name fallback (e.g., "main"); avoid version-tagged names like "v1_0"
 *   DB_GATEWAY_ADMIN_TOKEN - Admin token for /admin/* endpoints (legacy: ADMIN_TOKEN)
 *
 * Environment variables (optional):
 *   DATABASE_ID            - Database identifier (default: main)
 *
 * Options:
 *   --retry=<n>              Number of retry attempts (default: 3)
 *   --delay=<s>              Delay between retries in seconds (default: 5)
 *   --quiet                  Suppress output
 *   --force                  Skip checksum check and always run all steps
 *   --database-id=<id>       Override DATABASE_ID
 *   --main-schema-name=<n>   Override MAIN_SCHEMA_NAME
 *   --schema-name=<n>        Override SCHEMA_NAME (fallback)
 *
 * Idempotency:
 *   A SHA-256 hash of src/postgresql/ is saved to .cache/.gateway-schema-hash-main
 *   after each successful run. Subsequent runs with an unchanged schema exit early.
 *   Use --force to bypass this check.
 */

require_once __DIR__ . '/helpers/gateway-common.php';

$options = parseGatewayOptions($argv);
$env = loadGatewayEnv($options);

// Use MAIN_SCHEMA_NAME for the main database; falls back to SCHEMA_NAME for backward compat
$mainSchemaName = $env['main_schema_name'];

if (!$mainSchemaName) {
    fwrite(STDERR, "ERROR: MAIN_SCHEMA_NAME (or SCHEMA_NAME) environment variable is required (or use --main-schema-name=...)\n");
    exit(1);
}

if (!$options['quiet']) {
    echo "=== Gateway Register Main Database (V2) ===\n";
    echo "Platform:    {$env['platform_id']}\n";
    echo "Schema:      {$mainSchemaName}\n";
    echo "Database ID: {$env['database_id']}\n";
    echo "Gateway:     {$env['gateway_url']}\n\n";
}

// Skip if schema unchanged (checksum-based idempotency guard)
$postgresqlPath = ROOT_PATH . 'src/postgresql';
$hashFile = ROOT_PATH . '.cache/.gateway-schema-hash-main';
$currentHash = is_dir($postgresqlPath) ? computeSchemaHash($postgresqlPath) : '';

if (!$options['force'] && $currentHash !== '') {
    if (file_exists($hashFile) && file_get_contents($hashFile) === $currentHash) {
        if (!$options['quiet']) {
            echo "Schema unchanged — main database already registered, skipping\n";
            echo "Tip: use --force to run anyway\n";
        }
        exit(0);
    }
}

// Build main schema archive
$archive = buildGatewayArchive('main', $env['platform_id'], 'register_main', $options['quiet']);

// Step 1: Register platform
if (!$options['quiet']) echo "Step 1/3: ";
stepRegisterPlatform($env['gateway_url'], $env['platform_id'], $options['quiet']);

// Step 2: Upload main schema
if (!$options['quiet']) echo "Step 2/3: ";
stepUploadSchema($env['gateway_url'], $env['platform_id'], $mainSchemaName, $archive['tar_file'], $options['quiet']);

// Step 3: Create main database (uuid is null for the main/platform database)
if (!$options['quiet']) echo "Step 3/3: ";
stepCreateDatabase(
    $env['gateway_url'], $env['platform_id'], $mainSchemaName,
    null, $env['admin_token'],
    $options['retry'], $options['delay'], $options['quiet']
);

// Persist schema hash so subsequent runs can skip if nothing changed
if ($currentHash !== '') {
    $cacheDir = ROOT_PATH . '.cache';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }
    file_put_contents($hashFile, $currentHash);
}

exit(0);
