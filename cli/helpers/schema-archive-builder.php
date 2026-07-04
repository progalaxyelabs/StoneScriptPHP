<?php
/**
 * StoneScriptPHP CLI Helper — Schema Archive Builder
 *
 * Builds gateway-compatible tar.gz archives from the postgresql/ directory.
 * Supports the nested directory layout:
 *
 *   src/postgresql/{tenant,main,vendor}/postgresql/{tables,functions,views}/
 *   + src/postgresql/{functions,views,...}/ (shared, deployed to all databases)
 *
 * 'vendor' is a build artifact staged by cli/sync-vendor-schema.php (opt-in
 * framework schema like RequestLogging) — never hand-edited, only ever
 * consumed by the separate, non-automatic `gateway:migrate-vendor-main`.
 *
 * The StoneScriptDB gateway expects a flat `postgresql/` structure in the archive.
 * This builder merges the target scope (tenant, main, or vendor) with shared
 * top-level files into a single flat structure.
 */

/**
 * Validate the schema layout is nested.
 *
 * @param string $postgresqlPath  Path to src/postgresql/ directory
 * @return bool
 */
function validateSchemaLayout(string $postgresqlPath): bool
{
    return is_dir($postgresqlPath . '/tenant') || is_dir($postgresqlPath . '/main');
}

/**
 * Count schema files in a directory across all supported extensions.
 *
 * @param string $dir     Base directory
 * @param string $subdir  Subdirectory name (e.g., 'functions', 'tables')
 * @return int
 */
function countSchemaFiles(string $dir, string $subdir): int
{
    $path = $dir . '/' . $subdir;
    if (!is_dir($path)) {
        return 0;
    }

    $count = 0;
    foreach (['*.sql', '*.pgsql', '*.pssql'] as $pattern) {
        $count += count(glob($path . '/' . $pattern));
    }
    return $count;
}

/**
 * Recursively add files from a source directory to a PharData archive
 * with remapped paths under a given archive prefix.
 *
 * @param PharData $phar           Archive to add to
 * @param string   $sourceDir      Absolute path to source directory
 * @param string   $archivePrefix  Path prefix inside archive (e.g., 'postgresql/functions')
 * @return int Number of files added
 */
function addFilesToArchive(PharData $phar, string $sourceDir, string $archivePrefix): int
{
    if (!is_dir($sourceDir)) {
        return 0;
    }

    $count = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $relativePath = substr($file->getPathname(), strlen($sourceDir) + 1);
            // Normalize directory separators
            $relativePath = str_replace('\\', '/', $relativePath);
            $archivePath = $archivePrefix . '/' . $relativePath;
            $phar->addFile($file->getPathname(), $archivePath);
            $count++;
        }
    }

    return $count;
}

/**
 * Build a gateway-compatible schema archive (tar.gz).
 *
 * Requires nested layout: src/postgresql/{main,tenant}/postgresql/
 *
 * @param string   $postgresqlPath  Path to src/postgresql/ directory
 * @param string   $tarGzFile       Output .tar.gz file path
 * @param string   $target          'tenant' or 'main'
 * @param bool     $quiet           Suppress output
 * @param string[] $mergeTargets    Additional {target}/postgresql/ dirs to merge into the SAME
 *                                  archive on top of $target (e.g. ['vendor'] — see
 *                                  gateway-migrate-vendor-main.php). The gateway resolves the
 *                                  destination DATABASE from schema_name alone
 *                                  ({platform}_{schema_name} — stonescriptdb-gateway
 *                                  src/pool/router.rs::database_name()), so staged vendor schema
 *                                  MUST be merged into the same archive as 'main' to land in the
 *                                  same database as the platform's own schema — a separate
 *                                  schema_name would target a wholly different, nonexistent
 *                                  database. Empty by default — no behavior change for existing
 *                                  callers (register-main, migrate-main, tenant flows).
 * @return array{layout: string, total_files: int, tables: int, functions: int, views: int, migrations: int}
 * @throws RuntimeException If schema directory not found or archive creation fails
 */
function buildSchemaArchive(string $postgresqlPath, string $tarGzFile, string $target, bool $quiet = false, array $mergeTargets = []): array
{
    if (!validateSchemaLayout($postgresqlPath)) {
        throw new RuntimeException(
            "Invalid schema layout. Expected nested structure:\n" .
            "  src/postgresql/main/postgresql/{tables,functions,...}\n" .
            "  src/postgresql/tenant/postgresql/{tables,functions,...}\n" .
            "Found: {$postgresqlPath}"
        );
    }

    $stats = [
        'layout' => 'nested',
        'total_files' => 0,
        'tables' => 0,
        'functions' => 0,
        'views' => 0,
        'migrations' => 0,
    ];

    // Remove existing files
    $tarPath = preg_replace('/\.gz$/', '', $tarGzFile);
    if (file_exists($tarGzFile)) unlink($tarGzFile);
    if (file_exists($tarPath)) unlink($tarPath);

    $phar = new PharData($tarPath);

    $primaryDir = $postgresqlPath . '/' . $target . '/postgresql';

    if (!is_dir($primaryDir)) {
        $available = array_filter(
            ['main', 'tenant', 'vendor'],
            fn($t) => is_dir($postgresqlPath . '/' . $t)
        );
        throw new RuntimeException(
            "Schema directory not found for target '{$target}': {$primaryDir}\n" .
            "Available targets: " . implode(', ', $available)
        );
    }

    if (!$quiet) {
        echo "  Layout: nested ({$target}/postgresql/)\n";
    }

    // Add primary schema files: {target}/postgresql/* -> postgresql/*
    $added = addFilesToArchive($phar, $primaryDir, 'postgresql');
    $stats['total_files'] += $added;

    if (!$quiet) {
        echo "  Primary ({$target}): {$added} files\n";
    }

    // Merge shared top-level schema files: postgresql/{subdir}/* -> postgresql/{subdir}/*
    foreach (['functions', 'tables', 'views', 'migrations', 'seeders', 'extensions', 'types'] as $subdir) {
        $sharedDir = $postgresqlPath . '/' . $subdir;
        if (is_dir($sharedDir)) {
            $sharedAdded = addFilesToArchive($phar, $sharedDir, 'postgresql/' . $subdir);
            $stats['total_files'] += $sharedAdded;
            if (!$quiet && $sharedAdded > 0) {
                echo "  Shared {$subdir}: {$sharedAdded} files\n";
            }
        }
    }

    // Merge additional targets on top (e.g. ['vendor']) into this SAME archive/database.
    $mergeDirs = [$primaryDir];
    foreach ($mergeTargets as $mergeTarget) {
        $mergeDir = $postgresqlPath . '/' . $mergeTarget . '/postgresql';
        if (!is_dir($mergeDir)) {
            continue; // nothing staged for this target — not an error, just nothing to add
        }
        $mergeAdded = addFilesToArchive($phar, $mergeDir, 'postgresql');
        $stats['total_files'] += $mergeAdded;
        if (!$quiet) {
            echo "  Merged ({$mergeTarget}): {$mergeAdded} files\n";
        }
        $mergeDirs[] = $mergeDir;
    }

    // Count per type (primary + shared + any merged targets)
    foreach (['tables', 'functions', 'views', 'migrations'] as $type) {
        $stats[$type] = countSchemaFiles($postgresqlPath, $type);
        foreach ($mergeDirs as $dir) {
            $stats[$type] += countSchemaFiles($dir, $type);
        }
    }

    // Compress to gzip
    $phar->compress(Phar::GZ);

    // Remove intermediate .tar file
    if (file_exists($tarPath)) {
        unlink($tarPath);
    }

    return $stats;
}
