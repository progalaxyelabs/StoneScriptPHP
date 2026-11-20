<?php

/**
 * Tenancy v2 Generator
 *
 * Scaffolds the three-layer tenancy model into the CONSUMING platform's own repo:
 *
 *   main database    platform (one row), tenants core columns, tenant_memberships (no role column),
 *                    the tenancy_* / platform_* SQL functions and one forward-only migration
 *   tenant database  users, user_roles, user_role_privileges and their functions
 *   --builtin        (standalone platforms) also the local `identities` table and the idempotency
 *                    ledger the builtin MembershipAuthority needs
 *
 * Files that already exist are never overwritten, and a table is never defined twice: the target
 * directory is parsed for CREATE TABLE statements (file names mean nothing). A table the project already
 * defines (`tenants`, `tenant_memberships`, the tenant `users`, ...) is REFUSED with guidance; the generated
 * migrations expand the live tables to the core columns (the tenant `users` gets its own expand migration)
 * and the command prints exactly what to mirror in your declarations.
 *
 * Usage:
 *   php stone generate tenancy [--builtin]
 */

if (!defined('ROOT_PATH')) {
    $cliDir = __DIR__;
    $frameworkDir = dirname($cliDir);
    $potentialApiDir = dirname(dirname(dirname($frameworkDir)));

    if (basename($potentialApiDir) === 'api' && file_exists($potentialApiDir . DIRECTORY_SEPARATOR . 'composer.json')) {
        define('ROOT_PATH', $potentialApiDir . DIRECTORY_SEPARATOR);
    } else {
        define('ROOT_PATH', dirname($frameworkDir) . DIRECTORY_SEPARATOR);
    }
}
if (!defined('SRC_PATH')) define('SRC_PATH', ROOT_PATH . 'src' . DIRECTORY_SEPARATOR);

$vendorPath = ROOT_PATH . 'vendor' . DIRECTORY_SEPARATOR . 'progalaxyelabs' . DIRECTORY_SEPARATOR . 'stonescriptphp' . DIRECTORY_SEPARATOR;
if (!is_dir($vendorPath)) {
    $vendorPath = dirname(ROOT_PATH) . DIRECTORY_SEPARATOR . 'StoneScriptPHP' . DIRECTORY_SEPARATOR;
}

$argv = $_SERVER['argv'] ?? $argv;
$argc = $_SERVER['argc'] ?? $argc;
$args = array_slice($argv, 1);

if (in_array('--help', $args, true) || in_array('-h', $args, true) || in_array('help', $args, true)) {
    echo "Tenancy v2 Generator\n";
    echo "====================\n\n";
    echo "Scaffolds the platform / tenants / tenant_memberships main-database layer and the\n";
    echo "tenant-database users / user_roles / user_role_privileges layer into THIS project.\n\n";
    echo "Usage: php stone generate tenancy [--builtin]\n\n";
    echo "  --builtin   standalone platform with local identities: also create identities and the\n";
    echo "              idempotency ledger used by the builtin MembershipAuthority\n\n";
    echo "Never overwrites an existing file. Existing tenants / tenant_memberships declarations are kept.\n";
    exit(0);
}

$unknown = array_values(array_filter($args, static fn (string $a): bool => $a !== '--builtin'));
if ($unknown !== []) {
    fwrite(STDERR, "Error: unknown argument '" . $unknown[0] . "'. Run 'php stone generate tenancy --help'.\n");
    exit(1);
}
$builtin = in_array('--builtin', $args, true);

$templatesPath = $vendorPath . 'src' . DIRECTORY_SEPARATOR . 'Templates' . DIRECTORY_SEPARATOR . 'Tenancy' . DIRECTORY_SEPARATOR;
if (!is_dir($templatesPath)) {
    fwrite(STDERR, "Error: tenancy templates not found at $templatesPath\n");
    fwrite(STDERR, "Your progalaxyelabs/stonescriptphp version may be too old. Run: composer update progalaxyelabs/stonescriptphp\n");
    exit(1);
}

