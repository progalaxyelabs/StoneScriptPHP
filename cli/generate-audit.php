<?php

/**
 * Audit Generator
 *
 * Scaffolds an immutable, append-only `_audit_log` table + two capture
 * trigger functions (row-level for INSERT/UPDATE/DELETE, statement-level
 * for TRUNCATE — see functions/_audit_capture_truncate.pgsql.template for
 * why TRUNCATE needs its own trigger) into the CONSUMING platform's OWN
 * database (main or tenant — run it against whichever database you want
 * 100% mutation capture on), plus the migration that attaches both triggers
 * to a configurable list of tables (default: identities, tenants,
 * tenant_memberships — this platform's own identity/tenant model, per the
 * "gateway = pure infra, identities+tenants owned by each platform" split).
 *
 * Same precedent as `php stone generate tenant-governance` /
 * `php stone generate invitations`: copies static template files, plus one
 * small piece of generated text (the per-table trigger-attach blocks) built
 * from a tiny template with a single __TABLE__ placeholder substitution —
 * there is no other templating mechanism in this codebase to reuse, so this
 * is intentionally minimal.
 *
 * Usage:
 *   php stone generate audit
 *   php stone generate audit --tables=identities,tenants,tenant_memberships
 */

if (!defined('ROOT_PATH')) {
    // Identical detection block to cli/generate-tenant-governance.php /
    // cli/generate-invitations.php / cli/generate-auth.php — kept
    // byte-for-byte so all generators agree on where "the project" is
    // regardless of how this package is installed.
    $cliDir = __DIR__;
    $frameworkDir = dirname($cliDir);
    $potentialApiDir = dirname(dirname(dirname($frameworkDir))); // vendor/progalaxyelabs/stonescriptphp -> api

    if (basename($potentialApiDir) === 'api' && file_exists($potentialApiDir . DIRECTORY_SEPARATOR . 'composer.json')) {
        define('ROOT_PATH', $potentialApiDir . DIRECTORY_SEPARATOR);
    } else {
        define('ROOT_PATH', dirname($frameworkDir) . DIRECTORY_SEPARATOR);
    }
}
if (!defined('SRC_PATH')) define('SRC_PATH', ROOT_PATH . 'src' . DIRECTORY_SEPARATOR);

$vendorPath = ROOT_PATH . 'vendor' . DIRECTORY_SEPARATOR . 'progalaxyelabs' . DIRECTORY_SEPARATOR . 'stonescriptphp' . DIRECTORY_SEPARATOR;
if (!is_dir($vendorPath)) {
    // Development mode — framework is a sibling directory.
    $vendorPath = dirname(ROOT_PATH) . DIRECTORY_SEPARATOR . 'StoneScriptPHP' . DIRECTORY_SEPARATOR;
}

$argv = $_SERVER['argv'] ?? $argv;
$argc = $_SERVER['argc'] ?? $argc;

$DEFAULT_AUDITED_TABLES = ['identities', 'tenants', 'tenant_memberships'];

