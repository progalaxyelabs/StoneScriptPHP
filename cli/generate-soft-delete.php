<?php

/**
 * Soft-Delete Generator
 *
 * Scaffolds 7-day soft-delete + purge into the CONSUMING platform's OWN
 * database: deleted_at/purge_after/delete_requested_by columns on each
 * configured table, a request_<table>_deletion() function per table (the
 * identities table — if 'tenants' is also configured, which is the default
 * — gets the cascade variant that also soft-deletes tenants it created, via
 * `php stone generate tenant-governance`'s tenant_memberships table), a
 * shared purge_expired_deletions() that physically deletes past-due rows
 * (feeding `php stone generate audit`'s trigger, if installed, with the
 * immutable proof), a support-only restore function per table (deliberately
 * NOT self-service — see the design note in the generated functions), and
 * is_email_blocked() for signin/signup enforcement.
 *
 * Same precedent as `php stone generate audit` / `tenant-governance` /
 * `invitations`: copies static template files, plus generated text built
 * from small per-table snippet templates with placeholder substitution.
 *
 * Usage:
 *   php stone generate soft-delete
 *   php stone generate soft-delete --tables=identities:id,tenants:uuid
 *   php stone generate soft-delete --tables=identities:id,tenants:uuid --pk-type=UUID
 */

if (!defined('ROOT_PATH')) {
    // Identical detection block to every other generator in this framework.
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

/** table => pk column */
$DEFAULT_TABLES = ['identities' => 'id', 'tenants' => 'uuid'];

if ($argc >= 2 && in_array($argv[1], ['--help', '-h', 'help'], true)) {
    echo "Soft-Delete Generator\n";
    echo "======================\n\n";
    echo "Scaffolds 7-day soft-delete + purge into THIS database.\n\n";
    echo "Usage:\n";
    echo "  php stone generate soft-delete\n";
    echo "  php stone generate soft-delete --tables=identities:id,tenants:uuid\n";
    echo "  php stone generate soft-delete --pk-type=UUID\n";
    echo "  php stone generate soft-delete --email-table=identities --email-column=email\n\n";
    echo "--tables entries are table[:pk_column] (pk_column defaults to 'id').\n";
    echo "Default: identities:id,tenants:uuid (matches `tenant-governance`'s tenants(uuid)).\n\n";
    echo "This will create:\n";
    echo "  - migrations/{N}_create_soft_delete.pgsql\n";
    echo "  - src/postgresql/{main/postgresql/,}tables/_deletion_archive.pgsql\n";
    echo "  - src/postgresql/{main/postgresql/,}functions/request_<table>_deletion.pgsql (one per table)\n";
    echo "  - src/postgresql/{main/postgresql/,}functions/support_restore_<table>_deletion.pgsql (one per table)\n";
    echo "  - src/postgresql/{main/postgresql/,}functions/purge_expired_deletions.pgsql\n";
    echo "  - src/postgresql/{main/postgresql/,}functions/is_email_blocked.pgsql (if an email table is configured)\n";
    echo "  - src/App/Database/Functions/Fn*.php (via php stone generate model, for the request/restore/is_email_blocked fns)\n\n";
    echo "Also PATCHES each configured table's own declarative file (tables/<table>.pgsql)\n";
    echo "with the same 3 columns, when it can find one — keeps the gateway's\n";
    echo "verify_schema step (compares live columns to the declarative source,\n";
    echo "read-only, runs LAST) from permanently flagging drift.\n\n";
    echo "Restore is SUPPORT-ONLY by design — no self-service cancel-deletion\n";
    echo "route is generated anywhere. purge_expired_deletions() is not wired to\n";
    echo "any scheduler — cron/wire it into this platform's own daily job yourself.\n";
    echo "It returns (table, purged_count, error) per table — ANY failure on one\n";
    echo "table (FK violation, or a custom trigger exception) is reported there\n";
    echo "and does NOT block any other table's purge in the same run.\n\n";
    echo "If `php stone generate tenant-governance` is also installed, purging a\n";
    echo "tenant now correctly cascades through its founder membership row too\n";
    echo "(a narrow, named, request-scoped bypass of that trigger's creator-\n";
    echo "protection — see purge_expired_deletions()'s own comment and\n";
    echo "_tenant_memberships_protect_creator.pgsql's header for the mechanism\n";
    echo "and its honestly-documented current spoofing caveat). A normal\n";
    echo "user/admin attempt to remove a tenant creator still refuses exactly\n";
    echo "as before — only this system purge path is exempted.\n";
    exit(0);
}

// ── Parse args ────────────────────────────────────────────────────────
$tables = $DEFAULT_TABLES;
$pkType = 'UUID';
$emailTable = null; // resolved below once $tables is final
$emailColumn = 'email';
$emailTableExplicit = false;

foreach ($argv as $arg) {
    if (!is_string($arg)) {
        continue;
    }
    if (str_starts_with($arg, '--tables=')) {
        $list = substr($arg, strlen('--tables='));
        $tables = [];
        foreach (array_filter(array_map('trim', explode(',', $list))) as $entry) {
            $parts = explode(':', $entry, 2);
            $table = $parts[0];
            $pk = $parts[1] ?? 'id';
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $pk)) {
                echo "Error: invalid table/column name in --tables: '$entry' (must be bare SQL identifiers)\n";
                exit(1);
            }
            $tables[$table] = $pk;
        }
        if (empty($tables)) {
            echo "Error: --tables list is empty\n";
            exit(1);
        }
    } elseif (str_starts_with($arg, '--pk-type=')) {
        $pkType = substr($arg, strlen('--pk-type='));
    } elseif (str_starts_with($arg, '--email-table=')) {
        $emailTable = substr($arg, strlen('--email-table='));
        $emailTableExplicit = true;
    } elseif (str_starts_with($arg, '--email-column=')) {
        $emailColumn = substr($arg, strlen('--email-column='));
    }
}

