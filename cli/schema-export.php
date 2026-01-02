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
 *
 * Example:
 *   php stone schema:export
 *   php stone schema:export --output=/tmp/schema.tar.gz
 */

// Configuration
$postgresqlPath = ROOT_PATH . '/src/postgresql';
$cacheDir = ROOT_PATH . '/.cache';
$quiet = in_array('--quiet', $argv);

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

// Count files
$funcCount = count(glob("{$postgresqlPath}/functions/*.pssql"));
$migrationCount = count(glob("{$postgresqlPath}/migrations/*.pssql"));
$tableCount = count(glob("{$postgresqlPath}/tables/*.pssql"));
$seederCount = count(glob("{$postgresqlPath}/seeders/*.pssql"));

if (!$quiet) {
    echo "=== StoneScriptPHP Schema Export ===\n";
    echo "Source: {$postgresqlPath}\n";
    echo "Output: {$outputPath}\n";
    echo "\nFiles:\n";
    echo "  Functions:  {$funcCount}\n";
    echo "  Migrations: {$migrationCount}\n";
    echo "  Tables:     {$tableCount}\n";
    echo "  Seeders:    {$seederCount}\n";
    echo "\n";
}

// Create tar.gz using PharData
try {
    // Remove existing file if present
    if (file_exists($outputPath)) {
        unlink($outputPath);
    }

    // Also remove intermediate .tar if it exists
    $tarPath = preg_replace('/\.gz$/', '', $outputPath);
    if (file_exists($tarPath)) {
        unlink($tarPath);
    }

    // Create tar archive
    $phar = new PharData($tarPath);

    // Add postgresql folder recursively
    $phar->buildFromDirectory(dirname($postgresqlPath), '/postgresql/');

    // Compress to gzip
    $phar->compress(Phar::GZ);

    // Remove intermediate .tar file
    if (file_exists($tarPath)) {
        unlink($tarPath);
    }

    // Get file size
    $size = filesize($outputPath);
    $sizeKb = round($size / 1024, 1);

    if (!$quiet) {
        echo "Created: {$outputPath} ({$sizeKb} KB)\n";
    }

    // Output the path for scripting
    echo $outputPath . "\n";

    exit(0);

} catch (Exception $e) {
    fwrite(STDERR, "ERROR: Failed to create archive: " . $e->getMessage() . "\n");
    exit(1);
}