if ($argc >= 2 && in_array($argv[1], ['--help', '-h', 'help'], true)) {
    echo "Audit Generator\n";
    echo "================\n\n";
    echo "Scaffolds an immutable, append-only _audit_log table + row- and\n";
    echo "statement-level capture triggers into THIS database, attached to\n";
    echo "every table you name (default: " . implode(', ', $DEFAULT_AUDITED_TABLES) . ").\n\n";
    echo "Usage:\n";
    echo "  php stone generate audit\n";
    echo "  php stone generate audit --tables=identities,tenants,tenant_memberships\n\n";
    echo "This will create:\n";
    echo "  - migrations/{N}_create_audit_log.pgsql\n";
    echo "  - src/postgresql/{main/postgresql/,}tables/_audit_log.pgsql\n";
    echo "  - src/postgresql/{main/postgresql/,}functions/_audit_capture_row.pgsql\n";
    echo "  - src/postgresql/{main/postgresql/,}functions/_audit_capture_truncate.pgsql\n";
    echo "  - src/postgresql/{main/postgresql/,}audit/protected.json\n\n";
    echo "No model wrapper is generated — both trigger functions are internal,\n";
    echo "never called directly from PHP.\n\n";
    echo "protected.json is the contract for stonescriptdb-gateway's GATED\n";
    echo "audit-owner role-split (TAMPER-PROOF, not just tamper-evident —\n";
    echo "requires gateway operator opt-in via AUDIT_PROVISIONING_DATABASE_URL;\n";
    echo "inert file otherwise). Override the runtime DB role it names with\n";
    echo "--runtime-role=<role> (default: gateway_user).\n\n";
    echo "\"Who\" (actor_id) is captured via set_config('app.actor_id', <id>,\n";
    echo "true) — add that line yourself at the top of any mutating SQL\n";
    echo "function that has an acting identity available. Left unset (cron,\n";
    echo "raw psql, gateway migrate, admin tooling) -> actor_source='system'.\n\n";
    echo "TRUNCATE is captured by a separate AFTER STATEMENT trigger AND\n";
    echo "revoked from the app role on every audited base table — an\n";
    echo "AFTER-ROW trigger alone never fires on TRUNCATE.\n\n";
    echo "If a configured table doesn't exist yet when the migration runs, the\n";
    echo "attach step RAISEs a WARNING (visible in the migration output, not a\n";
    echo "silent skip) rather than failing the whole migration — create the\n";
    echo "table and re-run this generator (or write a follow-on migration with\n";
    echo "another attach block) to close the gap.\n";
    exit(0);
}

// ── Parse --tables / --runtime-role ──────────────────────────────────────
$auditedTables = $DEFAULT_AUDITED_TABLES;
$runtimeRoleOverride = null;
foreach ($argv as $arg) {
    if (is_string($arg) && str_starts_with($arg, '--tables=')) {
        $list = substr($arg, strlen('--tables='));
        $auditedTables = array_values(array_filter(array_map('trim', explode(',', $list))));
        foreach ($auditedTables as $t) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $t)) {
                echo "Error: invalid table name in --tables: '$t' (must be a bare SQL identifier)\n";
                exit(1);
            }
        }
        if (empty($auditedTables)) {
            echo "Error: --tables list is empty\n";
            exit(1);
        }
    }
    if (is_string($arg) && str_starts_with($arg, '--runtime-role=')) {
        $runtimeRoleOverride = trim(substr($arg, strlen('--runtime-role=')));
    }
}

/**
 * Reads the DB connecting role name the stonescriptdb-gateway's regular pool
 * uses for THIS platform's databases — needed only by the (flag-gated, OFF by
 * default) audit-owner role-split manifest (audit/protected.json), so the
 * gateway knows which role to grant DML-only access to. The gateway's regular
 * pool is built from ONE set of DB_HOST/DB_USER/... env vars shared across the
 * whole fleet (see stonescriptdb-gateway's src/config.rs Config::from_env),
 * defaulting to "gateway_user" — that is the correct default here too. Override
 * with --runtime-role= if a given deployment's gateway uses a non-default
 * DB_USER.
 */
function detectRuntimeRole(?string $override): string
{
    if ($override !== null && $override !== '') {
        return $override;
    }
    return 'gateway_user';
}

/**
 * Reads DB_GATEWAY_PLATFORM from this project's .env (same variable
 * `php stone migrate` already prints — see cli/migrate.php). Needed to name
 * this platform's `{platform}_audit_owner` role in the manifest. A plain
 * regex read (not Env::load(), which needs the full framework boot/autoload
 * context this standalone CLI script doesn't have) — same lightweight
 * approach the ROOT_PATH detection block above already uses for filesystem
 * layout.
 */
function detectPlatformCode(string $rootPath): ?string
{
    $envPath = $rootPath . '.env';
    if (!is_file($envPath)) {
        return null;
    }
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (preg_match('/^DB_GATEWAY_PLATFORM\s*=\s*(.+)$/', $line, $m)) {
            return trim($m[1], " \t\n\r\0\x0B\"'");
        }
    }
    return null;
}

