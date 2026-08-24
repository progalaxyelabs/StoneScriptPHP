<?php

/**
 * Tenant Governance Generator
 *
 * Scaffolds a platform-owned tenant membership + governance model into the
 * CONSUMING platform's OWN repo — the `tenant_memberships` main-DB table (+
 * creator-immutability trigger), fourteen SQL functions (twelve public + two
 * internal helpers), and their `php stone generate model` wrappers for the
 * public ones, plus an OPTIONAL config/tenant-governance.php holding the
 * display-name enricher hook.
 *
 * The framework ships the generic resolver
 * (StoneScriptPHP\Auth\TenantGovernance\TenantGovernanceResolver) that a
 * platform wires into config/auth.php; this command only scaffolds the
 * platform-specific storage + SQL layer — same precedent as
 * `php stone generate invitations`.
 *
 * Deliberately does NOT scaffold promote/demote/invite HTTP routes or any
 * admin UI — those are platform-specific business
 * routes, built on top of the generated functions the same way any other
 * feature route is built.
 *
 * Usage:
 *   php stone generate tenant-governance
 */

if (!defined('ROOT_PATH')) {
    // Identical detection block to cli/generate-invitations.php /
    // cli/generate-auth.php — kept byte-for-byte so all generators agree on
    // where "the project" is regardless of how this package is installed.
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

if ($argc >= 2 && in_array($argv[1], ['--help', '-h', 'help'], true)) {
    echo "Tenant Governance Generator\n";
    echo "===========================\n\n";
    echo "Scaffolds a platform-owned tenant membership + governance model\n";
    echo "(tenant_memberships table + trigger + 14 SQL functions + model\n";
    echo "wrappers + optional config) into THIS project. Roles/governance live\n";
    echo "in this platform, never in auth.\n\n";
    echo "Usage: php stone generate tenant-governance\n\n";
    echo "This will create:\n";
    echo "  - migrations/{N}_create_tenant_memberships.pgsql\n";
    echo "  - src/postgresql/{main/postgresql/,}tables/tenant_memberships.pgsql\n";
    echo "  - src/postgresql/{main/postgresql/,}functions/{12 public + 2 helper}.pgsql\n";
    echo "  - src/App/Database/Functions/Fn*.php (via php stone generate model, public fns only)\n";
    echo "  - config/tenant-governance.php (optional enricher hook)\n\n";
    echo "Does NOT register HTTP routes — promote/demote/invite endpoints are\n";
    echo "platform-specific. Wire config/auth.php's\n";
    echo "tenants_resolver/roles_resolver to TenantGovernanceResolver yourself.\n";
    exit(0);
}

echo "Generating platform-owned tenant governance model...\n\n";

$templatesPath = $vendorPath . 'src' . DIRECTORY_SEPARATOR . 'Templates' . DIRECTORY_SEPARATOR . 'TenantGovernance' . DIRECTORY_SEPARATOR;
if (!is_dir($templatesPath)) {
    echo "Error: Tenant governance templates not found at $templatesPath\n";
    echo "Your progalaxyelabs/stonescriptphp version may be too old — run:\n";
    echo "  composer update progalaxyelabs/stonescriptphp\n";
    exit(1);
}

// ── Layout detection ────────────────────────────────────────────────────
//
// tenant_memberships is a MAIN-DB table. Real fleet
// platforms nest main-DB schema under src/postgresql/main/postgresql/{tables,
// functions,migrations}. A simpler project may use the flat
// src/postgresql/{tables,functions} + migrations/ layout the invitations
// generator defaults to. Detect the nested main layout and prefer it; else
// fall back to flat. Either way, generate-model.php's own $search_dirs
// already covers both functions locations, so the model step finds them.
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
$configDir = ROOT_PATH . 'config';

foreach (['tables' => $tablesDir, 'functions' => $functionsDir, 'migrations' => $migrationsDir, 'config' => $configDir] as $name => $dir) {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            echo "Error: Failed to create $name directory: $dir\n";
            exit(1);
        }
        echo "Created directory: $dir\n";
    }
}

/**
 * Decide the migration's destination filename — mirrors
 * cli/generate-invitations.php::resolveMigrationFilename() exactly (continue
 * the target's existing sequential numbering if any; else use the real
 * fleet's timestamp convention rather than inventing 001_ with no basis).
 *
 * @return array{0: string, 1: string} [filename, note]
 */
