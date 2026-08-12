<?php

/**
 * android-server Generator (step 4 of the android-server track)
 *
 * Usage: php stone generate android-server
 *
 * Produces an `android-server/` tree (build output — regenerated on every
 * run, never hand-edited) from the current project's `src/`/`public/`:
 *   - business logic, DTOs, config copied byte-for-byte
 *   - routes.php mechanically transformed: admin + auth/provisioning routes
 *     EXCLUDED (they have no reachable network dependency offline and no
 *     meaning on a single local-store device). Every OTHER route's real
 *     `access` level is left UNCHANGED — see docs/ANDROID-SERVER-DESIGN.md
 *     §3 (rewritten 2026-08-01): offline auth is a REAL, locally-minted
 *     API token the app shell attaches to every request, not a
 *     `access: public` bypass (that mechanism was tried and found to be a
 *     false-positive proof — a public route never populates AuthContext,
 *     silently breaking any route that reads identity_id/tenant_id).
 *   - a schema-manifest.json describing which src/postgresql/* files an
 *     on-device schema-bringup driver should apply (that driver itself is a
 *     separate track — libphpandroid/android-server C++ host — this
 *     generator only produces the manifest it consumes)
 *   - a `.env` offline profile with DB_MODE=pgandroid
 *
 * Full reasoning + evidence this was built from:
 *   divisions/opensource/libphpandroid/docs/ANDROID-SERVER-DESIGN.md
 *
 * This file intentionally contains NO platform-specific names/schema — the
 * policy defaults are generic across any StoneScriptPHP project using the
 * conventional src/config/routes.php + src/postgresql/ layout. Platforms
 * override policy via src/config/android-server-policy.php and
 * src/config/android-server-schema-policy.php (both optional).
 */

require_once __DIR__ . '/generate-common.php';
require_once __DIR__ . '/helpers/color.php';

final class AndroidServerGenerator
{
    private string $projectRoot;
    private string $srcPath;
    private string $configPath;
    private string $publicPath;
    private string $outputPath;

    /** @var string[] */
    private array $warnings = [];

    public function __construct()
    {
        $this->projectRoot = ROOT_PATH;
        $this->srcPath = ROOT_PATH . 'src' . DIRECTORY_SEPARATOR;
        $this->configPath = $this->srcPath . 'config' . DIRECTORY_SEPARATOR;
        $this->publicPath = ROOT_PATH . 'public' . DIRECTORY_SEPARATOR;
        $this->outputPath = ROOT_PATH . 'android-server' . DIRECTORY_SEPARATOR;
    }

    public function run(): void
    {
        $this->printBanner();
        $this->validateLayout();
        $this->resetOutputDir();

        echo Color::blue("→ copying src/, public/, composer.*, keys/...\n");
        $this->copyDirRecursive($this->srcPath, $this->outputPath . 'src');
        if (is_dir($this->publicPath)) {
            $this->copyDirRecursive($this->publicPath, $this->outputPath . 'public');
        }
        $this->copyIfExists('composer.json');
        $this->copyIfExists('composer.lock');
        if (is_dir($this->projectRoot . 'keys')) {
            $this->copyDirRecursive($this->projectRoot . 'keys', $this->outputPath . 'keys');
        } else {
            $this->warnings[] = "No keys/ directory found at project root — the offline profile's " .
                ".env still points JWT_PUBLIC_KEY_PATH/JWT_PRIVATE_KEY_PATH at ./keys/*.pem. " .
                "TrustedIssuerVerifier reads the public key at config-load time regardless of " .
                "DB_MODE — boot will fail without it. Run `php stone generate jwt` first, or copy " .
                "an existing keypair into android-server/keys/ before deploying.";
        }

        $routeStats = $this->transformRoutes();
        $schemaStats = $this->generateSchemaManifest();
        $this->runSafetyNetCheck($routeStats, $schemaStats);
        $this->generateEnv();
        $this->writeReadme($routeStats, $schemaStats);

        $this->printSummary($routeStats, $schemaStats);
    }

    // ── setup ────────────────────────────────────────────────────────────

    private function printBanner(): void
    {
        echo "\n";
        echo "╔══════════════════════════════════════════════╗\n";
        echo "║   android-server generator                    ║\n";
        echo "╚══════════════════════════════════════════════╝\n\n";
    }