echo "Generating immutable audit log (tables: " . implode(', ', $auditedTables) . ")...\n\n";

$templatesPath = $vendorPath . 'src' . DIRECTORY_SEPARATOR . 'Templates' . DIRECTORY_SEPARATOR . 'Audit' . DIRECTORY_SEPARATOR;
if (!is_dir($templatesPath)) {
    echo "Error: Audit templates not found at $templatesPath\n";
    echo "Your progalaxyelabs/stonescriptphp version may be too old — run:\n";
    echo "  composer update progalaxyelabs/stonescriptphp\n";
    exit(1);
}

// ── Layout detection — identical approach to generate-tenant-governance.php.
$nestedMainBase = SRC_PATH . 'postgresql' . DIRECTORY_SEPARATOR . 'main' . DIRECTORY_SEPARATOR . 'postgresql' . DIRECTORY_SEPARATOR;
$useNested = is_dir($nestedMainBase);

if ($useNested) {
    $tablesDir     = $nestedMainBase . 'tables';
    $functionsDir  = $nestedMainBase . 'functions';
    $migrationsDir = $nestedMainBase . 'migrations';
    $auditDir      = $nestedMainBase . 'audit';
    echo "Detected nested main-DB layout: src/postgresql/main/postgresql/\n";
} else {
    $tablesDir     = SRC_PATH . 'postgresql' . DIRECTORY_SEPARATOR . 'tables';
    $functionsDir  = SRC_PATH . 'postgresql' . DIRECTORY_SEPARATOR . 'functions';
    $migrationsDir = ROOT_PATH . 'migrations';
    $auditDir      = SRC_PATH . 'postgresql' . DIRECTORY_SEPARATOR . 'audit';
    echo "Using flat layout: src/postgresql/{tables,functions} + migrations/\n";
}

foreach (['tables' => $tablesDir, 'functions' => $functionsDir, 'migrations' => $migrationsDir, 'audit' => $auditDir] as $name => $dir) {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            echo "Error: Failed to create $name directory: $dir\n";
            exit(1);
        }
        echo "Created directory: $dir\n";
    }
}

/**
 * Mirrors resolveGovernanceMigrationFilename() in
 * cli/generate-tenant-governance.php exactly — continue the target's
 * existing sequential numbering if any; else use the fleet's timestamp
 * convention rather than inventing 001_ with no basis.
 *
 * @return array{0: string, 1: string} [filename, note]
 */
function resolveAuditMigrationFilename(string $migrationsDir): array
{
    if (!is_dir($migrationsDir)) {
        return [
            '001_create_audit_log.pgsql',
            'no existing migrations directory — starting fresh sequential numbering',
        ];
    }

    $numbers = [];
    $widths = [];
    $extCounts = [];

    foreach (scandir($migrationsDir) ?: [] as $entry) {
        if (preg_match('/^(\d+)_.*\.(pgsql|sql)$/i', $entry, $m)) {
            $numbers[] = (int) $m[1];
            $widths[] = strlen($m[1]);
            $ext = strtolower($m[2]);
            $extCounts[$ext] = ($extCounts[$ext] ?? 0) + 1;
        }
    }

    if (!empty($numbers)) {
        $next = max($numbers) + 1;
        $width = max($widths);
        arsort($extCounts);
        $ext = array_key_first($extCounts) ?? 'pgsql';
        $number = str_pad((string) $next, $width, '0', STR_PAD_LEFT);

        return [
            "{$number}_create_audit_log.{$ext}",
            "sequential numbering detected ({$width}-digit) — next number is {$number}",
        ];
    }

    $timestamp = date('Y-m-d_H-i-s');

    return [
        "{$timestamp}_create_audit_log.sql",
        'no sequential-numbered migrations found — used the fleet timestamp convention',
    ];
}