if (!$emailTableExplicit) {
    $emailTable = array_key_exists('identities', $tables) ? 'identities' : null;
}
if ($emailTable !== null && !array_key_exists($emailTable, $tables)) {
    echo "Error: --email-table='$emailTable' is not in --tables\n";
    exit(1);
}

$useCascade = array_key_exists('identities', $tables) && array_key_exists('tenants', $tables);

echo "Generating 7-day soft-delete + purge (tables: " . implode(', ', array_keys($tables)) . ")...\n\n";
if ($useCascade) {
    echo "identities + tenants both configured -> identities gets the tenant-governance\n";
    echo "cascade variant (soft-deletes tenants the identity created).\n\n";
}

$templatesPath = $vendorPath . 'src' . DIRECTORY_SEPARATOR . 'Templates' . DIRECTORY_SEPARATOR . 'SoftDelete' . DIRECTORY_SEPARATOR;
if (!is_dir($templatesPath)) {
    echo "Error: Soft-delete templates not found at $templatesPath\n";
    echo "Your progalaxyelabs/stonescriptphp version may be too old — run:\n";
    echo "  composer update progalaxyelabs/stonescriptphp\n";
    exit(1);
}

// ── Layout detection — identical approach to every other generator. ──────
$nestedMainBase = SRC_PATH . 'postgresql' . DIRECTORY_SEPARATOR . 'main' . DIRECTORY_SEPARATOR . 'postgresql' . DIRECTORY_SEPARATOR;
$useNested = is_dir($nestedMainBase);

if ($useNested) {
    $tablesDir     = $nestedMainBase . 'tables';
    $functionsDir  = $nestedMainBase . 'functions';
    $migrationsDir = $nestedMainBase . 'migrations';
    echo "Detected nested main-DB layout: src/postgresql/main/postgresql/\n";
} else {
    $tablesDir     = SRC_PATH . 'postgresql' . DIRECTORY_SEPARATOR . 'tables';
    $functionsDir  = SRC_PATH . 'postgresql' . DIRECTORY_SEPARATOR . 'functions';
    $migrationsDir = ROOT_PATH . 'migrations';
    echo "Using flat layout: src/postgresql/{tables,functions} + migrations/\n";
}

foreach (['tables' => $tablesDir, 'functions' => $functionsDir, 'migrations' => $migrationsDir] as $name => $dir) {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            echo "Error: Failed to create $name directory: $dir\n";
            exit(1);
        }
        echo "Created directory: $dir\n";
    }
}

