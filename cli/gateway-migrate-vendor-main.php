<?php
/**
 * StoneScriptPHP CLI - Gateway Migrate Vendor-Provided Main Schema (V2 API)
 *
 * Activates opt-in framework schema (e.g. RequestLogging) that `composer
 * install`/`composer update` staged into src/postgresql/vendor/postgresql/
 * (see cli/sync-vendor-schema.php) but never applied.
 *
 * This is a DELIBERATELY SEPARATE command from `gateway:migrate-main` and is
 * NEVER invoked by it, by `gateway:register-main`, or by any deploy-manager
 * post-deploy hook. StoneScriptPHP is opensource — auto-executing vendor-authored
 * SQL as a side effect of the routine, unreviewed main-database migration path
 * would let unreviewed contributor schema run against a real database. Running
 * THIS command is the deliberate, reviewable act that activates it — read what's
 * in src/postgresql/vendor/ (a diffable, regenerated-on-install build artifact,
 * never hand-edited) before running this.
 *
 * IMPORTANT — why this merges into the SAME 'main' schema/archive rather than
 * uploading vendor files under their own schema name: the gateway resolves the
 * target DATABASE as `{platform}_{schema_name}` when no tenant uuid is given
 * (stonescriptdb-gateway src/pool/router.rs::database_name()) — schema_name is
 * not a label layered on top of one shared database, it's part of the database
 * name itself. A distinct schema_name like "main_vendor" would target a wholly
 * different, nonexistent database ("{platform}_main_vendor"), NOT the same
 * "{platform}_main" database that RequestLogging's own code actually queries.
 * So this command builds ONE combined archive — the platform's own main/postgresql/
 * PLUS src/postgresql/vendor/postgresql/ merged on top (buildSchemaArchive's
 * $mergeTargets) — and migrates it under the SAME MAIN_SCHEMA_NAME as
 * `gateway:migrate-main` uses. This is a full, consistent superset archive (not
 * a partial one), so it's safe even if the gateway's migrate treats "missing
 * from this archive" as "no longer part of the schema" — nothing the platform
 * already owns is ever absent from this upload.
 *
 * Usage:
 *   php stone gateway:migrate-vendor-main [options]
 *
 * Environment variables: same as gateway:migrate-main (DB_GATEWAY_URL,
 * PLATFORM_ID, MAIN_SCHEMA_NAME/SCHEMA_NAME, DATABASE_ID, DB_GATEWAY_PLATFORM_TOKEN).
 *
 * Options: same as gateway:migrate-main (--retry, --delay, --quiet,
 * --database-id, --main-schema-name, --schema-name, --allow-*, --force,
 * --dangerously-skip-verification).
 */

require_once __DIR__ . '/helpers/gateway-common.php';

$options = parseGatewayOptions($argv);
$env = loadGatewayEnv($options);

$mainSchemaName = $env['main_schema_name'];

if (!$mainSchemaName) {
    fwrite(STDERR, "ERROR: MAIN_SCHEMA_NAME (or SCHEMA_NAME) environment variable is required (or use --main-schema-name=...)\n");
    exit(1);
}

$vendorPostgresqlDir = ROOT_PATH . 'src/postgresql/vendor/postgresql';

if (!is_dir($vendorPostgresqlDir) || !glob($vendorPostgresqlDir . '/*/*.{sql,pgsql,pssql}', GLOB_BRACE)) {
    if (!$options['quiet']) {
        echo "No vendor-provided schema staged (src/postgresql/vendor/ is empty or absent) — nothing to migrate.\n";
        echo "This is expected if `composer install` hasn't run yet, or the current framework\n";
        echo "version offers no opt-in schema features. Nothing was changed.\n";
    }
    exit(0);
}

if (!$options['quiet']) {
    echo "=== Gateway Migrate Vendor-Provided Schema (V2) ===\n";
    echo "Platform:    {$env['platform_id']}\n";
    echo "Schema:      {$mainSchemaName} (main + staged vendor schema, merged into one archive)\n";
    echo "Database ID: {$env['database_id']}\n";
    echo "Gateway:     {$env['gateway_url']}\n";
    if ($options['force']) {
        echo "Force:       enabled (bypassing schema validation)\n";
    }
    echo "\n";
}

// Build the SAME 'main' archive gateway:migrate-main would build, PLUS the staged
// vendor schema merged on top — one full, consistent superset, landing in the
// SAME database (never a partial/vendor-only archive — see file header).
$archive = buildGatewayArchive('main', $env['platform_id'], 'migrate_vendor_main', $options['quiet'], ['vendor']);

// Step 1: Upload the merged schema under the SAME schema name as migrate-main.
if (!$options['quiet']) echo "Step 1/2: ";
stepUploadSchema($env['gateway_url'], $env['platform_id'], $mainSchemaName, $archive['tar_file'], $options['quiet']);

// Step 2: Migrate the main database (uuid is null — same target as migrate-main).
if (!$options['quiet']) echo "Step 2/2: ";
stepMigrateDatabase(
    $env['gateway_url'], $env['platform_id'], $mainSchemaName,
    null, resolveGatewayPlatformToken($env, $options['quiet']), $options['force'],
    $options['retry'], $options['delay'], $options['quiet'],
    $options['allow'], $options['skip_verification'],
    $env['cross_db_link']
);

exit(0);