    private function validateLayout(): void
    {
        $routesFile = $this->configPath . 'routes.php';
        if (!file_exists($routesFile)) {
            echo Color::red("Error: {$routesFile} not found.\n");
            echo "This generator expects the standard StoneScriptPHP-Server layout:\n";
            echo "  src/config/routes.php\n";
            echo "  src/postgresql/... (either flat: types/tables/views/functions/seeders,\n";
            echo "                       or split: main/postgresql/... + tenant/postgresql/...)\n";
            exit(1);
        }
    }

    private function resetOutputDir(): void
    {
        if (is_dir($this->outputPath)) {
            $this->removeDirRecursive($this->outputPath);
        }
        mkdir($this->outputPath, 0755, true);
    }

    // ── routes.php transform (design doc §3) ────────────────────────────

    /**
     * @return array{included:int, excluded:int, excluded_paths:string[], included_handlers:string[]}
     */
    private function transformRoutes(): array
    {
        echo Color::blue("→ transforming routes.php (exclude admin/auth-provisioning routes only)...\n");

        $configOut = $this->outputPath . 'src' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR;

        // Preserve the byte-identical original next to the generated wrapper —
        // an audit trail: anyone can diff routes.original.php against the
        // source project's routes.php and see nothing was hand-edited.
        rename($configOut . 'routes.php', $configOut . 'routes.original.php');

        // Ship the default policy into the OUTPUT tree itself (not a vendor
        // lookup at runtime) so the generated app has zero dependency on the
        // framework package being present on-device beyond what it already
        // needs. A platform overrides by placing
        // src/config/android-server-policy.php in the SOURCE project (copied
        // above as part of the plain src/ copy, since it lives under
        // src/config/ like any other app config file) — the wrapper below
        // prefers that file if the copy step already placed it there.
        file_put_contents($configOut . 'android-server-default-policy.php', $this->defaultPolicySource());

        file_put_contents($configOut . 'routes.php', $this->routesWrapperSource());

        // Compute stats + the included-handler list for the safety-net check
        // by ACTUALLY APPLYING the policy here (PHP array transform, no
        // device/DB needed) — same logic the generated wrapper runs at boot,
        // executed once now so the generator can report + cross-check it.
        $policy = file_exists($this->configPath . 'android-server-policy.php')
            ? require $this->configPath . 'android-server-policy.php'
            : $this->defaultPolicy();

        $routes = require $this->configPath . 'routes.php'; // original, still present in the SOURCE tree
        $included = 0;
        $excludedPaths = [];
        $includedHandlers = [];

        foreach ($routes as $method => $methodRoutes) {
            foreach ($methodRoutes as $path => $meta) {
                if (!is_array($meta)) {
                    $meta = ['handler' => $meta]; // bare classname shorthand (legacy flat format)
                }
                if (($policy['exclude'])($path, $meta)) {
                    $excludedPaths[] = "$method $path";
                    continue;
                }
                $included++;
                $handler = $meta['handler'] ?? '';
                if (is_string($handler) && $handler !== '') {
                    $includedHandlers[] = $handler;
                }
            }
        }

        return [
            'included' => $included,
            'excluded' => count($excludedPaths),
            'excluded_paths' => $excludedPaths,
            'included_handlers' => array_values(array_unique($includedHandlers)),
        ];
    }