/** Mirrors resolveAuditMigrationFilename() / resolveGovernanceMigrationFilename(). */
function resolveSoftDeleteMigrationFilename(string $migrationsDir): array
{
    if (!is_dir($migrationsDir)) {
        return [
            '001_create_soft_delete.pgsql',
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
            "{$number}_create_soft_delete.{$ext}",
            "sequential numbering detected ({$width}-digit) — next number is {$number}",
        ];
    }

    $timestamp = date('Y-m-d_H-i-s');

    return [
        "{$timestamp}_create_soft_delete.sql",
        'no sequential-numbered migrations found — used the fleet timestamp convention',
    ];
}

function writeIfMissing(string $dst, string $content, string $rootPath): void
{
    if (file_exists($dst)) {
        echo "  Skipped (already exists): " . relativeToRoot2($rootPath, $dst) . "\n";
        return;
    }
    file_put_contents($dst, $content);
    echo "  ✓ Created: " . relativeToRoot2($rootPath, $dst) . "\n";
}

function relativeToRoot2(string $root, string $path): string
{
    $root = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (str_starts_with($path, $root)) {
        return substr($path, strlen($root));
    }
    return $path;
}

/**
 * Keep a table's DECLARATIVE definition (src/postgresql/.../tables/<table>.pgsql)
 * in sync with the ADD COLUMN migration. The gateway's table-deploy step is
 * declarative and NEVER alters an already-existing table on its own — only
 * migrations do — and its read-only verify_schema step (which runs LAST,
 * after migrations) compares live columns against that declarative file.
 * Leaving it un-patched means every future deploy sees permanent drift
 * between "what verify_schema expects" and "what this migration actually
 * added" — the same class of bug as any generator that ALTERs a table via
 * migration without updating its own declarative source.
 *
 * Deliberately conservative: this is a plain-text patch of a file this
 * generator does NOT own (it belongs to whichever generator/hand-written
 * migration originally declared the table), so it only acts when it can
 * locate an unambiguous insertion point — a `CREATE TABLE [IF NOT EXISTS]
 * <table> (` header followed, somewhere after it, by a `);` on its own
 * line (the closing paren of that CREATE TABLE — the formatting convention
 * every table template in this framework already follows). If it can't
 * confidently find that shape, it does NOT guess: it reports 'ambiguous'
 * and leaves the file untouched, printing an explicit manual-fix
 * instruction rather than risking corrupting someone else's schema file.
 *
 * @return 'patched'|'already_patched'|'not_found'|'ambiguous'
 */