function resolveGovernanceMigrationFilename(string $migrationsDir): array
{
    if (!is_dir($migrationsDir)) {
        return [
            '001_create_tenant_memberships.pgsql',
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
            "{$number}_create_tenant_memberships.{$ext}",
            "sequential numbering detected ({$width}-digit) — next number is {$number}",
        ];
    }

    $timestamp = date('Y-m-d_H-i-s');

    return [
        "{$timestamp}_create_tenant_memberships.sql",
        'no sequential-numbered migrations found — used the fleet timestamp convention',
    ];
}

// ── 1. Table (declarative — gateway schema-sync source of truth) ─────────
echo "\n→ Creating declarative table file...\n";
$tableDst = $tablesDir . DIRECTORY_SEPARATOR . 'tenant_memberships.pgsql';
if (file_exists($tableDst)) {
    echo "  Skipped (already exists): " . relativeTo(ROOT_PATH, $tableDst) . "\n";
} else {
    copy($templatesPath . 'tables' . DIRECTORY_SEPARATOR . 'tenant_memberships.pgsql.template', $tableDst);
    echo "  ✓ Created: " . relativeTo(ROOT_PATH, $tableDst) . "\n";
}

// ── 2. Migration ─────────────────────────────────────────────────────────
echo "\n→ Creating migration...\n";
$existingMigration = null;
foreach (scandir($migrationsDir) ?: [] as $entry) {
    if (preg_match('/create_tenant_memberships\.(pgsql|sql)$/i', $entry)) {
        $existingMigration = $entry;
        break;
    }
}
if ($existingMigration !== null) {
    echo "  Skipped (already exists): " . relativeTo(ROOT_PATH, $migrationsDir . DIRECTORY_SEPARATOR . $existingMigration) . "\n";
} else {
    [$migrationFilename, $migrationNote] = resolveGovernanceMigrationFilename($migrationsDir);
    $migrationDst = $migrationsDir . DIRECTORY_SEPARATOR . $migrationFilename;
    copy($templatesPath . 'migrations' . DIRECTORY_SEPARATOR . 'create_tenant_memberships.pgsql.template', $migrationDst);
    echo "  ✓ Created: " . relativeTo(ROOT_PATH, $migrationDst) . "\n";
    echo "    ($migrationNote)\n";
}

// ── 3. SQL functions ─────────────────────────────────────────────────────
// Two internal helpers (leading underscore) get NO model wrapper — no PHP
// calls them directly (_tenant_memberships_protect_creator is a trigger fn;
// _tenant_membership_tier is reused only inside the public functions).
$internalFunctions = [
    '_tenant_memberships_protect_creator.pgsql',
    '_tenant_membership_tier.pgsql',
];
$publicFunctions = [
    'create_tenant_membership.pgsql',
    'add_member.pgsql',
    'promote_to_admin.pgsql',
    'demote_admin.pgsql',
    'promote_to_owner.pgsql',
    'demote_owner.pgsql',
    'set_job_role.pgsql',
    'set_membership_status.pgsql',
    'remove_member.pgsql',
    'get_tenant_memberships.pgsql',
    'get_identity_tenant_memberships.pgsql',
    'resolve_role_id.pgsql',
];

/**
 * Reads DB_GATEWAY_PLATFORM from this project's .env — needed only to name
 * the `{platform}_audit_owner` role inside _tenant_memberships_protect_creator's
 * sanctioned purge bypass (see that template's header comment and
 * cli/generate-audit.php's identical helper, which this mirrors). A plain
 * regex read, not Env::load() — this standalone CLI script doesn't have the
 * full framework boot/autoload context.
 */
function detectPlatformCodeForGovernance(string $rootPath): ?string
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

$platformCodeForAuditOwner = detectPlatformCodeForGovernance(ROOT_PATH);
// Unresolvable platform ⇒ a placeholder that can never equal any real
// current_user (safe no-op, not a bypass) — see the template's own comment.
$auditOwnerRole = $platformCodeForAuditOwner !== null
    ? $platformCodeForAuditOwner . '_audit_owner'
    : '__unresolved_platform___audit_owner';

