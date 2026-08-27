<?php

/**
 * Data-Export Generator
 *
 * Scaffolds the async, streaming, resource-capped user-data-download
 * feature (owner priority) into the CONSUMING
 * platform's OWN database + codebase:
 *   - data_export_jobs table (queued/running/succeeded/failed/expired, with
 *     race-free admission control — one open export per requester+scope+tenant)
 *   - enqueue_data_export_job / claim_export_job / complete_export_job /
 *     fail_export_job SQL functions + their `php stone generate model` wrappers
 *   - src/config/data-export.php — the per-platform table manifest (which
 *     tables are 'account'-scoped vs 'tenant'-scoped; EDIT BY HAND, the
 *     generator cannot know this platform's schema)
 *   - src/App/Lib/DataExport/* — the streaming worker (CSV via a genuine
 *     server-side Postgres CURSOR, XLSX via openspout/openspout's
 *     constant-memory writer; NEVER pg_dump, NEVER a whole table in PHP
 *     memory)
 *   - bin/export-worker.php — the worker's CLI entrypoint (--once / --loop;
 *     wire into cron/systemd/supervisor yourself, same posture as
 *     purge_expired_deletions())
 *   - POST {prefix}/data-export/enqueue — the checkbox-driven enqueue route
 *     (registered in src/config/routes.php if it's in the expected flat format)
 *
 * Same precedent as every other generator in this framework (`audit`,
 * `soft-delete`, `tenant-governance`, `invitations`): copies static template
 * files, plus tiny placeholder substitution — no other templating mechanism.
 *
 * Usage:
 *   php stone generate data-export
 *   php stone generate data-export --skip-route
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

if ($argc >= 2 && in_array($argv[1], ['--help', '-h', 'help'], true)) {
    echo "Data-Export Generator\n";
    echo "======================\n\n";
    echo "Scaffolds async, streaming, resource-capped user-data-download into THIS\n";
    echo "database + codebase.\n\n";
    echo "Usage:\n";
    echo "  php stone generate data-export\n";
    echo "  php stone generate data-export --skip-route\n\n";
    echo "This will create:\n";
    echo "  - migrations/{N}_create_data_export_jobs.pgsql\n";
    echo "  - src/postgresql/{main/postgresql/,}tables/data_export_jobs.pgsql\n";
    echo "  - src/postgresql/{main/postgresql/,}functions/{enqueue,claim,complete,fail,\n";
    echo "    reap_stale,expire_succeeded}_*.pgsql\n";
    echo "  - src/App/Database/Functions/Fn*.php (via php stone generate model)\n";
    echo "  - src/config/data-export.php (EDIT THIS — table manifest + tenancy_mode,\n";
    echo "    empty/unset by default)\n";
    echo "  - src/App/Lib/DataExport/{ExportWorker,CursorStreamer,CsvStreamExporter,\n";
    echo "    XlsxStreamExporter,WorkerDbConnection,ExportRowBudget,CellSanitizer,\n";
    echo "    ExportRowCapExceededException}.php\n";
    echo "  - bin/export-worker.php (the worker's CLI entrypoint — --once/--loop/--reap)\n";
    echo "  - src/App/Routes/DataExport/PostEnqueueDataExportRoute.php (fail-closed\n";
    echo "    tenant authorization wired to tenant-governance, if installed)\n";
    echo "  - registers POST {prefix}/data-export/enqueue in src/config/routes.php\n";
    echo "    (unless --skip-route, or the file isn't in the expected flat format)\n\n";
    echo "REQUIRED before this actually works:\n";
    echo "  1. composer require openspout/openspout   (XLSX constant-memory writer)\n";
    echo "  2. Edit src/config/data-export.php — list this platform's real\n";
    echo "     'account'-scoped and 'tenant'-scoped tables, AND set 'tenancy_mode' to\n";
    echo "     'single' or 'db_per_tenant' (+ 'resolve_tenant_database' if the latter).\n";
    echo "     The worker refuses to export a scope with no tables configured, and\n";
    echo "     refuses a tenant-scope job if tenancy_mode is unset or unresolvable —\n";
    echo "     never guesses which database a tenant's rows live in.\n";
    echo "  3. Set DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASSWORD env vars for the\n";
    echo "     worker (same vars DB_MODE=direct uses) — the worker connects\n";
    echo "     DIRECTLY to Postgres for genuine server-side cursor streaming; see\n";
    echo "     WorkerDbConnection.php's header comment for why this is a deliberate\n";
    echo "     exception to the gateway-only rule (worker is out-of-band, not a\n";
    echo "     route handler).\n";
    echo "  4. If this platform does NOT use `php stone generate tenant-governance`,\n";
    echo "     replace PostEnqueueDataExportRoute::tenantExportIsAuthorized()'s body\n";
    echo "     with this platform's own real, positive tenant-ownership check —\n";
    echo "     until then it FAIL-CLOSED denies every tenant-scope export (403), which\n";
    echo "     is correct/safe but means tenant exports won't work yet.\n";
    echo "  5. Wire bin/export-worker.php --loop into cron/systemd/supervisor yourself\n";
    echo "     — the framework ships no scheduler. --loop runs its own periodic\n";
    echo "     maintenance (stale-'running' reaper + expired-artifact cleanup); if you\n";
    echo "     prefer a separate cron entry instead, use `--reap` there and drop the\n";
    echo "     built-in periodic call (see export-worker.php's own comment).\n\n";
    echo "OUT OF SCOPE (left as a clean seam): serving the finished artifact to the\n";
    echo "end user (the future account-portal download endpoint). It must: read\n";
    echo "artifact_ref + download_expires_at from data_export_jobs (via a new\n";
    echo "get_data_export_job() function you write), verify the requester matches\n";
    echo "requested_by (or is authorized for the tenant), 404/410 once past\n";
    echo "download_expires_at, and is the ONLY thing that should ever transition a\n";
    echo "row to status='expired' (this generator never sets that status).\n";
    exit(0);
}

$skipRoute = in_array('--skip-route', $argv, true);

echo "Generating async data-export (data_export_jobs + worker)...\n\n";

$templatesPath = $vendorPath . 'src' . DIRECTORY_SEPARATOR . 'Templates' . DIRECTORY_SEPARATOR . 'DataExport' . DIRECTORY_SEPARATOR;
if (!is_dir($templatesPath)) {
    echo "Error: Data-export templates not found at $templatesPath\n";
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

$libDir    = SRC_PATH . 'App' . DIRECTORY_SEPARATOR . 'Lib' . DIRECTORY_SEPARATOR . 'DataExport';
$routesDir = SRC_PATH . 'App' . DIRECTORY_SEPARATOR . 'Routes' . DIRECTORY_SEPARATOR . 'DataExport';
$configDir = SRC_PATH . 'config';
$binDir    = ROOT_PATH . 'bin';

foreach ([
    'tables' => $tablesDir, 'functions' => $functionsDir, 'migrations' => $migrationsDir,
    'lib' => $libDir, 'routes' => $routesDir, 'config' => $configDir, 'bin' => $binDir,
] as $name => $dir) {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            echo "Error: Failed to create $name directory: $dir\n";
            exit(1);
        }
        echo "Created directory: $dir\n";
    }
}

function writeIfMissingDE(string $dst, string $content, string $rootPath): void
{
    if (file_exists($dst)) {
        echo "  Skipped (already exists): " . relativeToRootDE($rootPath, $dst) . "\n";
        return;
    }
    file_put_contents($dst, $content);
    echo "  ✓ Created: " . relativeToRootDE($rootPath, $dst) . "\n";
}

function copyIfMissingDE(string $src, string $dst, string $rootPath): void
{
    if (file_exists($dst)) {
        echo "  Skipped (already exists): " . relativeToRootDE($rootPath, $dst) . "\n";
        return;
    }
    copy($src, $dst);
    echo "  ✓ Created: " . relativeToRootDE($rootPath, $dst) . "\n";
}

function relativeToRootDE(string $root, string $path): string
{
    $root = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (str_starts_with($path, $root)) {
        return substr($path, strlen($root));
    }
    return $path;
}

/** Mirrors resolveAuditMigrationFilename() / resolveSoftDeleteMigrationFilename(). */
function resolveDataExportMigrationFilename(string $migrationsDir): array
{
    if (!is_dir($migrationsDir)) {
        return [
            '001_create_data_export_jobs.pgsql',
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
            "{$number}_create_data_export_jobs.{$ext}",
            "sequential numbering detected ({$width}-digit) — next number is {$number}",
        ];
    }

    $timestamp = date('Y-m-d_H-i-s');

    return [
        "{$timestamp}_create_data_export_jobs.sql",
        'no sequential-numbered migrations found — used the fleet timestamp convention',
    ];
}