// ── 1. Table (declarative — gateway schema-sync source of truth) ─────────
echo "\n→ Creating declarative table file...\n";
$tableDst = $tablesDir . DIRECTORY_SEPARATOR . '_audit_log.pgsql';
if (file_exists($tableDst)) {
    echo "  Skipped (already exists): " . relativeToRoot(ROOT_PATH, $tableDst) . "\n";
} else {
    copy($templatesPath . 'tables' . DIRECTORY_SEPARATOR . '_audit_log.pgsql.template', $tableDst);
    echo "  ✓ Created: " . relativeToRoot(ROOT_PATH, $tableDst) . "\n";
}

// ── 2. Trigger functions (row-level + TRUNCATE statement-level) ──────────
echo "\n→ Creating SQL functions...\n";
$captureRowDst = $functionsDir . DIRECTORY_SEPARATOR . '_audit_capture_row.pgsql';
if (file_exists($captureRowDst)) {
    echo "  Skipped (already exists): " . relativeToRoot(ROOT_PATH, $captureRowDst) . "\n";
} else {
    copy($templatesPath . 'functions' . DIRECTORY_SEPARATOR . '_audit_capture_row.pgsql.template', $captureRowDst);
    echo "  ✓ Created: " . relativeToRoot(ROOT_PATH, $captureRowDst) . "\n";
}

$captureTruncateDst = $functionsDir . DIRECTORY_SEPARATOR . '_audit_capture_truncate.pgsql';
if (file_exists($captureTruncateDst)) {
    echo "  Skipped (already exists): " . relativeToRoot(ROOT_PATH, $captureTruncateDst) . "\n";
} else {
    copy($templatesPath . 'functions' . DIRECTORY_SEPARATOR . '_audit_capture_truncate.pgsql.template', $captureTruncateDst);
    echo "  ✓ Created: " . relativeToRoot(ROOT_PATH, $captureTruncateDst) . "\n";
}

// ── 3. Migration (table + REVOKE + per-table trigger-attach blocks) ───────
echo "\n→ Creating migration...\n";
$existingMigration = null;
foreach (scandir($migrationsDir) ?: [] as $entry) {
    if (preg_match('/create_audit_log\.(pgsql|sql)$/i', $entry)) {
        $existingMigration = $entry;
        break;
    }
}
if ($existingMigration !== null) {
    echo "  Skipped (already exists): " . relativeToRoot(ROOT_PATH, $migrationsDir . DIRECTORY_SEPARATOR . $existingMigration) . "\n";
    echo "  (--tables is only honored on first generation — edit the existing\n";
    echo "   migration by hand to audit additional tables, or write a follow-on\n";
    echo "   migration with more __TABLE___attach blocks.)\n";
} else {
    $blockTemplate = file_get_contents($templatesPath . 'migrations' . DIRECTORY_SEPARATOR . '_attach_trigger_block.pgsql.template');
    $blocks = [];
    foreach ($auditedTables as $table) {
        $blocks[] = str_replace('__TABLE__', $table, $blockTemplate);
    }

    $migrationSql = str_replace(
        '__AUDITED_TABLE_TRIGGERS__',
        implode("\n", $blocks),
        file_get_contents($templatesPath . 'migrations' . DIRECTORY_SEPARATOR . 'create_audit_log.pgsql.template')
    );

    [$migrationFilename, $migrationNote] = resolveAuditMigrationFilename($migrationsDir);
    $migrationDst = $migrationsDir . DIRECTORY_SEPARATOR . $migrationFilename;
    file_put_contents($migrationDst, $migrationSql);
    echo "  ✓ Created: " . relativeToRoot(ROOT_PATH, $migrationDst) . "\n";
    echo "    ($migrationNote)\n";
}

