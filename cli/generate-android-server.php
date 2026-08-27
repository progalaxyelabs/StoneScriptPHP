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
 * SHIPPED SURFACE REDUCTION (added 2026-08-27, both opt-in and fully
 * backward-compatible — a project with neither addition generates exactly
 * as before):
 *
 *   1. Trimmed route table: if the source project ships
 *      `src/config/routes-android-server.php` (same array shape as
 *      routes.php — a hand-maintained subset of only the routes the
 *      offline app actually needs), the generator uses THAT as the route
 *      table instead of the full `routes.php`. Absent that file, behavior
 *      is unchanged: the full routes.php + the admin/auth exclusion policy
 *      below. Either way the effective table still goes through
 *      `routes.original.php` as the audit trail, and the admin/auth
 *      exclusion policy still runs on top as a second, defense-in-depth
 *      safety net.
 *   2. Handler pruning: previously `copyDirRecursive()` shipped the ENTIRE
 *      `src/App/Routes/` tree regardless of the effective route table —
 *      every admin/subscription/invitations/auth handler file, reachable
 *      or not, went into the artifact byte-for-byte. Now, after the
 *      effective route table is known, `pruneUnusedRouteHandlers()` deletes
 *      any `App\Routes\*` handler `.php` file that is NOT the target of a
 *      kept route, with a conservative textual cross-reference check
 *      against every KEPT handler's source before deleting anything (route
 *      handlers are leaves by convention — nothing else `use`s them —
 *      but this is a cheap belt-and-suspenders check against that
 *      assumption being wrong for some platform). Only `App\Routes\*` is
 *      touched; DTOs/Lib/Database wrappers/framework code are never
 *      pruned. If any kept handler can't be resolved to a file (e.g. it
 *      isn't a fully-qualified `App\Routes\...` class string), pruning is
 *      skipped entirely for that run and a warning is emitted — a
 *      smaller-but-correct artifact beats a broken one, so the fallback is
 *      "keep everything," never "guess."
 *
 * Full reasoning + evidence this was built from:
 *   divisions/opensource/libphpandroid/docs/ANDROID-SERVER-DESIGN.md
 *
 * This file intentionally contains NO platform-specific names/schema — the
 * policy defaults are generic across any StoneScriptPHP project using the
 * conventional src/config/routes.php + src/postgresql/ layout. Platforms
 * override policy via src/config/android-server-policy.php,
 * src/config/android-server-schema-policy.php, and (new) an optional
 * src/config/routes-android-server.php trimmed route table (all optional).
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
        $pruneStats = $this->pruneUnusedRouteHandlers($routeStats);
        $schemaStats = $this->generateSchemaManifest();
        $this->runSafetyNetCheck($routeStats, $schemaStats);
        $this->generateEnv();
        $this->writeReadme($routeStats, $schemaStats, $pruneStats);

        $this->printSummary($routeStats, $schemaStats, $pruneStats);
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
     * @return array{included:int, excluded:int, excluded_paths:string[], included_handlers:string[], route_source:string}
     */
    private function transformRoutes(): array
    {
        // Opt-in trimmed route table: a source project may ship
        // src/config/routes-android-server.php — a hand-maintained subset
        // of only the routes the offline app needs, same array shape as
        // routes.php. If present, it becomes the effective route table
        // instead of the full routes.php. Absent, behavior is byte-for-byte
        // what it was before this capability existed.
        $trimmedSourceFile = $this->configPath . 'routes-android-server.php';
        $usingTrimmedFile = file_exists($trimmedSourceFile);
        $routeSourceLabel = $usingTrimmedFile
            ? 'trimmed (src/config/routes-android-server.php)'
            : 'full (src/config/routes.php + admin/auth exclusion policy)';

        echo Color::blue($usingTrimmedFile
            ? "→ using trimmed route table (src/config/routes-android-server.php)...\n"
            : "→ transforming routes.php (exclude admin/auth-provisioning routes only)...\n");

        $configOut = $this->outputPath . 'src' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR;

        // Preserve the byte-identical EFFECTIVE source next to the generated
        // wrapper — an audit trail: anyone can diff routes.original.php
        // against the source project's routes.php (or, when trimmed, its
        // routes-android-server.php) and see nothing was hand-edited.
        if ($usingTrimmedFile) {
            // The trimmed file was already copied byte-for-byte into the
            // output tree by the plain src/ copy (it lives under
            // src/config/ like any other app config file). Promote it to be
            // the audit-trail "original" and DO NOT ship the full,
            // untrimmed routes.php alongside it — shipping the full route
            // table (even just as inert data) would still leak every
            // excluded route's path + handler class name, defeating the
            // point of trimming.
            if (file_exists($configOut . 'routes.php')) {
                unlink($configOut . 'routes.php');
            }
            rename($configOut . 'routes-android-server.php', $configOut . 'routes.original.php');
        } else {
            rename($configOut . 'routes.php', $configOut . 'routes.original.php');
        }

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
        // (and now, handler pruning) by ACTUALLY APPLYING the policy here
        // (PHP array transform, no device/DB needed) — same logic the
        // generated wrapper runs at boot, executed once now so the
        // generator can report + cross-check it. Applying the exclusion
        // policy on top of the trimmed table too (not just the full one) is
        // deliberate defense-in-depth: if a hand-maintained trimmed file
        // ever accidentally includes an admin/auth route, it still gets
        // dropped here rather than silently shipping.
        $policy = file_exists($this->configPath . 'android-server-policy.php')
            ? require $this->configPath . 'android-server-policy.php'
            : $this->defaultPolicy();

        // Require from the SOURCE project (untouched throughout), not the
        // output tree — same file the wrapper resolved to above.
        $routes = require ($usingTrimmedFile ? $trimmedSourceFile : $this->configPath . 'routes.php');
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
            'route_source' => $routeSourceLabel,
        ];
    }

    // ── handler pruning (shipped-surface reduction) ─────────────────────

    /**
     * Delete App\Routes\* handler .php files from the OUTPUT tree that are
     * not the target of any route in the effective (already-policy-applied)
     * route table computed by transformRoutes(). Only src/App/Routes/ is
     * ever touched — DTOs, Lib, Database wrappers, and all framework code
     * are left exactly as copyDirRecursive() placed them.
     *
     * Conservative by design: pruning is skipped ENTIRELY (falling back to
     * "ship everything," i.e. today's behavior) if any kept handler can't
     * be resolved to a concrete file under src/App/Routes/ — a
     * smaller-but-correct artifact beats a broken one, so when in doubt we
     * do nothing rather than guess. Files that survive the "not targeted by
     * a kept route" check are still kept if a KEPT handler's own source
     * textually references their class basename — a cheap belt-and-suspenders
     * safety net against the (normally true) assumption that route handlers
     * are leaves nothing else `use`s.
     *
     * @return array{pruned:int, kept:int, skipped:bool, skipped_reason:?string, skipped_as_referenced:string[]}
     */
    private function pruneUnusedRouteHandlers(array $routeStats): array
    {
        $routesDir = $this->outputPath . 'src' . DIRECTORY_SEPARATOR . 'App' . DIRECTORY_SEPARATOR . 'Routes';
        if (!is_dir($routesDir)) {
            // Nothing to prune (project has no App\Routes\ tree at all, or
            // uses a non-standard layout) — leave it alone.
            return ['pruned' => 0, 'kept' => 0, 'skipped' => true, 'skipped_reason' => 'no src/App/Routes directory', 'skipped_as_referenced' => []];
        }

        echo Color::blue("→ pruning unused App\\Routes\\* handler files...\n");

        // Only classes fully-qualified under App\Routes\ are candidates for
        // pruning at all — this matches the router's own contract (handler
        // strings are always fully-qualified class names, see
        // Routing/Router.php). Any handler string that does NOT start with
        // "App\Routes\" is either a legacy bare-classname shorthand (can't
        // be reliably resolved to a file — ambiguous) or intentionally
        // lives outside App\Routes\ — either way we can't safely prune
        // relative to it, so its presence forces a whole-run skip.
        $unresolvable = [];
        $keepClasses = [];
        foreach ($routeStats['included_handlers'] as $handler) {
            $normalized = ltrim($handler, '\\');
            if (str_starts_with($normalized, 'App\\Routes\\')) {
                $keepClasses[] = $normalized;
            } elseif (str_starts_with($normalized, 'App\\')) {
                // A resolvable App\-namespaced class, just not under
                // App\Routes\ — not a pruning candidate, no ambiguity.
                continue;
            } else {
                $unresolvable[] = $handler;
            }
        }

        if (!empty($unresolvable)) {
            $this->warnings[] = 'Handler pruning skipped entirely: ' . count($unresolvable) .
                ' route handler(s) in the effective route table are not fully-qualified App\\Routes\\* ' .
                'class strings (e.g. legacy bare classname shorthand), so this generator cannot ' .
                'reliably tell which src/App/Routes/ files are actually referenced. Shipping the full, ' .
                'unpruned src/App/Routes/ tree instead. Affected handler(s): ' . implode(', ', $unresolvable);
            return ['pruned' => 0, 'kept' => $this->countPhpFilesRecursive($routesDir), 'skipped' => true, 'skipped_reason' => 'unresolvable handler class string(s)', 'skipped_as_referenced' => []];
        }

        if (empty($keepClasses)) {
            $this->warnings[] = 'Handler pruning skipped: the effective route table resolved zero ' .
                'App\\Routes\\* handlers — refusing to prune src/App/Routes/ to avoid shipping a broken ' .
                'app. Verify routes-android-server.php / routes.php handler entries if this is unexpected.';
            return ['pruned' => 0, 'kept' => $this->countPhpFilesRecursive($routesDir), 'skipped' => true, 'skipped_reason' => 'no kept App\\Routes\\* handlers resolved', 'skipped_as_referenced' => []];
        }

        $keepFiles = []; // realpath => true
        foreach (array_unique($keepClasses) as $class) {
            $file = $this->handlerClassToFile($class);
            if ($file !== null && file_exists($file)) {
                $real = realpath($file);
                if ($real !== false) {
                    $keepFiles[$real] = true;
                    continue;
                }
            }
            // A kept route points at a handler class whose file doesn't
            // exist in the output tree at all — that's a pre-existing
            // problem with the route table (would 404/fatal at runtime
            // regardless of pruning), not something pruning should paper
            // over. Warn and skip pruning entirely to avoid compounding it.
            $this->warnings[] = "Handler pruning skipped entirely: kept route handler '{$class}' does not " .
                "resolve to an existing file under src/App/Routes/ — this route would fail at runtime " .
                "regardless of pruning. Fix the route table's handler entry, then regenerate.";
            return ['pruned' => 0, 'kept' => $this->countPhpFilesRecursive($routesDir), 'skipped' => true, 'skipped_reason' => 'kept handler class missing its file', 'skipped_as_referenced' => []];
        }

        $allFiles = $this->listPhpFilesRecursive($routesDir);

        // Concatenate every KEPT handler's source once, for the cheap
        // cross-reference safety check below.
        $keptSource = '';
        foreach (array_keys($keepFiles) as $f) {
            $c = file_get_contents($f);
            if ($c !== false) {
                $keptSource .= $c . "\n";
            }
        }

        $pruned = 0;
        $kept = 0;
        $skippedAsReferenced = [];
        foreach ($allFiles as $file) {
            $real = realpath($file);
            if ($real !== false && isset($keepFiles[$real])) {
                $kept++;
                continue;
            }
            $basename = basename($file, '.php');
            if ($basename !== '' && preg_match('/\b' . preg_quote($basename, '/') . '\b/', $keptSource) === 1) {
                // A kept handler's source still mentions this class's
                // basename (e.g. a shared trait/helper co-located under
                // Routes/, or one handler composing another) — keep it
                // rather than risk breaking a kept route.
                $skippedAsReferenced[] = $this->relativePath($routesDir, $file);
                $kept++;
                continue;
            }
            unlink($file);
            $pruned++;
        }

        $this->removeEmptyDirsRecursive($routesDir);

        return [
            'pruned' => $pruned,
            'kept' => $kept,
            'skipped' => false,
            'skipped_reason' => null,
            'skipped_as_referenced' => $skippedAsReferenced,
        ];
    }

    /** @return string[] absolute paths of every .php file under $dir, recursively */
    private function listPhpFilesRecursive(string $dir): array
    {
        $result = [];
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $result = array_merge($result, $this->listPhpFilesRecursive($path));
            } elseif (str_ends_with($item, '.php')) {
                $result[] = $path;
            }
        }
        return $result;
    }

    private function countPhpFilesRecursive(string $dir): int
    {
        return count($this->listPhpFilesRecursive($dir));
    }

    /** Remove now-empty directories left behind by pruning, deepest first. */
    private function removeEmptyDirsRecursive(string $dir): bool
    {
        $items = scandir($dir);
        $isEmpty = true;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                if ($this->removeEmptyDirsRecursive($path)) {
                    // subdir removed
                } else {
                    $isEmpty = false;
                }
            } else {
                $isEmpty = false;
            }
        }
        if ($isEmpty) {
            rmdir($dir);
            return true;
        }
        return false;
    }

    private function relativePath(string $base, string $path): string
    {
        $base = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (str_starts_with($path, $base)) {
            return substr($path, strlen($base));
        }
        return $path;
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

    private function writeReadme(array $routeStats, array $schemaStats, array $pruneStats): void
    {
        $excludedList = empty($routeStats['excluded_paths'])
            ? "  (none)\n"
            : implode("\n", array_map(fn($p) => "  - $p", $routeStats['excluded_paths'])) . "\n";

        $warningsList = empty($this->warnings)
            ? "  (none)\n"
            : implode("\n", array_map(fn($w) => "  - $w", $this->warnings)) . "\n";

        $pruneSection = $pruneStats['skipped']
            ? "- pruning SKIPPED — the full src/App/Routes/ tree was shipped unpruned.\n" .
              "  Reason: {$pruneStats['skipped_reason']}.\n"
            : "- Handler files kept:   {$pruneStats['kept']}\n" .
              "- Handler files pruned: {$pruneStats['pruned']}\n" .
              (empty($pruneStats['skipped_as_referenced'])
                  ? ''
                  : "- Kept despite being otherwise unused, because a kept handler's source still " .
                    "references their class name:\n" .
                    implode("\n", array_map(fn($p) => "    - $p", $pruneStats['skipped_as_referenced'])) . "\n");

        $readme = <<<MD
# android-server — GENERATED, do not hand-edit

Regenerate with `php stone generate android-server`. See
`docs/ANDROID-SERVER-DESIGN.md` (in the libphpandroid repo) for the full
design reasoning this generator implements.

## What was generated
- `src/`, `public/`, `composer.json`/`composer.lock`, `keys/` — copied
  verbatim from the source project, EXCEPT `src/App/Routes/` which is
  pruned to only the handlers the effective route table actually uses
  (see "Handler pruning" below).
- `src/config/routes.original.php` — byte-identical copy of the EFFECTIVE
  route source ({$routeStats['route_source']}) — audit trail.
- `src/config/routes.php` — GENERATED wrapper: applies the offline route
  policy (below) on top of the original, unmodified array.
- `schema-manifest.json` — file list for the on-device schema-bringup
  driver (a separate track; this generator only produces the manifest).
- `.env` — offline profile (`DB_MODE=pgandroid` + dummy gateway/auth values).

## Route source
- {$routeStats['route_source']}

## Route policy applied
- Included routes: {$routeStats['included']}
- Excluded routes: {$routeStats['excluded']}

Excluded:
{$excludedList}
## Handler pruning
{$pruneSection}
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

    private function printSummary(array $routeStats, array $schemaStats, array $pruneStats): void
    {
        echo "\n";
        echo Color::green("✔ android-server/ generated.\n");
        echo "  route source: {$routeStats['route_source']}\n";
        echo "  routes:  {$routeStats['included']} included, {$routeStats['excluded']} excluded\n";
        if ($pruneStats['skipped']) {
            echo "  handlers: pruning SKIPPED ({$pruneStats['skipped_reason']}) — full src/App/Routes/ shipped\n";
        } else {
            echo "  handlers: {$pruneStats['kept']} kept, {$pruneStats['pruned']} pruned\n";
        }
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