echo "\n→ Creating SQL functions...\n";
foreach (array_merge($internalFunctions, $publicFunctions) as $fn) {
    $src = $templatesPath . 'functions' . DIRECTORY_SEPARATOR . $fn . '.template';
    $dst = $functionsDir . DIRECTORY_SEPARATOR . $fn;
    if (file_exists($dst)) {
        echo "  Skipped (already exists): " . relativeTo(ROOT_PATH, $dst) . "\n";
        continue;
    }
    if ($fn === '_tenant_memberships_protect_creator.pgsql') {
        // Only file needing substitution — closes the tenant-purge GUC spoof
        // by naming this platform's audit_owner role (see the helper above).
        $content = str_replace('__AUDIT_OWNER_ROLE__', $auditOwnerRole, (string) file_get_contents($src));
        file_put_contents($dst, $content);
    } else {
        copy($src, $dst);
    }
    echo "  ✓ Created: " . relativeTo(ROOT_PATH, $dst) . "\n";
}

// ── 4. Model wrappers (php stone generate model) — PUBLIC functions only ──
echo "\n→ Generating model wrappers (php stone generate model)...\n";
$stoneBinary = $vendorPath . 'stone';
if (!file_exists($stoneBinary)) {
    echo "  ⚠️  Could not find the stone binary at $stoneBinary — skipping automatic\n";
    echo "     model generation. Run these manually:\n";
    foreach ($publicFunctions as $fn) {
        echo "       php stone generate model $fn\n";
    }
} else {
    foreach ($publicFunctions as $fn) {
        $cmd = 'cd ' . escapeshellarg(rtrim(ROOT_PATH, DIRECTORY_SEPARATOR))
            . ' && php ' . escapeshellarg($stoneBinary) . ' generate model ' . escapeshellarg($fn) . ' 2>&1';
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

// ── 5. config/tenant-governance.php (optional enricher hook) ─────────────
echo "\n→ Creating config hook file...\n";
$configDst = $configDir . DIRECTORY_SEPARATOR . 'tenant-governance.php';
if (file_exists($configDst)) {
    echo "  Skipped (already exists): config/tenant-governance.php\n";
} else {
    copy($templatesPath . 'config' . DIRECTORY_SEPARATOR . 'tenant-governance.php.template', $configDst);
    echo "  ✓ Created: config/tenant-governance.php\n";
}

// ── Syntax validation (the config file — the only generated PHP here) ─────
echo "\n→ Validating generated PHP syntax...\n";
$syntaxOk = true;
if (file_exists($configDst)) {
    $out = [];
    $rc = 0;
    exec('php -l ' . escapeshellarg($configDst) . ' 2>&1', $out, $rc);
    if ($rc !== 0) {
        $syntaxOk = false;
        echo "  ⚠️  Syntax error in config/tenant-governance.php:\n  " . implode("\n  ", $out) . "\n";
    }
}
if ($syntaxOk) {
    echo "  ✓ Generated PHP has valid syntax\n";
}

echo "\n✅ Tenant governance scaffolding complete!\n\n";
echo "Next steps:\n";
echo "1. Run migrations: php stone gateway:migrate-main (or php stone migrate up)\n";
echo "2. Wire config/auth.php's resolvers to the framework class:\n";
echo "     use StoneScriptPHP\\Auth\\TenantGovernance\\TenantGovernanceResolver;\n";
echo "     \$governance = new TenantGovernanceResolver();\n";
echo "     // ... in the returned config array:\n";
echo "     'tenants_resolver' => \$governance->tenantsResolver(),\n";
echo "     'roles_resolver'   => \$governance->rolesResolver(),\n";
echo "3. Call create_tenant_membership(identity_id, tenant_id) from this\n";
echo "   platform's tenant-provisioning route so the creator gets a founding\n";
echo "   owner membership (see FnCreateTenantMembership).\n";
echo "4. For human-readable tenant names in available_tenants, implement the\n";
echo "   enricher in config/tenant-governance.php and construct the resolver\n";
echo "   with it: new TenantGovernanceResolver(\$cfg['tenant_enricher']).\n";
echo "5. Build promote/demote/member-management routes on top of the generated\n";
echo "   Fn* wrappers as needed — this generator intentionally ships none.\n";

/**
 * Render an absolute path relative to ROOT_PATH for tidy output. Falls back
 * to the absolute path if it isn't under ROOT_PATH.
 */
function relativeTo(string $root, string $path): string
{
    $root = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (str_starts_with($path, $root)) {
        return substr($path, strlen($root));
    }
    return $path;
}
