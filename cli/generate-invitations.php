<?php

/**
 * Invitation System Generator
 *
 * Scaffolds a platform-owned invitation system into the CONSUMING platform's
 * OWN repo — a table migration, three SQL functions (+ their
 * `php stone generate model` wrappers), three route handlers, a generated
 * repository, and a config/invitations.php hook file.
 *
 * The external auth service is NEVER involved in invitation storage — this
 * is a non-negotiable constraint of the design.
 * The framework ships the generic, correctness-sensitive orchestration
 * piece (StoneScriptPHP\Auth\Invitations\InvitationCompletionService) that
 * the generated PostAcceptInvitationRoute calls into; this command only
 * scaffolds the platform-specific storage + route layer — following the
 * exact precedent `php stone generate auth:*` (cli/generate-auth.php)
 * already established for provider-specific auth scaffolding: copy
 * templates into the consuming project, let the platform own and edit them
 * freely from that point on.
 *
 * Usage:
 *   php stone generate invitations
 *
 * This will create:
 *   - migrations/{N}_create_platform_invitations.pgsql (or .sql — see
 *     resolveMigrationFilename() below for why the extension/numbering is
 *     dynamic, not a fixed assumption)
 *   - src/postgresql/functions/{create,get,mark}_platform_invitation*.pgsql
 *   - src/App/Database/Functions/Fn*.php (via `php stone generate model`)
 *   - src/App/Routes/Invitations/{PlatformInvitationRepository,
 *     PostCreateInvitationRoute,GetInvitationRoute,PostAcceptInvitationRoute}.php
 *   - config/invitations.php
 *   - Registers the three routes in src/config/routes.php
 */

if (!defined('ROOT_PATH')) {
    // Check if running from 'api' subdirectory (common structure: project/api/)
    // — identical detection block to cli/generate-auth.php, deliberately kept
    // byte-for-byte the same so both generators agree on where "the project"
    // is regardless of how this framework package is installed.
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
if (!defined('CONFIG_PATH')) define('CONFIG_PATH', SRC_PATH . 'App' . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR);

// Find vendor path (framework templates + `stone` binary location).
$vendorPath = ROOT_PATH . 'vendor' . DIRECTORY_SEPARATOR . 'progalaxyelabs' . DIRECTORY_SEPARATOR . 'stonescriptphp' . DIRECTORY_SEPARATOR;
if (!is_dir($vendorPath)) {
    // Development mode - framework is a sibling directory
    $vendorPath = dirname(ROOT_PATH) . DIRECTORY_SEPARATOR . 'StoneScriptPHP' . DIRECTORY_SEPARATOR;
}

$argv = $_SERVER['argv'] ?? $argv;
$argc = $_SERVER['argc'] ?? $argc;

if ($argc >= 2 && in_array($argv[1], ['--help', '-h', 'help'], true)) {
    echo "Invitation System Generator\n";
    echo "============================\n\n";
    echo "Scaffolds a platform-owned invitation system (table + SQL functions +\n";
    echo "routes + repository + config hook) into THIS project. Auth is never\n";
    echo "involved in invitation storage.\n\n";
    echo "Usage: php stone generate invitations\n\n";
    echo "This will create:\n";
    echo "  - migrations/{N}_create_platform_invitations.pgsql\n";
    echo "  - src/postgresql/functions/{create,get,mark}_platform_invitation*.pgsql\n";
    echo "  - src/App/Database/Functions/Fn*.php (via php stone generate model)\n";
    echo "  - src/App/Routes/Invitations/*.php (repository + 3 route handlers)\n";
    echo "  - config/invitations.php\n";
    echo "  - Registers the 3 routes in src/config/routes.php\n";
    exit(0);
}

echo "Generating platform-owned invitation system...\n\n";

$templatesPath = $vendorPath . 'src' . DIRECTORY_SEPARATOR . 'Templates' . DIRECTORY_SEPARATOR . 'Invitations' . DIRECTORY_SEPARATOR;
if (!is_dir($templatesPath)) {
    echo "Error: Invitation templates not found at $templatesPath\n";
    echo "Your progalaxyelabs/stonescriptphp version may be too old — run:\n";
    echo "  composer update progalaxyelabs/stonescriptphp\n";
    exit(1);
}

// ── 1. Directories ──────────────────────────────────────────────────────
$dirs = [
    'migrations' => ROOT_PATH . 'migrations',
    // First-priority search dir per cli/generate-model.php's own $search_dirs
    // list — keeps `php stone generate model <file>` (called below, step 4)
    // able to find these without any extra flag.
    'functions'  => SRC_PATH . 'postgresql' . DIRECTORY_SEPARATOR . 'functions',
    'routes'     => SRC_PATH . 'App' . DIRECTORY_SEPARATOR . 'Routes' . DIRECTORY_SEPARATOR . 'Invitations',
    'config'     => ROOT_PATH . 'config',
];

foreach ($dirs as $name => $dir) {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            echo "Error: Failed to create $name directory: $dir\n";
            exit(1);
        }
        echo "Created directory: $dir\n";
    }
}