// ── 1. Declarative table ──────────────────────────────────────────────
echo "\n→ Creating declarative table file...\n";
copyIfMissingDE(
    $templatesPath . 'tables' . DIRECTORY_SEPARATOR . 'data_export_jobs.pgsql.template',
    $tablesDir . DIRECTORY_SEPARATOR . 'data_export_jobs.pgsql',
    ROOT_PATH
);

// ── 2. SQL functions ────────────────────────────────────────────────────
echo "\n→ Creating SQL functions...\n";
$sqlFunctions = [
    'enqueue_data_export_job', 'claim_export_job', 'complete_export_job', 'fail_export_job',
    'reap_stale_export_jobs', 'expire_succeeded_export_jobs',
];
foreach ($sqlFunctions as $fn) {
    copyIfMissingDE(
        $templatesPath . 'functions' . DIRECTORY_SEPARATOR . "{$fn}.pgsql.template",
        $functionsDir . DIRECTORY_SEPARATOR . "{$fn}.pgsql",
        ROOT_PATH
    );
}

// ── 3. Migration ─────────────────────────────────────────────────────────
echo "\n→ Creating migration...\n";
$existingMigration = null;
foreach (scandir($migrationsDir) ?: [] as $entry) {
    if (preg_match('/create_data_export_jobs\.(pgsql|sql)$/i', $entry)) {
        $existingMigration = $entry;
        break;
    }
}
if ($existingMigration !== null) {
    echo "  Skipped (already exists): " . relativeToRootDE(ROOT_PATH, $migrationsDir . DIRECTORY_SEPARATOR . $existingMigration) . "\n";
} else {
    $migrationSql = file_get_contents($templatesPath . 'migrations' . DIRECTORY_SEPARATOR . 'create_data_export_jobs.pgsql.template');
    [$migrationFilename, $migrationNote] = resolveDataExportMigrationFilename($migrationsDir);
    $migrationDst = $migrationsDir . DIRECTORY_SEPARATOR . $migrationFilename;
    file_put_contents($migrationDst, $migrationSql);
    echo "  ✓ Created: " . relativeToRootDE(ROOT_PATH, $migrationDst) . "\n";
    echo "    ($migrationNote)\n";
}

