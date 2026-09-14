<?php
/**
 * StoneScriptPHP CLI Helper — Vendor Schema Sync
 *
 * Core (framework-free, filesystem-only) logic for staging opt-in vendor schema
 * into a platform's src/postgresql/vendor/postgresql/ — see
 * cli/sync-vendor-schema.php (the thin CLI wrapper) for the full rationale.
 *
 * TWO package layouts are supported, and a single package may mix them:
 *
 *  1. BUCKET layout (the framework's own opt-in features — Webhooks,
 *     RequestLogging, Analytics, Subscriptions):
 *       vendor/<pkg>/src/<Feature>/Schema/{tables,functions,views,migrations,
 *                                            seeders,extensions,types}/*.pgsql
 *     Each file is copied — under its ORIGINAL basename — into the matching
 *     bucket of the target (tables→tables, functions→functions, …), merged
 *     across however many features/packages contribute one. This is the exact
 *     historical behaviour and is preserved byte-for-byte.
 *
 *  2. ORDERED-SCOPE layout (a dependency-ordered migration SET, e.g.
 *     progalaxyelabs/stonescriptphp-invoice):
 *       vendor/<pkg>/src/<Feature>/Schema/main/NNN_*.pgsql
 *     Here the Schema subdirectory is a SCOPE ("main"), not a bucket, and its
 *     files are an ordered migration stream whose correctness depends on a
 *     GLOBAL ascending filename-prefix order across every Feature (see the
 *     module's LOAD-ORDER.md — e.g. 031 redefines a 003 function and must run
 *     after 030, before 032; a tables-then-functions split cannot express
 *     this). Such files are staged into the target's `migrations/` bucket —
 *     the ONE bucket the gateway applies as an ordered, run-once stream
 *     (stonescriptdb-gateway MigrationRunner: filename order, topologically
 *     stable, tracked in _stonescriptdb_gateway_migrations) — with each file
 *     renamed `<pkg-slug>__<original>` so (a) intra-module prefix order is
 *     preserved (the slug is constant per package) and (b) files never collide
 *     with the platform's own migrations or another package's.
 *
 *     Only the `main` scope is staged here. A per-tenant scope (`tenant/`) is
 *     DEFERRED — no vendor→tenant routing exists yet — and is silently skipped.
 */

/** Recursively remove a directory tree. Safe to call on a non-existent path. */
function ssp_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        is_dir($path) ? ssp_rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

/**
 * Subdirectory names under a Feature's Schema/ that are gateway BUCKETS (a flat
 * category of files), as opposed to SCOPES (main/tenant — an ordered set).
 */
const SSP_SCHEMA_BUCKETS = ['tables', 'functions', 'views', 'migrations', 'seeders', 'extensions', 'types'];

/**
 * Schema SCOPES that carry an ordered migration set. Only `main` is staged
 * today; `tenant` is deferred (no vendor→tenant routing) and skipped by callers.
 */
const SSP_SCHEMA_SCOPES = ['main', 'tenant'];

/**
 * Turn a composer package name into a filesystem-safe, collision-free slug used
 * to namespace an ordered module's migration files inside the shared
 * `migrations/` bucket. e.g. "progalaxyelabs/stonescriptphp-invoice" ->
 * "progalaxyelabs_stonescriptphp_invoice".
 */
function ssp_package_slug(string $packageName): string
{
    return preg_replace('/[^a-z0-9]+/i', '_', strtolower($packageName));
}

/**
 * Find every directory literally named `Schema` anywhere under a package's
 * src/, at ANY depth — not just one Feature-directory deep.
 *
 * Originally this was `glob($packageSrc . '/*\/Schema')`, which only matched
 * `src/<Feature>/Schema` (exactly one directory between src/ and Schema/).
 * That silently failed to stage a nested feature such as
 * `src/Auth/BuiltinOAuth/Schema` (two directories deep) — the package would
 * report success (no error, no warning) while simply never staging the file,
 * discovered when `progalaxyelabs/stonescriptphp` 9.16.0 shipped
 * `ssp_oauth_resolve_profile.pgsql` under exactly that nested path and it
 * never appeared in any consumer's `src/postgresql/vendor/`. Recursing fixes
 * every existing one-level-deep package (Webhooks, RequestLogging, Analytics,
 * Subscriptions — still found, since a match at depth 1 is still a match) as
 * a strict superset, so this is additive, not behavior-changing for them.
 *
 * Does not recurse INTO a matched Schema/ directory — a Schema dir's own
 * children are buckets/scopes (tables, functions, main, ...), never another
 * nested Schema dir, so stopping there avoids any pathological rescanning.
 *
 * @return string[] Absolute paths to every `Schema` directory found.
 */