/**
 * Decide the migration's destination filename + a human-readable note
 * about which convention was used.
 *
 * DEVIATION FROM THE ORIGINAL DESIGN (documented per this repo's own rule that
 * deviations must be explicit, not silent): the original design proposed a fixed
 * `{next_number}_create_platform_invitations.pgsql` name, following the
 * `001_create_users_table.sql`-style sequential-numbered convention
 * `src/Templates/Migrations/` (and `cli/generate-auth.php`) use for
 * from-scratch scaffolding. Direct inspection of a real fleet platform's
 * migrations directory shows production platforms do NOT use that
 * convention — they use gateway-registration-mode
 * TIMESTAMPED migrations (`YYYY-MM-DD_HH-MM-SS_description.sql`). This
 * function follows whichever convention the TARGET project's own
 * migrations/ directory already uses:
 *   - If sequential-numbered migrations exist (e.g. this project ran
 *     `php stone generate auth:email-password` first, which does use that
 *     convention) → continue the same sequence exactly.
 *   - Otherwise (no migrations yet, OR the real fleet's timestamp
 *     convention is already in use) → use a timestamped filename, so the
 *     file this command produces is immediately consistent with what
 *     actually ships to production instead of inventing a sequence number
 *     with no basis.
 *
 * @return array{0: string, 1: string} [filename, note]
 */
function resolveMigrationFilename(string $migrationsDir): array
{
    if (!is_dir($migrationsDir)) {
        return [
            '001_create_platform_invitations.pgsql',
            'no existing migrations directory — starting fresh sequential numbering (matches the php stone generate auth:* scaffold convention)',
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
            "{$number}_create_platform_invitations.{$ext}",
            "sequential numbering detected ({$width}-digit) — next number is {$number}",
        ];
    }

    $timestamp = date('Y-m-d_H-i-s');

    return [
        "{$timestamp}_create_platform_invitations.sql",
        'no sequential-numbered migrations found — used the real fleet timestamp convention instead of a from-scratch sequential-number assumption',
    ];
}

// ── 2. Migration ─────────────────────────────────────────────────────────
echo "\n→ Creating migration...\n";

// A freshly-computed name is always the NEXT free number/timestamp (by
// design — see resolveMigrationFilename()'s docblock), so it can never
// collide with a run from a moment ago. Re-running this command would
// therefore create a second, redundant (though harmless — the DDL itself
// is idempotent) migration file unless we first check whether an
// invitations migration already exists under ANY name.
$existingMigration = null;
foreach (scandir($dirs['migrations']) ?: [] as $entry) {
    if (preg_match('/create_platform_invitations\.(pgsql|sql)$/i', $entry)) {
        $existingMigration = $entry;
        break;
    }
}

if ($existingMigration !== null) {
    echo "  Skipped (already exists): migrations/$existingMigration\n";
} else {
    [$migrationFilename, $migrationNote] = resolveMigrationFilename($dirs['migrations']);
    $migrationDestination = $dirs['migrations'] . DIRECTORY_SEPARATOR . $migrationFilename;
    copy($templatesPath . 'migrations' . DIRECTORY_SEPARATOR . 'create_platform_invitations.pgsql.template', $migrationDestination);
    echo "  ✓ Created: migrations/$migrationFilename\n";
    echo "    ($migrationNote)\n";
}

// ── 3. SQL functions ─────────────────────────────────────────────────────
$functionFiles = [
    'create_platform_invitation.pgsql',
    'get_platform_invitation_by_token_hash.pgsql',
    'mark_platform_invitation_accepted.pgsql',
];

echo "\n→ Creating SQL functions...\n";
foreach ($functionFiles as $fn) {
    $src = $templatesPath . 'functions' . DIRECTORY_SEPARATOR . $fn . '.template';
    $dst = $dirs['functions'] . DIRECTORY_SEPARATOR . $fn;

    if (file_exists($dst)) {
        echo "  Skipped (already exists): src/postgresql/functions/$fn\n";
        continue;
    }

    copy($src, $dst);
    echo "  ✓ Created: src/postgresql/functions/$fn\n";
}