// ── 4. Model wrappers (php stone generate model) ──────────────────────────
echo "\n→ Generating model wrappers (php stone generate model)...\n";
$stoneBinary = $vendorPath . 'stone';
if (!file_exists($stoneBinary)) {
    echo "  ⚠️  Could not find the stone binary at $stoneBinary — skipping automatic\n";
    echo "     model generation. Run these manually:\n";
    foreach ($sqlFunctions as $fn) {
        echo "       php stone generate model $fn.pgsql\n";
    }
} else {
    foreach ($sqlFunctions as $fn) {
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

// ── 5. Config manifest ──────────────────────────────────────────────────
echo "\n→ Creating config manifest...\n";
copyIfMissingDE(
    $templatesPath . 'config' . DIRECTORY_SEPARATOR . 'data-export.php.template',
    $configDir . DIRECTORY_SEPARATOR . 'data-export.php',
    ROOT_PATH
);

// ── 6. Worker library files ────────────────────────────────────────────
echo "\n→ Creating worker library (src/App/Lib/DataExport)...\n";
$libFiles = [
    'WorkerDbConnection.php',
    'CursorStreamer.php',
    'ExportRowCapExceededException.php',
    'ExportRowBudget.php',
    'CellSanitizer.php',
    'CsvStreamExporter.php',
    'XlsxStreamExporter.php',
    'ExportWorker.php',
];
foreach ($libFiles as $file) {
    copyIfMissingDE(
        $templatesPath . 'worker' . DIRECTORY_SEPARATOR . "{$file}.template",
        $libDir . DIRECTORY_SEPARATOR . $file,
        ROOT_PATH
    );
}

// ── 7. Worker CLI entrypoint (bin/export-worker.php) ──────────────────────
echo "\n→ Creating worker CLI entrypoint...\n";
$workerBinDst = $binDir . DIRECTORY_SEPARATOR . 'export-worker.php';
copyIfMissingDE(
    $templatesPath . 'worker' . DIRECTORY_SEPARATOR . 'export-worker.php.template',
    $workerBinDst,
    ROOT_PATH
);
@chmod($workerBinDst, 0755);

// ── 8. Enqueue route ────────────────────────────────────────────────────
echo "\n→ Creating enqueue route handler...\n";
copyIfMissingDE(
    $templatesPath . 'routes' . DIRECTORY_SEPARATOR . 'PostEnqueueDataExportRoute.php.template',
    $routesDir . DIRECTORY_SEPARATOR . 'PostEnqueueDataExportRoute.php',
    ROOT_PATH
);

// ── 9. Register route in src/config/routes.php ─────────────────────────
// Same genuinely-working auto-append mechanism `php stone generate
// invitations` uses (see that generator's own comment on why — it reuses
// cli/generate-route.php's flat-v4.0-format merge approach rather than only
// printing manual instructions).
function renderDataExportRouteValue($routeData): string
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

if ($skipRoute) {
    echo "\n→ Skipping route registration (--skip-route).\n";
} else {
    echo "\n→ Registering route in src/config/routes.php...\n";
    $routesConfigPath = SRC_PATH . 'config' . DIRECTORY_SEPARATOR . 'routes.php';
    $newRoutes = [
        ['POST', '/data-export/enqueue', [
            'handler' => '\\App\\Routes\\DataExport\\PostEnqueueDataExportRoute',
            'group'   => 'data-export',
            'action'  => 'enqueue',
        ]],
    ];

    if (!file_exists($routesConfigPath)) {
        echo "  ⚠️  routes.php not found at $routesConfigPath — skipping auto-registration.\n";
        echo "     Manually add this entry to src/config/routes.php:\n";
        foreach ($newRoutes as [$method, $path, $entry]) {
            echo "       $method $path => " . renderDataExportRouteValue($entry) . "\n";
        }
    } else {
        $routes = require $routesConfigPath;

        if (!is_array($routes) || array_key_exists('public', $routes) || array_key_exists('protected', $routes)) {
            echo "  ⚠️  routes.php is not in the expected flat v4.0 format — skipping auto-registration.\n";
            echo "     Manually add this entry to src/config/routes.php:\n";
            foreach ($newRoutes as [$method, $path, $entry]) {
                echo "       $method $path => " . renderDataExportRouteValue($entry) . "\n";
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
                    $routesCode .= "        " . var_export($routePath, true) . " => " . renderDataExportRouteValue($routeData) . ",\n";
                }
                $routesCode .= "    ],\n";
            }
            $routesCode .= "];\n";

            file_put_contents($routesConfigPath, $routesCode);
            echo "  ✓ Updated src/config/routes.php\n";
        }
    }
}

echo "\n✅ Data-export scaffolding complete!\n\n";
echo "Next steps:\n";
echo "1. composer require openspout/openspout   (required — XLSX writer)\n";
echo "2. Run migrations: php stone gateway:migrate-main (or php stone migrate up /\n";
echo "   gateway:migrate-tenant, whichever database you generated this against).\n";
echo "3. Edit src/config/data-export.php — list this platform's real 'account'\n";
echo "   and 'tenants' tables, AND set 'tenancy_mode' ('single' or\n";
echo "   'db_per_tenant' + 'resolve_tenant_database'). The worker refuses to\n";
echo "   export a scope with zero tables configured, or a tenant job with an\n";
echo "   unset/unresolvable tenancy_mode (loud failure, never a silent\n";
echo "   empty/wrong-database export).\n";
echo "4. Set DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASSWORD for the worker process\n";
echo "   (same vars DB_MODE=direct uses — see WorkerDbConnection.php).\n";
echo "5. If this platform does NOT use `php stone generate tenant-governance`,\n";
echo "   replace PostEnqueueDataExportRoute::tenantExportIsAuthorized()'s body\n";
echo "   with a real ownership check — until then tenant exports are\n";
echo "   FAIL-CLOSED denied (403), by design.\n";
echo "6. Run the worker: php bin/export-worker.php --once (manual test) or\n";
echo "   --loop under systemd/supervisor for production (runs its own\n";
echo "   periodic stale-job-reap + expired-artifact cleanup).\n";
echo "7. Build the account-portal download endpoint (out of scope here — see\n";
echo "   --help for its exact contract).\n";