function ssp_find_schema_dirs(string $packageSrc): array
{
    if (!is_dir($packageSrc)) {
        return [];
    }

    $out = [];
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($packageSrc, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($rii as $item) {
        if (!$item->isDir()) {
            continue;
        }
        if ($item->getFilename() === 'Schema') {
            $out[] = $item->getPathname();
        }
    }

    // RecursiveDirectoryIterator has no built-in "don't descend into this
    // dir" hook mid-iteration without a custom filter class, so instead
    // filter out any path that has an ANCESTOR match already collected
    // (defends against a pathological "Schema/Schema" nesting; harmless
    // no-op for every real package today).
    return array_values(array_filter($out, function (string $path) use ($out) {
        foreach ($out as $other) {
            if ($other !== $path && str_starts_with($path, $other . '/')) {
                return false;
            }
        }
        return true;
    }));
}

/**
 * Stage every Schema/ folder found under one package's src/ into $targetBase,
 * handling BOTH the bucket and ordered-scope layouts (see file header).
 *
 * Does NOT wipe $targetBase — the caller is responsible for regenerating it
 * once before staging any package, so files merge across packages.
 *
 * @param string $packageSrc  Absolute path to a package's src/ directory.
 * @param string $targetBase  Absolute path to (platform)/src/postgresql/vendor/postgresql.
 * @param string $packageName Composer package name (for slugging ordered modules).
 * @param int    $copied      (in/out) running count of files staged.
 * @param array  $features    (in/out) set of "<pkg>/<Feature>" that contributed a file.
 */
function ssp_stage_package_schema(string $packageSrc, string $targetBase, string $packageName, int &$copied, array &$features): void
{
    if (!is_dir($packageSrc)) {
        return;
    }

    $slug = ssp_package_slug($packageName);
    $schemaDirs = ssp_find_schema_dirs($packageSrc);

    foreach ($schemaDirs as $schemaDir) {
        $featureName = basename(dirname($schemaDir));
        $featureKey = $packageName . '/' . $featureName;
        $subdirs = glob($schemaDir . '/*', GLOB_ONLYDIR) ?: [];

        foreach ($subdirs as $subdir) {
            $subdirName = basename($subdir);

            if (in_array($subdirName, SSP_SCHEMA_SCOPES, true)) {
                // ORDERED-SCOPE layout. Only `main` is staged; `tenant` is deferred.
                if ($subdirName !== 'main') {
                    continue;
                }
                // Files may be nested one Feature deep; the gateway MigrationRunner
                // reads a FLAT migrations/ directory, so flatten by basename (the
                // module's LOAD-ORDER guarantees globally-unique 3-digit prefixes).
                $files = ssp_collect_schema_files($subdir);
                $destDir = $targetBase . '/migrations';
                foreach ($files as $file) {
                    if (!is_dir($destDir)) {
                        mkdir($destDir, 0755, true);
                    }
                    // Namespace with the package slug: preserves intra-module
                    // prefix order (constant prefix) and prevents cross-package /
                    // platform-own collisions in the shared migrations/ bucket.
                    $destName = $slug . '__' . basename($file);
                    copy($file, $destDir . '/' . $destName);
                    $copied++;
                    $features[$featureKey] = true;
                }
                continue;
            }

            // BUCKET layout (historical behaviour — unchanged): copy under the
            // original basename into the matching bucket.
            if (in_array($subdirName, SSP_SCHEMA_BUCKETS, true)) {
                $destDir = $targetBase . '/' . $subdirName;
                $files = glob($subdir . '/*.{sql,pgsql,pssql}', GLOB_BRACE) ?: [];
                foreach ($files as $file) {
                    if (!is_dir($destDir)) {
                        mkdir($destDir, 0755, true);
                    }
                    copy($file, $destDir . '/' . basename($file));
                    $copied++;
                    $features[$featureKey] = true;
                }
                continue;
            }

            // Unknown subdirectory name — ignore (defensive; keeps the stager
            // forward-compatible with layouts it does not yet understand).
        }
    }
}

/**
 * Recursively collect *.sql / *.pgsql / *.pssql files under a directory
 * (used for the ordered-scope layout, whose files may sit directly under
 * Schema/main/ or one Feature-directory deep).
 *
 * @return string[] Absolute file paths (unsorted; caller/gateway orders by name).
 */
function ssp_collect_schema_files(string $dir): array
{
    $out = [];
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($rii as $f) {
        if ($f->isFile()) {
            $ext = strtolower($f->getExtension());
            if ($ext === 'sql' || $ext === 'pgsql' || $ext === 'pssql') {
                $out[] = $f->getPathname();
            }
        }
    }
    return $out;
}

/**
 * BACKWARD-COMPATIBLE single-package entry point (framework-only).
 *
 * Regenerates $targetBase and stages just the framework package's Schema tree.
 * Retained for any caller that still passes an explicit framework src path; the
 * CLI wrapper now prefers syncAllVendorSchema() to also pick up installed
 * consumer modules (e.g. stonescriptphp-invoice).
 *
 * @param string $vendorFrameworkSrc  Absolute path to vendor/progalaxyelabs/stonescriptphp/src
 * @param string $targetBase          Absolute path to (platform)/src/postgresql/vendor/postgresql
 * @return array{copied: int, features: string[]}
 */
function syncVendorSchema(string $vendorFrameworkSrc, string $targetBase): array
{
    if (!is_dir($vendorFrameworkSrc)) {
        return ['copied' => 0, 'features' => []];
    }

    ssp_rrmdir($targetBase);

    $copied = 0;
    $features = [];
    ssp_stage_package_schema($vendorFrameworkSrc, $targetBase, 'progalaxyelabs/stonescriptphp', $copied, $features);

    return ['copied' => $copied, 'features' => array_keys($features)];
}

/**
 * Stage vendor schema from ALL installed progalaxyelabs/* packages.
 *
 * Reads vendor/composer/installed.json to discover every installed
 * progalaxyelabs/* package (the framework AND consumer modules such as
 * stonescriptphp-invoice), resolves each package's src/ directory, and stages
 * its Schema tree (bucket + ordered-scope layouts) into $targetBase — which is
 * regenerated ONCE up front so it never accumulates files from a package/version
 * no longer installed.
 *
 * The framework is always staged (even if installed.json can't be read), so the
 * historical framework-only behaviour is a strict subset of this.
 *
 * @param string $projectRoot Absolute path to the consuming project root (getcwd()).
 * @param string $targetBase  Absolute path to (project)/src/postgresql/vendor/postgresql.
 * @return array{copied: int, features: string[], packages: string[]}
 */
function syncAllVendorSchema(string $projectRoot, string $targetBase): array
{
    ssp_rrmdir($targetBase);

    $copied = 0;
    $features = [];
    $packagesWithFiles = [];

    // Resolve the list of (packageName => srcDir) to scan. Always include the
    // framework so behaviour is never worse than the legacy single-package path.
    $packageSrcDirs = ssp_discover_vendor_package_srcs($projectRoot);

    foreach ($packageSrcDirs as $packageName => $srcDir) {
        $before = $copied;
        ssp_stage_package_schema($srcDir, $targetBase, $packageName, $copied, $features);
        if ($copied > $before) {
            $packagesWithFiles[$packageName] = true;
        }
    }

    return [
        'copied' => $copied,
        'features' => array_keys($features),
        'packages' => array_keys($packagesWithFiles),
    ];
}

/**
 * Discover the src/ directory of every installed progalaxyelabs/* package.
 *
 * Reads vendor/composer/installed.json (composer v2 shape:
 * {"packages":[{name, install-path}, ...]}). install-path is relative to
 * vendor/composer/. The framework is always included as a fallback even if the
 * manifest is missing or unreadable.
 *
 * @return array<string,string>  packageName => absolute src/ path (only existing dirs).
 */
function ssp_discover_vendor_package_srcs(string $projectRoot): array
{
    $result = [];

    // Fallback: the framework itself, at its conventional install path.
    $frameworkSrc = $projectRoot . '/vendor/progalaxyelabs/stonescriptphp/src';
    if (is_dir($frameworkSrc)) {
        $result['progalaxyelabs/stonescriptphp'] = $frameworkSrc;
    }

    $installedJson = $projectRoot . '/vendor/composer/installed.json';
    if (is_file($installedJson)) {
        $data = json_decode((string) file_get_contents($installedJson), true);
        $packages = $data['packages'] ?? (is_array($data) ? $data : []);
        $composerDir = $projectRoot . '/vendor/composer';

        foreach ($packages as $pkg) {
            $name = $pkg['name'] ?? null;
            if (!is_string($name) || strpos($name, 'progalaxyelabs/') !== 0) {
                continue;
            }
            $installPath = $pkg['install-path'] ?? null;
            if (!is_string($installPath) || $installPath === '') {
                // No explicit path — fall back to the conventional vendor location.
                $installPath = '../' . $name;
            }
            // install-path is relative to vendor/composer/.
            $absInstall = ssp_realish_path($composerDir . '/' . $installPath);
            $src = $absInstall . '/src';
            if (is_dir($src)) {
                $result[$name] = $src;
            }
        }
    }

    return $result;
}

/**
 * Normalize a path containing ../ segments without requiring the target to be
 * resolvable by realpath at every step (works for not-yet-created dirs too).
 */
function ssp_realish_path(string $path): string
{
    $real = realpath($path);
    if ($real !== false) {
        return $real;
    }
    // Manual normalization fallback.
    $parts = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '' || $seg === '.') {
            continue;
        }
        if ($seg === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $seg;
    }
    return '/' . implode('/', $parts);
}