function patchDeclarativeTableForSoftDelete(string $filePath, string $table): string
{
    if (!file_exists($filePath)) {
        return 'not_found';
    }

    $content = file_get_contents($filePath);

    if (preg_match('/\bdeleted_at\b/i', $content)) {
        return 'already_patched';
    }

    if (!preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?' . preg_quote($table, '/') . '\s*\(/i', $content, $headerMatch, PREG_OFFSET_CAPTURE)) {
        return 'not_found';
    }
    $searchFrom = $headerMatch[0][1] + strlen($headerMatch[0][0]);

    if (!preg_match('/\n\)\s*;/', $content, $closeMatch, PREG_OFFSET_CAPTURE, $searchFrom)) {
        return 'ambiguous';
    }
    $closePos = $closeMatch[0][1];

    $head = rtrim(substr($content, 0, $closePos));
    $tail = substr($content, $closePos);

    if ($head === '' || substr($head, -1) !== ',') {
        $head .= ',';
    }

    $newColumns = "\n\n    -- Added by `php stone generate soft-delete` — kept in sync with the\n"
        . "    -- ADD COLUMN migration (migrations/*_create_soft_delete.pgsql) so this\n"
        . "    -- declarative file never drifts from live reality (see that migration's\n"
        . "    -- header comment for why that matters to the gateway's verify_schema step).\n"
        . "    deleted_at TIMESTAMPTZ,\n"
        . "    purge_after TIMESTAMPTZ,\n"
        . "    delete_requested_by TEXT";

    file_put_contents($filePath, $head . $newColumns . $tail);

    return 'patched';
}

// ── 1. Declarative archive table ──────────────────────────────────────
echo "\n→ Creating declarative table file...\n";
$tableDst = $tablesDir . DIRECTORY_SEPARATOR . '_deletion_archive.pgsql';
if (file_exists($tableDst)) {
    echo "  Skipped (already exists): " . relativeToRoot2(ROOT_PATH, $tableDst) . "\n";
} else {
    copy($templatesPath . 'tables' . DIRECTORY_SEPARATOR . '_deletion_archive.pgsql.template', $tableDst);
    echo "  ✓ Created: " . relativeToRoot2(ROOT_PATH, $tableDst) . "\n";
}

// ── 2. Per-table SQL functions ────────────────────────────────────────
echo "\n→ Creating SQL functions...\n";

$genericTpl  = file_get_contents($templatesPath . 'functions' . DIRECTORY_SEPARATOR . 'request___TABLE___deletion.pgsql.template');
$emailTpl    = file_get_contents($templatesPath . 'functions' . DIRECTORY_SEPARATOR . 'request___TABLE___deletion_with_email.pgsql.template');
$cascadeTpl  = file_get_contents($templatesPath . 'functions' . DIRECTORY_SEPARATOR . 'request_identities_deletion_cascade.pgsql.template');
$restoreTpl  = file_get_contents($templatesPath . 'functions' . DIRECTORY_SEPARATOR . 'support_restore___TABLE___deletion.pgsql.template');

/** @var string[] function names that get `php stone generate model` wrappers */
$modelFunctions = [];

foreach ($tables as $table => $pk) {
    if ($useCascade && $table === 'identities') {
        $content = $cascadeTpl;
        $fnName = 'request_identities_deletion';
    } elseif ($table === $emailTable) {
        $content = str_replace(['__TABLE__', '__PK_COLUMN__', '__PK_TYPE__', '__EMAIL_COLUMN__'], [$table, $pk, $pkType, $emailColumn], $emailTpl);
        $fnName = "request_{$table}_deletion";
    } else {
        $content = str_replace(['__TABLE__', '__PK_COLUMN__', '__PK_TYPE__'], [$table, $pk, $pkType], $genericTpl);
        $fnName = "request_{$table}_deletion";
    }
    $dst = $functionsDir . DIRECTORY_SEPARATOR . "{$fnName}.pgsql";
    writeIfMissing($dst, $content, ROOT_PATH);
    $modelFunctions[] = $fnName;

    $restoreContent = str_replace(['__TABLE__', '__PK_COLUMN__', '__PK_TYPE__'], [$table, $pk, $pkType], $restoreTpl);
    $restoreFnName = "support_restore_{$table}_deletion";
    $restoreDst = $functionsDir . DIRECTORY_SEPARATOR . "{$restoreFnName}.pgsql";
    writeIfMissing($restoreDst, $restoreContent, ROOT_PATH);
    $modelFunctions[] = $restoreFnName;
}

// purge_expired_deletions() — one shared function, per-table blocks inside.
// 'tenants' gets a DIFFERENT block that also sets a request-scoped GUC
// around its DELETE so tenant-governance's creator-protection trigger lets
// the resulting founder-membership cascade delete through (see that block
// template's own comment for the mechanism); every other table gets the
// generic block.
$purgeBlockTpl = file_get_contents($templatesPath . 'functions' . DIRECTORY_SEPARATOR . '_purge_table_block.pgsql.template');
$purgeBlockTenantsTpl = file_get_contents($templatesPath . 'functions' . DIRECTORY_SEPARATOR . '_purge_table_block_tenants.pgsql.template');
$purgeBlocks = [];
foreach ($tables as $table => $pk) {
    if ($table === 'tenants') {
        $purgeBlocks[] = $purgeBlockTenantsTpl;
    } else {
        $purgeBlocks[] = str_replace(['__TABLE__', '__PK_COLUMN__'], [$table, $pk], $purgeBlockTpl);
    }
}
$purgeContent = str_replace(
    '__PURGE_TABLE_BLOCKS__',
    implode("\n", $purgeBlocks),
    file_get_contents($templatesPath . 'functions' . DIRECTORY_SEPARATOR . 'purge_expired_deletions.pgsql.template')
);
$purgeDst = $functionsDir . DIRECTORY_SEPARATOR . 'purge_expired_deletions.pgsql';
writeIfMissing($purgeDst, $purgeContent, ROOT_PATH);
$modelFunctions[] = 'purge_expired_deletions';

// Gated audit-owner role-split (see cli/generate-audit.php's own comment on
// audit/protected.json): purge_expired_deletions() must be owned by
// {platform}_audit_owner too — same rationale as the capture trigger
// functions (SECURITY DEFINER + owned by a role the runtime role cannot
// touch), since a purge that could be re-pointed or disabled by the runtime
// role would let it silently suppress the immutable DELETE proof this
// function's own DELETEs are supposed to generate via the audit trigger.
// Only touches the manifest if `php stone generate audit` already ran here
// (this generator does not create audit/protected.json on its own — soft-
// delete has no dependency on audit being installed).
foreach ([$nestedMainBase . 'audit', SRC_PATH . 'postgresql' . DIRECTORY_SEPARATOR . 'audit'] as $candidateAuditDir) {
    $candidateManifest = $candidateAuditDir . DIRECTORY_SEPARATOR . 'protected.json';
    if (is_file($candidateManifest)) {
        $existing = json_decode((string) file_get_contents($candidateManifest), true) ?: [];
        $existingFns = is_array($existing['functions'] ?? null) ? $existing['functions'] : [];
        $existing['functions'] = array_values(array_unique(array_merge($existingFns, ['purge_expired_deletions'])));
        file_put_contents($candidateManifest, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        echo "  ✓ Updated " . relativeToRoot2(ROOT_PATH, $candidateManifest) . " (added purge_expired_deletions to the audit-owner role-split manifest)\n";
        break;
    }
}

// is_email_blocked() — only if an email table is configured.
if ($emailTable !== null) {
    $emailBlockedContent = str_replace(['__EMAIL_TABLE__', '__EMAIL_COLUMN__'], [$emailTable, $emailColumn], file_get_contents($templatesPath . 'functions' . DIRECTORY_SEPARATOR . 'is_email_blocked.pgsql.template'));
    $emailBlockedDst = $functionsDir . DIRECTORY_SEPARATOR . 'is_email_blocked.pgsql';
    writeIfMissing($emailBlockedDst, $emailBlockedContent, ROOT_PATH);
    $modelFunctions[] = 'is_email_blocked';
}

// ── 3. Migration (archive table + per-table ADD COLUMN blocks) ───────────
echo "\n→ Creating migration...\n";
$existingMigration = null;
foreach (scandir($migrationsDir) ?: [] as $entry) {
    if (preg_match('/create_soft_delete\.(pgsql|sql)$/i', $entry)) {
        $existingMigration = $entry;
        break;
    }
}
if ($existingMigration !== null) {
    echo "  Skipped (already exists): " . relativeToRoot2(ROOT_PATH, $migrationsDir . DIRECTORY_SEPARATOR . $existingMigration) . "\n";
    echo "  (--tables is only honored on first generation — write a follow-on\n";
    echo "   migration with more __TABLE___column blocks to add soft-delete to\n";
    echo "   additional tables later.)\n";
} else {
    $columnBlockTpl = file_get_contents($templatesPath . 'migrations' . DIRECTORY_SEPARATOR . '_add_columns_block.pgsql.template');
    $columnBlocks = [];
    foreach (array_keys($tables) as $table) {
        $columnBlocks[] = str_replace('__TABLE__', $table, $columnBlockTpl);
    }

    $migrationSql = str_replace(
        '__ADD_COLUMN_BLOCKS__',
        implode("\n", $columnBlocks),
        file_get_contents($templatesPath . 'migrations' . DIRECTORY_SEPARATOR . 'create_soft_delete.pgsql.template')
    );

    [$migrationFilename, $migrationNote] = resolveSoftDeleteMigrationFilename($migrationsDir);
    $migrationDst = $migrationsDir . DIRECTORY_SEPARATOR . $migrationFilename;
    file_put_contents($migrationDst, $migrationSql);
    echo "  ✓ Created: " . relativeToRoot2(ROOT_PATH, $migrationDst) . "\n";
    echo "    ($migrationNote)\n";
}

// ── 3b. Patch each table's OWN declarative file (keep declarative == reality) ─
echo "\n→ Patching declarative table files (deleted_at/purge_after/delete_requested_by)...\n";
foreach (array_keys($tables) as $table) {
    $declarativeFile = $tablesDir . DIRECTORY_SEPARATOR . "{$table}.pgsql";
    $result = patchDeclarativeTableForSoftDelete($declarativeFile, $table);
    switch ($result) {
        case 'patched':
            echo "  ✓ Patched: " . relativeToRoot2(ROOT_PATH, $declarativeFile) . "\n";
            break;
        case 'already_patched':
            echo "  Skipped (already has deleted_at): " . relativeToRoot2(ROOT_PATH, $declarativeFile) . "\n";
            break;
        case 'not_found':
            echo "  ⓘ No declarative file at " . relativeToRoot2(ROOT_PATH, $declarativeFile) . " for table '$table'\n";
            echo "    — nothing to patch (this table is likely declared purely via a\n";
            echo "    migration, e.g. `php stone generate auth:email-password`'s users\n";
            echo "    table; if it DOES have a declarative file under a different name,\n";
            echo "    add deleted_at/purge_after/delete_requested_by to it by hand).\n";
            break;
        case 'ambiguous':
            echo "  ⚠️  Could not confidently locate the CREATE TABLE closing paren in\n";
            echo "     " . relativeToRoot2(ROOT_PATH, $declarativeFile) . " — left UNTOUCHED to avoid\n";
            echo "     corrupting it. Add these columns to it BY HAND:\n";
            echo "       deleted_at TIMESTAMPTZ,\n";
            echo "       purge_after TIMESTAMPTZ,\n";
            echo "       delete_requested_by TEXT\n";
            break;
    }
}

// ── 4. Model wrappers (php stone generate model) ──────────────────────────
echo "\n→ Generating model wrappers (php stone generate model)...\n";
$stoneBinary = $vendorPath . 'stone';
if (!file_exists($stoneBinary)) {
    echo "  ⚠️  Could not find the stone binary at $stoneBinary — skipping automatic\n";
    echo "     model generation. Run these manually:\n";
    foreach ($modelFunctions as $fn) {
        echo "       php stone generate model $fn.pgsql\n";
    }
} else {
    foreach ($modelFunctions as $fn) {
        $cmd = 'cd ' . escapeshellarg(rtrim(ROOT_PATH, DIRECTORY_SEPARATOR))
            . ' && php ' . escapeshellarg($stoneBinary) . ' generate model ' . escapeshellarg("{$fn}.pgsql") . ' 2>&1';
        $modelOutput = [];
        $modelReturnCode = 0;
        exec($cmd, $modelOutput, $modelReturnCode);

        if ($modelReturnCode !== 0) {
            echo "  ⚠️  Failed to generate model wrapper for $fn:\n";
            foreach ($modelOutput as $line) {
                echo "     $line\n";
            }
        } else {
            foreach ($modelOutput as $line) {
                echo "  $line\n";
            }
        }
    }
}

echo "\n✅ Soft-delete scaffolding complete!\n\n";
echo "Next steps:\n";
echo "1. Run migrations: php stone gateway:migrate-main (or php stone migrate up /\n";
echo "   gateway:migrate-tenant, whichever database you generated this against).\n";
echo "   Watch the migration output for 'soft-delete: table \"...\" does not\n";
echo "   exist' WARNINGs — that means a configured table wasn't there yet and\n";
echo "   its columns did NOT get added; create the table and re-run.\n";
echo "2. If any declarative table file above was reported 'ambiguous' or\n";
echo "   'no declarative file', add deleted_at/purge_after/delete_requested_by\n";
echo "   to it by hand — otherwise skip, it's already handled.\n";
echo "3. Wire purge_expired_deletions() into a daily cron/scheduler yourself —\n";
echo "   the framework ships no scheduler. Run `php stone generate audit` FIRST\n";
echo "   (on the same tables) if you want the purge's DELETE to leave an\n";
echo "   immutable trail.\n";
echo "4. Call is_email_blocked(email) from your signin AND signup routes —\n";
echo "   on true, return \"invalid email, contact support\", not the generic\n";
echo "   wrong-password / already-registered messaging. A restored (support_\n";
echo "   restore'd) row is correctly NOT blocked.\n";
echo "5. Filter every read query on the configured tables with `deleted_at IS\n";
echo "   NULL` — this generator does not touch existing read routes/functions,\n";
echo "   you must add the filter to each one yourself.\n";
echo "6. Restore is support-only: call support_restore_<table>_deletion(id,\n";
echo "   actor_id) directly (e.g. from an internal admin tool) — no\n";
echo "   self-service cancel route is generated.\n";
