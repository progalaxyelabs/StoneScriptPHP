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
 *   DB_GATEWAY_URL           - Gateway URL (e.g., http://localhost:9000)
 *   DB_GATEWAY_PLATFORM      - Platform identifier (legacy name PLATFORM_ID also accepted)
 *   DB_GATEWAY_SCHEMA_NAME   - Main schema name, a descriptive name like "main" (legacy name MAIN_SCHEMA_NAME also accepted)
 *   DATABASE_ID              - Database to migrate (default: main)
 *   DB_GATEWAY_PLATFORM_TOKEN - Platform token (required by gateway v4.1.0+; auto-provisioned if absent and DB_GATEWAY_ADMIN_TOKEN is set)
 *
 * Options:
 *   --retry=<n>              Number of retry attempts (default: 3)
 *   --delay=<s>              Delay between retries in seconds (default: 5)
 *   --quiet                  Suppress output
 *   --database-id=<id>       Override DATABASE_ID
 *   --main-schema-name=<n>   Override the main schema name
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

$options = parseGatewayOptions($argv, ['main-schema-name']);
$env = loadGatewayEnv($options);

// Main database schema: MAIN_SCHEMA_NAME, else DB_GATEWAY_SCHEMA_NAME
$mainSchemaName = $env['main_schema_name'];

if (!$mainSchemaName) {
    fwrite(STDERR, "ERROR: DB_GATEWAY_SCHEMA_NAME (or MAIN_SCHEMA_NAME) is required (or use --main-schema-name=...)\n");
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

// Step 2: Migrate main database (uuid is null for the main/platform database).
// Gateway v4.1.0+ requires a platform token (not admin token) for POST /v2/migrate.
if (!$options['quiet']) echo "Step 2/2: ";
stepMigrateDatabase(
    $env['gateway_url'], $env['platform_id'], $mainSchemaName,
    null, resolveGatewayPlatformToken($env, $options['quiet']), $options['force'],
    $options['retry'], $options['delay'], $options['quiet'],
    $options['allow'], $options['skip_verification'],
    $env['cross_db_link']
);

exit(0);