// The platform slug (DB_GATEWAY_PLATFORM) must be at most 19 characters so {slug}_tenant_{uuid} fits 63 bytes.
$envFile = ROOT_PATH . '.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $envLine) {
        if (preg_match('/^\s*DB_GATEWAY_PLATFORM\s*=\s*(.+)$/', $envLine, $m) === 1) {
            $configuredSlug = trim($m[1], " \t\"'");
            if (strlen($configuredSlug) > 19) {
                fwrite(STDERR, "Error: DB_GATEWAY_PLATFORM '$configuredSlug' is " . strlen($configuredSlug) . " characters; the platform slug may be at most 19 so that {slug}_tenant_{uuid} fits a 63-byte database name. Nothing was generated.\n");
                exit(1);
            }
        }
    }
}

require_once __DIR__ . '/helpers/schema-layout.php';
$nestedMain = SRC_PATH . 'postgresql' . DIRECTORY_SEPARATOR . 'main' . DIRECTORY_SEPARATOR . 'postgresql' . DIRECTORY_SEPARATOR;
if (sspUseNestedMainLayout(SRC_PATH)) {
    $mainTables = $nestedMain . 'tables';
    $mainFunctions = $nestedMain . 'functions';
    $mainMigrations = $nestedMain . 'migrations';
} else {
    $mainTables = SRC_PATH . 'postgresql' . DIRECTORY_SEPARATOR . 'tables';
    $mainFunctions = SRC_PATH . 'postgresql' . DIRECTORY_SEPARATOR . 'functions';
    $mainMigrations = ROOT_PATH . 'migrations';
}
$tenantBase = SRC_PATH . 'postgresql' . DIRECTORY_SEPARATOR . 'tenant' . DIRECTORY_SEPARATOR . 'postgresql' . DIRECTORY_SEPARATOR;
$tenantTables = $tenantBase . 'tables';
$tenantFunctions = $tenantBase . 'functions';

foreach ([$mainTables, $mainFunctions, $mainMigrations, $tenantTables, $tenantFunctions] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        fwrite(STDERR, "Error: failed to create directory $dir\n");
        exit(1);
    }
}

function tenancyRel(string $path): string
{
    $root = rtrim(ROOT_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
}

/**
 * The tables a directory of declarative schema files defines, found by PARSING `CREATE TABLE` statements (comments
 * removed; schema prefix and quotes ignored), not by file name.
 *
 * @return array<string,string> lower-case table name => the file that defines it
 */
function tenancyDefinedTables(string $dir): array
{
    $tables = [];
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*.{pgsql,sql,pssql}', GLOB_BRACE) ?: [] as $file) {
        $sql = (string) file_get_contents($file);
        $sql = preg_replace(['~/\*.*?\*/~s', '~--[^\n]*~'], '', $sql) ?? $sql;
        if (preg_match_all('/\bCREATE\s+(?:UNLOGGED\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:"?\w+"?\s*\.\s*)?"?(\w+)"?/i', $sql, $m) > 0) {
            foreach ($m[1] as $name) {
                $tables[strtolower($name)] ??= $file;
            }
        }
    }
    return $tables;
}

function tenancyCopy(string $src, string $dst): void
{
    if (file_exists($dst)) {
        echo '  Skipped (already exists): ' . tenancyRel($dst) . "\n";
        return;
    }
    if (!copy($src, $dst)) {
        fwrite(STDERR, 'Error: could not write ' . tenancyRel($dst) . "\n");
        exit(1);
    }
    echo '  Created: ' . tenancyRel($dst) . "\n";
}

/** Next migration filename in the target's own numbering convention (same rules as the other generators). */
function tenancyMigrationName(string $dir, string $slug): string
{
    foreach (scandir($dir) ?: [] as $entry) {
        if (preg_match('/' . preg_quote($slug, '/') . '\.(pgsql|sql)$/i', $entry)) {
            return $entry; // already generated: reuse so the run stays idempotent
        }
    }
    $numbers = [];
    $widths = [];
    $extCounts = [];
    foreach (scandir($dir) ?: [] as $entry) {
        if (preg_match('/^(\d+)_.*\.(pgsql|sql)$/i', $entry, $m)) {
            $numbers[] = (int) $m[1];
            $widths[] = strlen($m[1]);
            $ext = strtolower($m[2]);
            $extCounts[$ext] = ($extCounts[$ext] ?? 0) + 1;
        }
    }
    if ($numbers !== []) {
        arsort($extCounts);
        $ext = array_key_first($extCounts) ?? 'pgsql';
        return str_pad((string) (max($numbers) + 1), max($widths), '0', STR_PAD_LEFT) . "_{$slug}.{$ext}";
    }
    return date('Y-m-d_H-i-s') . "_{$slug}.sql";
}

