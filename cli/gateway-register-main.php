<?php
/**
 * StoneScriptPHP CLI - Gateway Register Main Database (V2 API)
 *
 * Four-step registration flow for the main (platform) database:
 *   1. Register platform (idempotent; admin token)
 *   2. Platform token: reuse DB_GATEWAY_PLATFORM_TOKEN, or issue one with the admin token
 *   3. Upload main schema archive (from main/postgresql/; platform token)
 *   4. Create main database from stored schema (platform token)
 *
 * Usage:
 *   php stone gateway:register-main [options]
 *
 * Configuration is read through the framework's Env (the real environment, `.env`, `<NAME>_FILE`
 * and /run/secrets), exactly as the application reads it.
 *
 * Required:
 *   DB_GATEWAY_URL           - Gateway URL (e.g., http://localhost:9000)
 *   DB_GATEWAY_PLATFORM      - Platform identifier (legacy name PLATFORM_ID also accepted)
 *   DB_GATEWAY_SCHEMA_NAME   - Main schema name, a descriptive name like "main" (not version-tagged);
 *                              legacy name MAIN_SCHEMA_NAME also accepted
 *   If a legacy name and its DB_GATEWAY_* counterpart are both set to different values the command
 *   stops (exit 1) naming both keys.
 *   DB_GATEWAY_ADMIN_TOKEN   - Admin token for platform registration and token issuing (needed for a new platform)
 *
 * Environment variables (optional):
 *   DATABASE_ID              - Database identifier (default: main)
 *   DB_GATEWAY_PLATFORM_TOKEN - Existing platform token (skips issuing; required once the platform already has one)
 *
 * Options:
 *   --retry=<n>              Number of retry attempts (default: 3)
 *   --delay=<s>              Delay between retries in seconds (default: 5)
 *   --quiet                  Suppress output
 *   --force                  Skip checksum check and always run all steps
 *   --database-id=<id>       Override DATABASE_ID
 *   --main-schema-name=<n>   Override the main schema name
 *
 * Idempotency:
 *   A SHA-256 hash of src/postgresql/ is saved to .cache/.gateway-schema-hash-main
 *   after each successful run. Subsequent runs with an unchanged schema exit early.
 *   Use --force to bypass this check.
 */

require_once __DIR__ . '/helpers/gateway-common.php';

$options = parseGatewayOptions($argv, ['main-schema-name']);
$env = loadGatewayEnv($options);

// Main database schema: MAIN_SCHEMA_NAME, else DB_GATEWAY_SCHEMA_NAME
$mainSchemaName = $env['main_schema_name'];

if (!$mainSchemaName) {
    fwrite(STDERR, "ERROR: DB_GATEWAY_SCHEMA_NAME (or MAIN_SCHEMA_NAME) is required (or use --main-schema-name=...)\n");
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

// Step 1: Register platform (admin token: a new platform has no platform token yet)
if (!$options['quiet']) echo "Step 1/4: ";
stepRegisterPlatform($env['gateway_url'], $env['platform_id'], $options['quiet'], $env['admin_token'], $env['platform_token']);

// Step 2: Platform token. DB_GATEWAY_PLATFORM_TOKEN is reused when set; otherwise one is
// issued with the admin token (shown once). Later steps authenticate with it.
if (!$options['quiet']) echo "Step 2/4: ";
$platformToken = !empty($env['platform_token'])
    ? $env['platform_token']
    : resolveGatewayPlatformToken($env, $options['quiet']);
if (!$options['quiet'] && !empty($env['platform_token'])) {
    echo "Using DB_GATEWAY_PLATFORM_TOKEN\n\n";
}

// Step 3: Upload main schema (platform token)
if (!$options['quiet']) echo "Step 3/4: ";
stepUploadSchema($env['gateway_url'], $env['platform_id'], $mainSchemaName, $archive['tar_file'], $options['quiet'], $platformToken);

// Step 4: Create main database (uuid is null for the main/platform database)
if (!$options['quiet']) echo "Step 4/4: ";
stepCreateDatabase(
    $env['gateway_url'], $env['platform_id'], $mainSchemaName,
    null, $platformToken,
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
