<?php
/**
 * StoneScriptPHP CLI - Schema Export
 *
 * Creates a tar.gz archive of the postgresql folder for db-gateway registration.
 *
 * Usage:
 *   php stone schema:export [options]
 *
 * Options:
 *   --output=<path>   Output file path (default: .cache/postgresql_<timestamp>.tar.gz)
 *   --quiet           Suppress output
 *   --main            Export main DB schema instead of tenant schema (nested layouts only)
 *
 * Example:
 *   php stone schema:export
 *   php stone schema:export --output=/tmp/schema.tar.gz
 *   php stone schema:export --main
 */

require_once __DIR__ . '/helpers/schema-archive-builder.php';

// Configuration
$postgresqlPath = ROOT_PATH . '/src/postgresql';
$cacheDir = ROOT_PATH . '/.cache';
$quiet = in_array('--quiet', $argv);
$migrateMain = in_array('--main', $argv);

// Parse options
$outputPath = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--output=') === 0) {
        $outputPath = substr($arg, 9);
    }
}

// Generate default output path with timestamp
if (!$outputPath) {
    $timestamp = date('Ymd_His_') . substr(microtime(), 2, 6);
    $outputPath = "{$cacheDir}/postgresql_{$timestamp}.tar.gz";
}

// Validate postgresql folder exists
if (!is_dir($postgresqlPath)) {
    fwrite(STDERR, "ERROR: PostgreSQL folder not found at: {$postgresqlPath}\n");
    exit(1);
}

// Create cache directory if needed
$outputDir = dirname($outputPath);
if (!is_dir($outputDir)) {
    if (!mkdir($outputDir, 0755, true)) {
        fwrite(STDERR, "ERROR: Cannot create directory: {$outputDir}\n");
        exit(1);
    }
}

$target = $migrateMain ? 'main' : 'tenant';

if (!$quiet) {
    echo "=== StoneScriptPHP Schema Export ===\n";
    echo "Source: {$postgresqlPath}\n";
    echo "Output: {$outputPath}\n";
    echo "Target: {$target}\n\n";
}

// Create tar.gz using shared archive builder
try {
    $stats = buildSchemaArchive($postgresqlPath, $outputPath, $target, $quiet);

    $size = filesize($outputPath);
    $sizeKb = round($size / 1024, 1);

    if (!$quiet) {
        echo "Created: {$outputPath} ({$sizeKb} KB)\n";
        echo "  Tables:     {$stats['tables']} files\n";
        echo "  Functions:  {$stats['functions']} files\n";
        echo "  Views:      {$stats['views']} files\n";
        echo "  Migrations: {$stats['migrations']} files\n";
        echo "  Total:      {$stats['total_files']} files\n";
    }

    // Output the path for scripting
    echo $outputPath . "\n";

    exit(0);

} catch (Exception $e) {
    fwrite(STDERR, "ERROR: Failed to create archive: " . $e->getMessage() . "\n");
    exit(1);
}