// 1. Main-database tables
echo "Generating tenancy v2 (" . ($builtin ? 'standalone/builtin' : 'external auth') . ")...\n\n";
echo "Main database tables\n";
$guidance = [];
$tenancyRefused = 0;

/**
 * Place a table definition, never a duplicate. The target directory is parsed for CREATE TABLE statements (the
 * file name means nothing): a table that is already defined by one of OUR earlier runs is left alone; a table the
 * project defines itself, in any file, is REFUSED (nothing is written) and the caller gets true so it can add
 * guidance.
 */
function tenancyPlaceTable(string $dir, string $table, string $template): bool
{
    global $tenancyRefused;
    $defined = tenancyDefinedTables($dir);
    $dst = $dir . DIRECTORY_SEPARATOR . $table . '.pgsql';
    $file = $defined[$table] ?? null;
    if ($file === null) {
        tenancyCopy($template, $dst);
        return false;
    }
    if (str_contains((string) file_get_contents($file), 'Generated by `php stone generate tenancy`')) {
        echo '  Skipped (already exists): ' . tenancyRel($file) . "\n";
        return false;
    }
    echo "  Refused: table '$table' is already defined in " . tenancyRel($file) . "; not writing a second definition\n";
    $tenancyRefused++;
    return true;
}

tenancyPlaceTable($mainTables, 'platform', $templatesPath . 'main/tables/platform.pgsql.template')
    && $guidance[] = 'Your own `platform` table was kept. It needs: singleton (PK, CHECK), platform_id UUID, platform_name, platform_slug (at most 19 characters), auth_mode, registered_at (see the generated template).';

foreach (['tenants', 'tenant_memberships'] as $table) {
    if (!tenancyPlaceTable($mainTables, $table, $templatesPath . "main/tables/{$table}.pgsql.template")) {
        continue;
    }
    $defining = tenancyDefinedTables($mainTables)[$table];
    $guidance[] = $table === 'tenants'
        ? 'Add the core columns to your own tenants declaration: uuid, name, slug, tenant_db_name (UNIQUE), tenant_provisioning_status, created_by_identity_id, created_at, updated_at, provisioned_at (see the generated template for the exact definitions). Platform business columns must be nullable or defaulted. The generated migration expands the live table.'
        : 'Reshape your tenant_memberships declaration to: id, identity_id, tenant_id (FK tenants(uuid) ON DELETE NO ACTION), is_tenant_owner, status, invited_by_identity_id, created_at, updated_at, UNIQUE (identity_id, tenant_id). The role column moves to the tenant database; leave it in place until the drop-column wrapper retires it.';
    if ($table === 'tenant_memberships' && str_contains((string) file_get_contents($defining), 'is_tenant_admin')) {
        $guidance[] = 'Your tenant_memberships is the tenant-governance ledger (creator/owner/admin flags). Tenancy v2 moves governance tiers to tenant-database roles; migrate them to user_roles before retiring the old columns.';
    }
}

tenancyPlaceTable($mainTables, 'tenancy_mirror_drift', $templatesPath . 'main/tables/tenancy_mirror_drift.pgsql.template');

if ($builtin) {
    foreach (['identities', 'tenant_provision_requests'] as $table) {
        tenancyPlaceTable($mainTables, $table, $templatesPath . "main/tables/{$table}.pgsql.template")
            && $guidance[] = "Your own `$table` table was kept; check it against the generated template (the builtin authority needs those columns).";
    }
}

// 2. Main-database functions
echo "\nMain database functions\n";
$mainFns = ['platform_get', 'platform_upsert', 'tenancy_get_tenant_status', 'tenancy_upsert_tenant', 'tenancy_set_tenant_status', 'tenancy_create_membership', 'tenancy_get_identity_memberships', 'tenancy_find_membership', 'tenancy_set_membership_status', 'tenancy_list_tenants', 'tenancy_mirror_membership_status', 'tenancy_mirror_drift', 'tenancy_list_active_member_identities'];
if ($builtin) {
    $mainFns = array_merge($mainFns, ['tenancy_find_request', 'tenancy_save_request']);
}
foreach ($mainFns as $fn) {
    tenancyCopy($templatesPath . "main/functions/{$fn}.pgsql.template", $mainFunctions . DIRECTORY_SEPARATOR . $fn . '.pgsql');
}

