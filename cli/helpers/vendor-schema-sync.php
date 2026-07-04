<?php
/**
 * StoneScriptPHP CLI Helper — Vendor Schema Sync
 *
 * Core (framework-free, filesystem-only) logic for staging opt-in framework
 * schema (e.g. RequestLogging) from vendor/progalaxyelabs/stonescriptphp/src/
 * into a platform's src/postgresql/vendor/postgresql/ — see
 * cli/sync-vendor-schema.php (the thin CLI wrapper that calls this with real
 * paths) for the full rationale.
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
 * Stage every vendor-provided Schema/ folder found under $vendorFrameworkSrc
 * (expected shape: {$vendorFrameworkSrc}/*\/Schema/{tables,functions,...}/*.pgsql)
 * into $targetBase/{tables,functions,...}/, merging by subdirectory across
 * however many feature modules contribute one.
 *
 * $targetBase is fully regenerated (removed then rebuilt) on every call —
 * never accumulates files from a feature no longer present in the currently
 * installed framework version.
 *
 * @param string $vendorFrameworkSrc  Absolute path to vendor/progalaxyelabs/stonescriptphp/src
 * @param string $targetBase          Absolute path to (platform)/src/postgresql/vendor/postgresql
 * @return array{copied: int, features: string[]}  Count of files staged + which feature dirs contributed any
 */
function syncVendorSchema(string $vendorFrameworkSrc, string $targetBase): array
{
    if (!is_dir($vendorFrameworkSrc)) {
        return ['copied' => 0, 'features' => []];
    }

    ssp_rrmdir($targetBase);

    $copied = 0;
    $featuresWithFiles = [];
    $schemaDirs = glob($vendorFrameworkSrc . '/*/Schema', GLOB_ONLYDIR) ?: [];

    foreach ($schemaDirs as $schemaDir) {
        $featureName = basename(dirname($schemaDir));
        $subdirs = glob($schemaDir . '/*', GLOB_ONLYDIR) ?: [];

        foreach ($subdirs as $subdir) {
            // e.g. 'tables', 'functions', 'views', 'migrations', 'extensions', 'types'
            $subdirName = basename($subdir);
            $destDir = $targetBase . '/' . $subdirName;
            $files = glob($subdir . '/*.{sql,pgsql,pssql}', GLOB_BRACE) ?: [];

            foreach ($files as $file) {
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0755, true);
                }
                copy($file, $destDir . '/' . basename($file));
                $copied++;
                $featuresWithFiles[$featureName] = true;
            }
        }
    }

    return ['copied' => $copied, 'features' => array_keys($featuresWithFiles)];
}