    private function routesWrapperSource(): string
    {
        return <<<'PHP'
<?php

/**
 * GENERATED by `php stone generate android-server` — do not hand-edit.
 * Regenerate from the source project's real routes.php on every change.
 *
 * Loads the byte-identical original (routes.original.php) and applies the
 * offline-profile policy: exclude admin/auth-provisioning routes (no
 * reachable network dependency offline). By default, every remaining
 * route's real `access` level is left AS-IS — offline auth is a real API
 * token the app shell presents on every request (see
 * docs/ANDROID-SERVER-DESIGN.md §3, rewritten 2026-08-01 after the v1
 * `access: public` mechanism was found to silently break AuthContext for
 * any route needing identity). The `make_public` policy hook still exists
 * for the rare route that genuinely wants zero-token access, but the
 * shipped default never uses it.
 */

$routes = require __DIR__ . '/routes.original.php';

$policyFile = file_exists(__DIR__ . '/android-server-policy.php')
    ? __DIR__ . '/android-server-policy.php'
    : __DIR__ . '/android-server-default-policy.php';
$policy = require $policyFile;

foreach ($routes as $method => &$methodRoutes) {
    foreach ($methodRoutes as $path => &$meta) {
        if (!is_array($meta)) {
            $meta = ['handler' => $meta];
        }
        if (($policy['exclude'])($path, $meta)) {
            unset($methodRoutes[$path]);
            continue;
        }
        if (($policy['make_public'])($path, $meta)) {
            $meta['access'] = 'public';
        }
    }
    unset($meta);
}
unset($methodRoutes);

return $routes;
PHP;
    }

    private function defaultPolicySource(): string
    {
        return <<<'PHP'
<?php

/**
 * GENERATED default offline-profile route policy.
 *
 * To override for this platform, copy this file to
 * src/config/android-server-policy.php IN THE SOURCE PROJECT (not this
 * generated tree — this file is regenerated every run) and edit as needed.
 * See docs/ANDROID-SERVER-DESIGN.md §3 for the reasoning behind each rule.
 */
return [
    // Routes matching ANY of these are dropped entirely from the generated
    // routes.php (not just left gated) — admin functionality and the
    // auth/provisioning surface (login/register/exchange proxies, tenant
    // provisioning, invitations, a tenant switcher) have no reachable
    // network dependency offline and no meaning on a single local-store
    // device.
    'exclude' => function (string $path, array $meta): bool {
        if (($meta['service'] ?? null) === 'admin') {
            return true;
        }
        $handler = $meta['handler'] ?? '';
        $handler = is_string($handler) ? $handler : '';
        $excludePatterns = [
            '/\\\\Routes\\\\Auth\\\\/',
            '/ProvisionTenant/',
            '/\\\\Routes\\\\Invitations\\\\/',
            '/GetMyTenantsRoute$/',
        ];
        foreach ($excludePatterns as $pattern) {
            if (preg_match($pattern, $handler) === 1) {
                return true;
            }
        }
        return false;
    },

    // CORRECTED 2026-08-01 (android-server manual-build v2 auth-fix — see
    // docs/ANDROID-SERVER-DESIGN.md §3, rewritten): do NOT mark surviving
    // routes `access: public`. A public route never receives jwt_claims,
    // so AuthContext never populates — proven false-positive in the v1
    // pass (routes that happen to need zero identity "worked," but any
    // route reading auth()->identity_id/tenant_id silently got null). The
    // CORRECT offline mechanism is a REAL API token (HybridApiTokenJwtHandler
    // /TrustedIssuerVerifier, validated locally via RSA, no network) that
    // the app shell mints once and attaches to every request — a
    // client-side/app-shell concern, not something this generator's
    // routes.php transform should fake by weakening the route's real
    // access requirement. Default: change nothing. A platform that
    // genuinely wants a specific route reachable with zero token (rare —
    // most "offline" routes still need identity for created_by/audit
    // fields) opts it in explicitly here.
    'make_public' => function (string $path, array $meta): bool {
        return false;
    },
];
PHP;
    }