// 3. Main-database migration (forward-only, idempotent)
echo "\nMain database migration\n";
$migrationName = tenancyMigrationName($mainMigrations, 'tenancy_v2_core');
tenancyCopy($templatesPath . 'main/migrations/tenancy_v2_core.pgsql.template', $mainMigrations . DIRECTORY_SEPARATOR . $migrationName);

// 4. Tenant database
echo "\nTenant database tables and functions\n";
foreach (['users', 'user_roles', 'user_role_privileges'] as $table) {
    if (!tenancyPlaceTable($tenantTables, $table, $templatesPath . "tenant/tables/{$table}.pgsql.template")) {
        continue;
    }
    if ($table === 'users') {
        // The project already has a users table: ship a forward-only expand migration instead of a second definition.
        $tenantMigrations = $tenantBase . 'migrations';
        if (!is_dir($tenantMigrations) && !mkdir($tenantMigrations, 0755, true)) {
            fwrite(STDERR, "Error: failed to create directory $tenantMigrations\n");
            exit(1);
        }
        $name = tenancyMigrationName($tenantMigrations, 'users_expand_identity');
        tenancyCopy($templatesPath . 'tenant/migrations/users_expand_identity.pgsql.template', $tenantMigrations . DIRECTORY_SEPARATOR . $name);
        $guidance[] = 'Your tenant users table was kept. Review ' . tenancyRel($tenantMigrations . DIRECTORY_SEPARATOR . $name) . ' (it backfills identity_id from id: change that if users.id is not the auth identity) and add identity_id, display_name, status, created_at, updated_at and UNIQUE (identity_id) to your own users declaration.';
    } else {
        $guidance[] = "Your own tenant `$table` table was kept; check it against the generated template (the tenant functions need those columns).";
    }
}
foreach (['tenant_user_upsert', 'tenant_user_assign_role', 'tenant_get_identity_role_privileges', 'tenant_seed_privileges', 'tenant_missing_privileges', 'tenant_role_privilege_counts', 'tenant_identities_with_access'] as $fn) {
    tenancyCopy($templatesPath . "tenant/functions/{$fn}.pgsql.template", $tenantFunctions . DIRECTORY_SEPARATOR . $fn . '.pgsql');
}

// 5. Platform-wide privilege seed (config), written into every tenant database at provisioning
echo "\nPrivilege seed\n";
$configDir = ROOT_PATH . 'config';
if (!is_dir($configDir) && !mkdir($configDir, 0755, true)) {
    fwrite(STDERR, "Error: failed to create directory $configDir\n");
    exit(1);
}
tenancyCopy($templatesPath . 'config/tenant-privileges.php.template', $configDir . DIRECTORY_SEPARATOR . 'tenant-privileges.php');

echo "\nTenancy v2 scaffolding complete.\n\n";
if ($guidance !== []) {
    echo "Do these by hand:\n";
    foreach ($guidance as $i => $line) {
        echo '  ' . ($i + 1) . '. ' . $line . "\n";
    }
    echo "\n";
}
echo "Next steps:\n";
echo "  1. php stone gateway:migrate-main                      (main database)\n";
echo "  2. php stone gateway:register-tenant, then migrate each tenant database\n";
echo "  3. Fill in config/tenant-privileges.php (the roles your routes check), then apply it to the tenants that already\n";
echo "     exist: php stone tenancy:seed-privileges --dry-run, then php stone tenancy:seed-privileges.\n";
if ($builtin) {
    echo "  4. Standalone: call platform_upsert(<new uuid>, <name>, <slug>, 'builtin') once and set AUTH_MODE=builtin.\n";
} else {
    echo "  4. php stone platform:register --name \"<name>\" --slug <slug>   (reads the operator token from ~/.stone/operator.env)\n";
}
echo "  5. MANDATORY before the flip: php stone tenancy:preflight must pass (no role with users and zero privileges).\n";
echo "  6. Set TENANCY_V2=true when this platform switches to the v2 provisioning sequence.\n";

// A table the project already defines was refused: tell scripts and CI (exit 2) after all the guidance above.
if ($tenancyRefused > 0) {
    fwrite(STDERR, "\nRefused $tenancyRefused existing table definition(s) (see 'Refused' above and the guidance): exit status 2.\n");
    exit(2);
}