// ── 4. audit/protected.json manifest (gated audit-owner role-split) ──────
//
// Consumed ONLY by stonescriptdb-gateway's audit_provision module (src/audit_
// provision/mod.rs), and ONLY when the gateway operator has set
// AUDIT_PROVISIONING_DATABASE_URL — absent that flag this file is inert
// (loaded, found "no manifest"-equivalent behaviour never triggers because the
// gateway checks the flag FIRST). Domain-agnostic contract: the gateway learns
// audited table/function names ONLY from this file, never from gateway code.
// `php stone generate soft-delete` appends "purge_expired_deletions" to
// `functions` here (idempotently) if this file already exists when it runs.
echo "\n→ Writing audit/protected.json manifest (gated role-split contract)...\n";
$manifestDst = $auditDir . DIRECTORY_SEPARATOR . 'protected.json';
$runtimeRole = detectRuntimeRole($runtimeRoleOverride);
$platformCode = detectPlatformCode(ROOT_PATH);
if ($platformCode === null) {
    echo "  Warning: could not read DB_GATEWAY_PLATFORM from .env — protected.json\n";
    echo "  omits the platform hint; the gateway derives {platform}_audit_owner from\n";
    echo "  the /v2/migrate request's own 'platform' field regardless, so this is\n";
    echo "  informational only (not required for the manifest to function).\n";
}
if (file_exists($manifestDst)) {
    $existing = json_decode((string) file_get_contents($manifestDst), true) ?: [];
    $existing['audited_tables'] = $auditedTables;
    $existing['audit_log_table'] = $existing['audit_log_table'] ?? '_audit_log';
    $existingFns = is_array($existing['functions'] ?? null) ? $existing['functions'] : [];
    $existing['functions'] = array_values(array_unique(array_merge($existingFns, ['_audit_capture_row', '_audit_capture_truncate'])));
    $existing['runtime_role'] = $runtimeRole;
    file_put_contents($manifestDst, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    echo "  ✓ Updated: " . relativeToRoot(ROOT_PATH, $manifestDst) . "\n";
} else {
    $manifest = [
        'audited_tables'  => $auditedTables,
        'audit_log_table' => '_audit_log',
        'functions'       => ['_audit_capture_row', '_audit_capture_truncate'],
        'runtime_role'    => $runtimeRole,
    ];
    if ($platformCode !== null) {
        $manifest['_platform_hint'] = $platformCode; // informational only — see comment above
    }
    file_put_contents($manifestDst, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    echo "  ✓ Created: " . relativeToRoot(ROOT_PATH, $manifestDst) . "\n";
}

echo "\n✅ Audit scaffolding complete!\n\n";
echo "Next steps:\n";
echo "1. Run migrations: php stone gateway:migrate-main (or php stone migrate up /\n";
echo "   gateway:migrate-tenant, whichever database you generated this against).\n";
echo "   Watch the migration output for 'audit: table \"...\" does not exist'\n";
echo "   WARNINGs — that means a configured table wasn't there yet and its\n";
echo "   capture trigger did NOT attach; create the table and re-run.\n";
echo "2. Any mutating SQL function that has an acting identity available should\n";
echo "   set it at the top of the function body:\n";
echo "     PERFORM set_config('app.actor_id', p_actor_identity_id::text, true);\n";
echo "   Left unset -> actor_source='system' (honest for cron/admin/migrate paths).\n";
echo "3. Re-run this generator with --tables to attach capture to more tables\n";
echo "   later, or hand-edit the migration if the target table didn't exist yet\n";
echo "   on first run (the trigger-attach blocks are self-skipping and idempotent).\n";
echo "4. Query examples:\n";
echo "     SELECT * FROM _audit_log WHERE table_name='identities' AND subject_id=\$1 ORDER BY occurred_at;\n";
echo "     SELECT * FROM _audit_log WHERE operation='DELETE' AND table_name='identities';\n";
echo "     SELECT * FROM _audit_log WHERE operation='TRUNCATE';\n";

/**
 * Render an absolute path relative to ROOT_PATH for tidy output.
 * Named distinctly from generate-tenant-governance.php's relativeTo() —
 * both files may be require()'d in the same PHP process during the test
 * suite's fixture runs (via the dispatcher), and PHP has no per-file
 * function scoping, so a shared name would collide.
 */
function relativeToRoot(string $root, string $path): string
{
    $root = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (str_starts_with($path, $root)) {
        return substr($path, strlen($root));
    }
    return $path;
}