// ── 4. Model wrappers (php stone generate model) ────────────────────────
echo "\n→ Generating model wrappers (php stone generate model)...\n";
$stoneBinary = $vendorPath . 'stone';
if (!file_exists($stoneBinary)) {
    echo "  ⚠️  Could not find the stone binary at $stoneBinary — skipping automatic\n";
    echo "     model generation. Run these manually:\n";
    foreach ($functionFiles as $fn) {
        echo "       php stone generate model $fn\n";
    }
} else {
    foreach ($functionFiles as $fn) {
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

// ── 5. Route handlers + repository ──────────────────────────────────────
echo "\n→ Creating route handlers...\n";
$routeFiles = [
    'PlatformInvitationRepository.php',
    'PostCreateInvitationRoute.php',
    'GetInvitationRoute.php',
    'PostAcceptInvitationRoute.php',
];

foreach ($routeFiles as $file) {
    $src = $templatesPath . 'routes' . DIRECTORY_SEPARATOR . $file . '.template';
    $dst = $dirs['routes'] . DIRECTORY_SEPARATOR . $file;

    if (file_exists($dst)) {
        echo "  Skipped (already exists): src/App/Routes/Invitations/$file\n";
        continue;
    }

    copy($src, $dst);
    echo "  ✓ Created: src/App/Routes/Invitations/$file\n";
}

// ── 6. config/invitations.php ────────────────────────────────────────────
echo "\n→ Creating config hook file...\n";
$configDestination = $dirs['config'] . DIRECTORY_SEPARATOR . 'invitations.php';
if (file_exists($configDestination)) {
    echo "  Skipped (already exists): config/invitations.php\n";
} else {
    copy($templatesPath . 'config' . DIRECTORY_SEPARATOR . 'invitations.php.template', $configDestination);
    echo "  ✓ Created: config/invitations.php\n";
}

// ── 7. Register routes in src/config/routes.php ─────────────────────────
//
// NOTE: the original design assumed this was the "same mechanic generate-auth.php already
// uses". On inspection, generate-auth.php's help text CLAIMS to update
// routes.php but its actual implementation does not — it only prints
// manual instructions (verified by reading cli/generate-auth.php in full).
// This generator does the real thing instead, reusing the genuinely-working
// auto-append mechanism cli/generate-route.php implements (loads
// routes.php, validates the flat v4.0 format, merges in new entries,
// re-renders every entry via the same var_export()-based approach). A
// working generator is a better outcome than reproducing generate-auth.php's
// unfulfilled promise — documented here per this repo's "deviate, don't
// silently diverge" rule.
//
// Route paths are registered WITHOUT a tenant-prefix (e.g. `/invitations`,
// not `/portal/tenant/{tenantId}/invitations`) because that prefix varies
// per platform (T2 vs T3 — every platform's own routing convention differs)
// and this generator has no way to know it. Adjust the paths below in
// src/config/routes.php for this platform's own routing convention after
// generation — printed as an explicit next step.
echo "\n→ Registering routes in src/config/routes.php...\n";

/**
 * Render a routes.php route value back to PHP source — same logic as
 * cli/generate-route.php's renderRouteValue(), duplicated here (not
 * `require`d) because that file is a top-level CLI script with argv-parsing
 * side effects, not a reusable library.
 */
function renderInvitationsRouteValue($routeData): string
{
    if (is_string($routeData)) {
        $class = str_ends_with($routeData, '::class') ? substr($routeData, 0, -7) : $routeData;
        return $class . '::class';
    }

    if (is_array($routeData)) {
        $parts = [];
        foreach ($routeData as $key => $value) {
            if ($key === 'handler' && is_string($value)) {
                $class = str_ends_with($value, '::class') ? substr($value, 0, -7) : $value;
                $parts[] = "'handler' => {$class}::class";
            } else {
                $parts[] = var_export($key, true) . ' => ' . var_export($value, true);
            }
        }
        return '[' . implode(', ', $parts) . ']';
    }

    return var_export($routeData, true);
}

$routesConfigPath = SRC_PATH . 'config' . DIRECTORY_SEPARATOR . 'routes.php';
$newRoutes = [
    ['GET', '/invitations/{token}', [
        'handler'   => '\\App\\Routes\\Invitations\\GetInvitationRoute',
        'group'     => 'invitations',
        'action'    => 'get',
        'is_public' => true,
    ]],
    ['POST', '/invitations', [
        'handler' => '\\App\\Routes\\Invitations\\PostCreateInvitationRoute',
        'group'   => 'invitations',
        'action'  => 'create',
    ]],
    ['POST', '/invitations/{token}/accept', [
        'handler'   => '\\App\\Routes\\Invitations\\PostAcceptInvitationRoute',
        'group'     => 'invitations',
        'action'    => 'accept',
        'is_public' => true,
    ]],
];

if (!file_exists($routesConfigPath)) {
    echo "  ⚠️  routes.php not found at $routesConfigPath — skipping auto-registration.\n";
    echo "     Manually add these entries to src/config/routes.php:\n";
    foreach ($newRoutes as [$method, $path, $entry]) {
        echo "       $method $path => " . renderInvitationsRouteValue($entry) . "\n";
    }
} else {
    $routes = require $routesConfigPath;

    if (!is_array($routes) || array_key_exists('public', $routes) || array_key_exists('protected', $routes)) {
        echo "  ⚠️  routes.php is not in the expected flat v4.0 format — skipping auto-registration.\n";
        echo "     Manually add these entries to src/config/routes.php:\n";
        foreach ($newRoutes as [$method, $path, $entry]) {
            echo "       $method $path => " . renderInvitationsRouteValue($entry) . "\n";
        }
    } else {
        foreach ($newRoutes as [$method, $path, $entry]) {
            if (!isset($routes[$method])) {
                $routes[$method] = [];
            }
            if (isset($routes[$method][$path])) {
                echo "  Skipped (already registered): $method $path\n";
                continue;
            }
            $routes[$method][$path] = $entry;
            echo "  ✓ Registered: $method $path\n";
        }

        $routesCode = "<?php\n\nreturn [\n";
        foreach ($routes as $httpMethod => $methodRoutes) {
            if (!is_array($methodRoutes)) {
                continue;
            }
            $routesCode .= "    '$httpMethod' => [\n";
            foreach ($methodRoutes as $routePath => $routeData) {
                $routesCode .= "        " . var_export($routePath, true) . " => " . renderInvitationsRouteValue($routeData) . ",\n";
            }
            $routesCode .= "    ],\n";
        }
        $routesCode .= "];\n";

        file_put_contents($routesConfigPath, $routesCode);
        echo "  ✓ Updated src/config/routes.php\n";
    }
}

// ── Syntax validation ─────────────────────────────────────────────────────
echo "\n→ Validating generated PHP syntax...\n";
$filesToValidate = array_merge(
    array_map(fn($f) => $dirs['routes'] . DIRECTORY_SEPARATOR . $f, $routeFiles),
    [$configDestination, $routesConfigPath]
);
$filesToValidate = array_values(array_filter($filesToValidate, fn($f) => file_exists($f)));

$syntaxErrors = [];
foreach ($filesToValidate as $file) {
    $output = [];
    $returnVar = 0;
    exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $returnVar);
    if ($returnVar !== 0) {
        $syntaxErrors[] = ['file' => $file, 'error' => implode("\n", $output)];
    }
}

if (!empty($syntaxErrors)) {
    echo "  ⚠️  Syntax errors detected:\n";
    foreach ($syntaxErrors as $error) {
        echo "\n  " . basename($error['file']) . ":\n  " . $error['error'] . "\n";
    }
} else {
    echo "  ✓ All generated PHP files have valid syntax\n";
}

echo "\n✅ Invitation system scaffolding complete!\n\n";
echo "Next steps:\n";
echo "1. Run migrations: php stone migrate up\n";
echo "2. Wire config/invitations.php's 'role_resolver' and 'membership_writer'\n";
echo "   closures to this platform's own role model and membership table.\n";
echo "3. Add this platform's own authorization check to the top of\n";
echo "   PostCreateInvitationRoute::process() (see its TODO comment) — without\n";
echo "   it, ANY authenticated caller can invite anyone at any role.\n";
echo "4. If this platform uses a tenant-prefixed routing convention (e.g. T3's\n";
echo "   /portal/tenant/{tenantId}/...), adjust the 3 route paths this command\n";
echo "   registered in src/config/routes.php accordingly — this generator\n";
echo "   cannot know that convention ahead of time.\n";
echo "5. Regenerate the TypeScript client: php stone generate client\n";
