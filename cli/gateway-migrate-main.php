<?php
/**
 * StoneScriptPHP CLI - Gateway Migrate Main Database (V2 API)
 *
 * Two-step migration flow for the main (platform) database:
 *   1. Upload main schema archive (from main/postgresql/)
 *   2. Migrate main database using stored schema
 *
 * Usage:
 *   php stone gateway:migrate-main [options]
 *
 * Environment variables (required):
 *   DB_GATEWAY_URL      - Gateway URL (e.g., http://localhost:9000)
 *   PLATFORM_ID         - Platform identifier (e.g., myapp)
 *   MAIN_SCHEMA_NAME    - Main schema name, use a descriptive name like "main" (not a version-tagged name like "main_v1") [preferred]
 *   SCHEMA_NAME         - Schema name fallback (e.g., "main"); avoid version-tagged names like "v1_0"
 *   DATABASE_ID         - Database to migrate (default: main)
 *
 * Options:
 *   --retry=<n>              Number of retry attempts (default: 3)
 *   --delay=<s>              Delay between retries in seconds (default: 5)
 *   --quiet                  Suppress output
 *   --database-id=<id>       Override DATABASE_ID
 *   --main-schema-name=<n>   Override MAIN_SCHEMA_NAME
 *   --schema-name=<n>        Override SCHEMA_NAME (fallback)
 *
 * Granular safety flags (least-privilege — unlock only the operation you intend):
 *   --allow-drop-table            Permit dropping tables
 *   --allow-drop-column           Permit dropping columns
 *   --allow-column-type-change    Permit changing a column's type
 *   --allow-add-not-null-column   Permit adding a NOT NULL column without DEFAULT
 *   --allow-set-not-null          Permit making an existing column NOT NULL
 *   --dangerously-skip-verification  Bypass post-migration schema verification only
 *   --force                       Deprecated allow-all: permits every guarded op AND
 *                                 skips verification (kept for back-compat)
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
    echo "=== Gateway Migrate Main Database (V2) ===\n";
    echo "Platform:    {$env['platform_id']}\n";
    echo "Schema:      {$mainSchemaName}\n";
    echo "Database ID: {$env['database_id']}\n";
    echo "Gateway:     {$env['gateway_url']}\n";
    if ($options['force']) {
        echo "Force:       enabled (bypassing schema validation)\n";
    }
    echo "\n";
}

// Build main schema archive
$archive = buildGatewayArchive('main', $env['platform_id'], 'migrate_main', $options['quiet']);

// Step 1: Upload main schema
if (!$options['quiet']) echo "Step 1/2: ";
stepUploadSchema($env['gateway_url'], $env['platform_id'], $mainSchemaName, $archive['tar_file'], $options['quiet']);

// Step 2: Migrate main database (uuid is null for the main/platform database)
if (!$options['quiet']) echo "Step 2/2: ";
stepMigrateDatabase(
    $env['gateway_url'], $env['platform_id'], $mainSchemaName,
    null, $env['admin_token'], $options['force'],
    $options['retry'], $options['delay'], $options['quiet'],
    $options['allow'], $options['skip_verification'],
    $env['cross_db_link']
);

exit(0);