    /**
     * Single source of truth: loads the SAME source `defaultPolicySource()`
     * ships to the device, via a temp file — guarantees the policy used here
     * (for stats/the safety-net check) can never drift from what actually
     * runs on-device.
     *
     * @return array{exclude: callable(string,array):bool, make_public: callable(string,array):bool}
     */
    private function defaultPolicy(): array
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'android_server_policy_');
        file_put_contents($tmpFile, $this->defaultPolicySource());
        $policy = require $tmpFile;
        unlink($tmpFile);
        return $policy;
    }

    // ── schema manifest (design doc §6) ─────────────────────────────────

    /**
     * @return array{main:array<string,string[]>, tenant:array<string,string[]>, vendor:array<string,string[]>, layout:string, excluded_functions:string[]}
     */
    private function generateSchemaManifest(): array
    {
        echo Color::blue("→ walking src/postgresql/ and building schema-manifest.json...\n");

        $pgRoot = $this->srcPath . 'postgresql' . DIRECTORY_SEPARATOR;
        $hasSplit = is_dir($pgRoot . 'main') && is_dir($pgRoot . 'tenant');

        $schemaPolicy = file_exists($this->configPath . 'android-server-schema-policy.php')
            ? require $this->configPath . 'android-server-schema-policy.php'
            : ['main_exclude' => [], 'tenant_exclude' => [], 'vendor_include' => []];

        $phases = ['types', 'tables', 'views', 'functions', 'seeders'];

        if ($hasSplit) {
            $main = $this->collectPhases($pgRoot . 'main' . DIRECTORY_SEPARATOR . 'postgresql' . DIRECTORY_SEPARATOR, $phases, $schemaPolicy['main_exclude'] ?? []);
            $tenant = $this->collectPhases($pgRoot . 'tenant' . DIRECTORY_SEPARATOR . 'postgresql' . DIRECTORY_SEPARATOR, $phases, $schemaPolicy['tenant_exclude'] ?? []);
            $layout = 'split (main/ + tenant/)';
        } else {
            // Flat layout: the whole src/postgresql/ tree is the single app
            // schema — treat it as "tenant" (the actual business schema; a
            // flat-layout project has no separate registry DB to distinguish).
            $main = ['types' => [], 'tables' => [], 'views' => [], 'functions' => [], 'seeders' => []];
            $tenant = $this->collectPhases($pgRoot, $phases, $schemaPolicy['tenant_exclude'] ?? []);
            $layout = 'flat (src/postgresql/ only)';
        }

        // vendor/: excluded entirely by default (framework-shared SaaS-billing/
        // analytics infra — see design doc §6.2). vendor_include lets a
        // platform opt specific files back in by basename.
        $vendorInclude = array_flip($schemaPolicy['vendor_include'] ?? []);
        $vendorRoot = $pgRoot . 'vendor' . DIRECTORY_SEPARATOR . 'postgresql' . DIRECTORY_SEPARATOR;
        $vendor = ['types' => [], 'tables' => [], 'views' => [], 'functions' => [], 'seeders' => []];
        if (is_dir($vendorRoot)) {
            foreach ($phases as $phase) {
                $dir = $vendorRoot . $phase . DIRECTORY_SEPARATOR;
                if (!is_dir($dir)) {
                    continue;
                }
                foreach (glob($dir . '*.pgsql') as $file) {
                    $base = basename($file, '.pgsql');
                    if (isset($vendorInclude[$base])) {
                        $vendor[$phase][] = $base;
                    }
                }
            }
        }

        $excludedFunctions = array_merge(
            $schemaPolicy['main_exclude'] ?? [],
            $schemaPolicy['tenant_exclude'] ?? []
        );

        $manifest = [
            'generated_at' => date('c'),
            'layout' => $layout,
            'note' => 'File list only — the retry-until-fixed-point schema-bringup ALGORITHM ' .
                'that applies these files belongs to the libphpandroid/android-server C++ host ' .
                'track, not this generator. See docs/ANDROID-SERVER-DESIGN.md §6.',
            'phase_order' => ['plpgsql-bootstrap (fixed SQL, see design doc §6.1 — not file-based)', ...$phases],
            'main' => $main,
            'tenant' => $tenant,
            'vendor' => $vendor,
        ];

        file_put_contents(
            $this->outputPath . 'schema-manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );

        return [
            'main' => $main,
            'tenant' => $tenant,
            'vendor' => $vendor,
            'layout' => $layout,
            'excluded_functions' => $excludedFunctions,
        ];
    }

    /** @return array<string,string[]> phase => [basenames without .pgsql] */
    private function collectPhases(string $root, array $phases, array $exclude): array
    {
        $excludeSet = array_flip($exclude);
        $result = [];
        foreach ($phases as $phase) {
            $dir = $root . $phase . DIRECTORY_SEPARATOR;
            $result[$phase] = [];
            if (!is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . '*.pgsql') as $file) {
                $base = basename($file, '.pgsql');
                if (isset($excludeSet[$base])) {
                    continue;
                }
                $result[$phase][] = $base;
            }
            sort($result[$phase]);
        }
        return $result;
    }

    // ── safety-net check (design doc §6.2 caveat) ───────────────────────

    private function runSafetyNetCheck(array $routeStats, array $schemaStats): void
    {
        $excludedFunctions = $schemaStats['excluded_functions'];
        if (empty($excludedFunctions)) {
            // Default policy excludes nothing beyond vendor/ — nothing to check.
            return;
        }

        echo Color::blue("→ checking remaining routes for calls into excluded schema functions...\n");
        $excludedSet = array_flip($excludedFunctions);

        foreach ($routeStats['included_handlers'] as $handlerClass) {
            $file = $this->handlerClassToFile($handlerClass);
            if ($file === null || !file_exists($file)) {
                continue;
            }
            $source = file_get_contents($file);
            if ($source === false) {
                continue;
            }
            if (preg_match_all('/Database::(?:fn|query)\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]/', $source, $matches)) {
                foreach ($matches[1] as $calledFn) {
                    if (isset($excludedSet[$calledFn])) {
                        $this->warnings[] = "Route handler {$handlerClass} calls Database::fn('{$calledFn}', ...) " .
                            "— but '{$calledFn}' is in the schema-exclude policy and will NOT exist on-device. " .
                            "Either remove it from the exclude list, or confirm this route is genuinely " .
                            "unreachable/dead code offline before shipping.";
                    }
                }
            }
        }
    }

    /** App\Routes\Foo\Bar -> <output>/src/App/Routes/Foo/Bar.php (PSR-4, App\ => src/App/) */
    private function handlerClassToFile(string $class): ?string
    {
        $class = ltrim($class, '\\');
        if (!str_starts_with($class, 'App\\')) {
            return null; // not an app-owned handler (e.g. a framework built-in route) — nothing to grep
        }
        $relative = substr($class, strlen('App\\'));
        $relative = str_replace('\\', DIRECTORY_SEPARATOR, $relative);
        return $this->outputPath . 'src' . DIRECTORY_SEPARATOR . 'App' . DIRECTORY_SEPARATOR . $relative . '.php';
    }

    // ── .env (design doc §7) ─────────────────────────────────────────────

    private function generateEnv(): void
    {
        echo Color::blue("→ generating android-server/.env (offline profile)...\n");

        // Start from the source project's real .env if present (preserves
        // whatever it already correctly has — JWT_ISSUER, key paths,
        // PLATFORM_CODE, etc.) and only override the offline-specific keys.
        $lines = [];
        $sourceEnv = $this->projectRoot . '.env';
        if (file_exists($sourceEnv)) {
            $lines = file($sourceEnv, FILE_IGNORE_NEW_LINES);
        }

        $overrides = [
            'DB_MODE' => 'pgandroid',
            // Dummy, deliberately-unreachable values. NOT used at runtime in
            // DB_MODE=pgandroid, but app-owned config can eagerly READ them
            // regardless of DB_MODE (design doc §5) — populate them rather
            // than leave them uninitialized.
            'DB_GATEWAY_URL' => 'http://127.0.0.1:1/',
            'DB_GATEWAY_PLATFORM' => 'offline-local',
            'DB_GATEWAY_ADMIN_TOKEN' => 'unused-offline-dummy-token',
            'AUTH_SERVICE_URL' => 'http://127.0.0.1:1/',
            'EXTERNAL_AUTH_CLIENT_SECRET' => 'unused-offline-dummy-secret',
        ];

        $applied = [];
        foreach ($lines as $i => $line) {
            foreach ($overrides as $key => $value) {
                if (preg_match('/^' . preg_quote($key, '/') . '=/', $line)) {
                    $lines[$i] = "{$key}={$value}";
                    $applied[$key] = true;
                }
            }
        }
        foreach ($overrides as $key => $value) {
            if (!isset($applied[$key])) {
                $lines[] = "{$key}={$value}";
            }
        }

        $header = [
            '# GENERATED by `php stone generate android-server` — offline profile.',
            '# DB_MODE=pgandroid dispatches Database::fn() through the C++ embed host\'s',
            '# androidserver_db_exec() bridge -> libpgandroid, in-process. See',
            '# docs/ANDROID-SERVER-DESIGN.md for the full reasoning.',
            '',
        ];

        file_put_contents(
            $this->outputPath . '.env',
            implode("\n", array_merge($header, $lines)) . "\n"
        );
    }

    // ── output ────────────────────────────────────────────────────────

    private function writeReadme(array $routeStats, array $schemaStats): void
    {
        $excludedList = empty($routeStats['excluded_paths'])
            ? "  (none)\n"
            : implode("\n", array_map(fn($p) => "  - $p", $routeStats['excluded_paths'])) . "\n";

        $warningsList = empty($this->warnings)
            ? "  (none)\n"
            : implode("\n", array_map(fn($w) => "  - $w", $this->warnings)) . "\n";

        $readme = <<<MD
# android-server — GENERATED, do not hand-edit

Regenerate with `php stone generate android-server`. See
`docs/ANDROID-SERVER-DESIGN.md` (in the libphpandroid repo) for the full
design reasoning this generator implements.

## What was generated
- `src/`, `public/`, `composer.json`/`composer.lock`, `keys/` — copied
  verbatim from the source project.
- `src/config/routes.original.php` — byte-identical copy of the source
  project's real `routes.php` (audit trail).
- `src/config/routes.php` — GENERATED wrapper: applies the offline route
  policy (below) on top of the original, unmodified array.
- `schema-manifest.json` — file list for the on-device schema-bringup
  driver (a separate track; this generator only produces the manifest).
- `.env` — offline profile (`DB_MODE=pgandroid` + dummy gateway/auth values).

## Route policy applied
- Included routes: {$routeStats['included']}
- Excluded routes: {$routeStats['excluded']}

Excluded:
{$excludedList}
## Schema manifest
- Layout detected: {$schemaStats['layout']}
- main:   types={$this->count($schemaStats['main']['types'] ?? [])} tables={$this->count($schemaStats['main']['tables'] ?? [])} views={$this->count($schemaStats['main']['views'] ?? [])} functions={$this->count($schemaStats['main']['functions'] ?? [])} seeders={$this->count($schemaStats['main']['seeders'] ?? [])}
- tenant: types={$this->count($schemaStats['tenant']['types'] ?? [])} tables={$this->count($schemaStats['tenant']['tables'] ?? [])} views={$this->count($schemaStats['tenant']['views'] ?? [])} functions={$this->count($schemaStats['tenant']['functions'] ?? [])} seeders={$this->count($schemaStats['tenant']['seeders'] ?? [])}
- vendor: excluded entirely by default (opt in via android-server-schema-policy.php's vendor_include)

## Warnings
{$warningsList}
MD;

        file_put_contents($this->outputPath . 'GENERATED-README.md', $readme);
    }

    private function count(array $a): int
    {
        return count($a);
    }

    private function printSummary(array $routeStats, array $schemaStats): void
    {
        echo "\n";
        echo Color::green("✔ android-server/ generated.\n");
        echo "  routes:  {$routeStats['included']} included, {$routeStats['excluded']} excluded\n";
        echo "  schema:  layout=" . $schemaStats['layout'] . "\n";
        if (!empty($this->warnings)) {
            echo "\n" . Color::yellow("⚠ " . count($this->warnings) . " warning(s):\n");
            foreach ($this->warnings as $w) {
                echo Color::yellow("  - $w\n");
            }
        }
        echo "\nSee android-server/GENERATED-README.md for the full report.\n\n";
    }

    // ── fs helpers ────────────────────────────────────────────────────

    private function copyIfExists(string $relative): void
    {
        $src = $this->projectRoot . $relative;
        if (file_exists($src)) {
            copy($src, $this->outputPath . $relative);
        }
    }

    private function copyDirRecursive(string $from, string $to): void
    {
        if (!is_dir($to)) {
            mkdir($to, 0755, true);
        }
        $items = scandir($from);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $srcPath = $from . DIRECTORY_SEPARATOR . $item;
            $dstPath = $to . DIRECTORY_SEPARATOR . $item;
            if (is_dir($srcPath)) {
                $this->copyDirRecursive($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }
    }

    private function removeDirRecursive(string $dir): void
    {
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirRecursive($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}

$generator = new AndroidServerGenerator();
$generator->run();
