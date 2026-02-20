<?php
/**
 * StoneScriptPHP CLI - Gateway Migrate (V2 API)
 *
 * Two-step migration flow:
 *   1. Upload schema archive (stores new version)
 *   2. Migrate database using stored schema (JSON body)
 *
 * Usage:
 *   php stone gateway:migrate [options]
 *
 * Environment variables (required):
 *   DB_GATEWAY_URL    - Gateway URL (e.g., http://localhost:9000)
 *   PLATFORM_ID       - Platform identifier (e.g., myapp)
 *   SCHEMA_NAME       - Schema version name (e.g., v1.0, latest)
 *   DATABASE_ID       - Database to migrate (e.g., main, tenant_abc123)
 *
 * Options:
 *   --retry=<n>       Number of retry attempts (default: 3)
 *   --delay=<s>       Delay between retries in seconds (default: 5)
 *   --quiet           Suppress output
 *   --main            Migrate main DB schema instead of tenant schema (nested layouts only)
 *   --force           Bypass DATALOSS schema validation (use with caution)
 *   --database-id=<id> Override DATABASE_ID
 *   --schema-name=<n>  Override SCHEMA_NAME
 *
 * Example:
 *   # Migrate main database
 *   DB_GATEWAY_URL=http://localhost:9000 PLATFORM_ID=myapp SCHEMA_NAME=v1.0 \
 *     DATABASE_ID=main php stone gateway:migrate
 *
 *   # Migrate specific tenant
 *   php stone gateway:migrate --database-id=tenant_abc123 --schema-name=v1.0
 */

require_once __DIR__ . '/helpers/schema-archive-builder.php';

// Configuration from environment
$gatewayUrl = getenv('DB_GATEWAY_URL');
$platformId = getenv('PLATFORM_ID');
$schemaName = getenv('SCHEMA_NAME') ?: null;
$databaseId = getenv('DATABASE_ID') ?: null;

// Parse options
$retryCount = 3;
$retryDelay = 5;
$quiet = in_array('--quiet', $argv);
$migrateMain = in_array('--main', $argv);
$force = in_array('--force', $argv);

foreach ($argv as $arg) {
    if (strpos($arg, '--retry=') === 0) {
        $retryCount = (int) substr($arg, 8);
    }
    if (strpos($arg, '--delay=') === 0) {
        $retryDelay = (int) substr($arg, 8);
    }
    if (strpos($arg, '--database-id=') === 0) {
        $databaseId = substr($arg, 14);
    }
    if (strpos($arg, '--schema-name=') === 0) {
        $schemaName = substr($arg, 14);
    }
}

// Validate required environment variables
if (!$gatewayUrl) {
    fwrite(STDERR, "ERROR: DB_GATEWAY_URL environment variable is required\n");
    exit(1);
}

if (!$platformId) {
    fwrite(STDERR, "ERROR: PLATFORM_ID environment variable is required\n");
    exit(1);
}

if (!$schemaName) {
    fwrite(STDERR, "ERROR: SCHEMA_NAME environment variable is required (or use --schema-name=...)\n");
    exit(1);
}

if (!$databaseId) {
    fwrite(STDERR, "ERROR: DATABASE_ID environment variable is required (or use --database-id=...)\n");
    fwrite(STDERR, "  Use 'main' for main database, or a tenant ID for tenant databases.\n");
    exit(1);
}

$postgresqlPath = ROOT_PATH . '/src/postgresql';
$cacheDir = ROOT_PATH . '/.cache';

// Validate postgresql folder exists
if (!is_dir($postgresqlPath)) {
    fwrite(STDERR, "ERROR: PostgreSQL folder not found at: {$postgresqlPath}\n");
    exit(1);
}

$target = $migrateMain ? 'main' : 'tenant';

if (!$quiet) {
    echo "=== DB Gateway Schema Migration (V2) ===\n";
    echo "Platform:    {$platformId}\n";
    echo "Schema:      {$schemaName}\n";
    echo "Database ID: {$databaseId}\n";
    echo "Target:      {$target}\n";
    echo "Gateway:     {$gatewayUrl}\n";
    if ($force) {
        echo "Force:       enabled (bypassing schema validation)\n";
    }
    echo "\n";
}

// Create cache directory
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

// Generate timestamped filename
$timestamp = date('Ymd_His_') . substr(microtime(), 2, 6);
$tarFile = "{$cacheDir}/postgresql_{$platformId}_migrate_{$timestamp}.tar.gz";

// Register shutdown handler for cleanup
register_shutdown_function(function() use ($tarFile) {
    if (file_exists($tarFile)) {
        unlink($tarFile);
    }
    $tarPath = preg_replace('/\.gz$/', '', $tarFile);
    if (file_exists($tarPath)) {
        unlink($tarPath);
    }
});

// ─── Build schema archive ────────────────────────────────────────────────────
if (!$quiet) {
    echo "Building schema archive...\n";
}

try {
    $stats = buildSchemaArchive($postgresqlPath, $tarFile, $target, $quiet);

    $size = round(filesize($tarFile) / 1024, 1);

    if (!$quiet) {
        echo "Created: {$tarFile} ({$size} KB)\n";
        echo "  Tables:     {$stats['tables']} files\n";
        echo "  Functions:  {$stats['functions']} files\n";
        echo "  Views:      {$stats['views']} files\n";
        echo "  Migrations: {$stats['migrations']} files\n";
        echo "  Total:      {$stats['total_files']} files\n\n";
    }
} catch (Exception $e) {
    fwrite(STDERR, "ERROR: Failed to create archive: " . $e->getMessage() . "\n");
    exit(1);
}

// Helper: make HTTP request using stream context
function httpRequest(string $method, string $url, array $headers, ?string $body, int $timeout): array {
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => $headers,
            'content' => $body,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ]
    ]);

    $result = @file_get_contents($url, false, $context);
    $httpCode = 0;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (preg_match('/^HTTP\/\d\.\d (\d+)/', $header, $matches)) {
                $httpCode = (int) $matches[1];
            }
        }
    }

    return ['code' => $httpCode, 'body' => $result];
}

// ─── Step 1: Upload schema archive ───────────────────────────────────────────
if (!$quiet) {
    echo "Step 1/2: Uploading schema '{$schemaName}'...\n";
}

$boundary = uniqid();
$body = '';

// Add schema_name field
$body .= "--{$boundary}\r\n";
$body .= "Content-Disposition: form-data; name=\"schema_name\"\r\n\r\n";
$body .= "{$schemaName}\r\n";

// Add schema file
$fileContent = file_get_contents($tarFile);
$body .= "--{$boundary}\r\n";
$body .= "Content-Disposition: form-data; name=\"schema\"; filename=\"postgresql.tar.gz\"\r\n";
$body .= "Content-Type: application/gzip\r\n\r\n";
$body .= $fileContent . "\r\n";
$body .= "--{$boundary}--\r\n";

$resp = httpRequest('POST', "{$gatewayUrl}/platform/{$platformId}/schema", [
    "Content-Type: multipart/form-data; boundary={$boundary}",
    'Content-Length: ' . strlen($body),
], $body, 60);

if (in_array($resp['code'], [200, 201])) {
    if (!$quiet) {
        echo "  Schema uploaded\n\n";
    }
} else {
    fwrite(STDERR, "ERROR: Failed to upload schema (HTTP {$resp['code']})\n");
    if ($resp['body']) fwrite(STDERR, "  {$resp['body']}\n");
    exit(1);
}

// ─── Step 2: Migrate database ────────────────────────────────────────────────
if (!$quiet) {
    echo "Step 2/2: Migrating database '{$databaseId}'...\n";
}

$attempt = 1;
$success = false;

while ($attempt <= $retryCount && !$success) {
    if (!$quiet) {
        echo "  Attempt {$attempt} of {$retryCount}...\n";
    }

    $payload = json_encode([
        'platform' => $platformId,
        'schema_name' => $schemaName,
        'database_id' => $databaseId,
        'force' => $force,
    ]);

    $resp = httpRequest('POST', "{$gatewayUrl}/v2/migrate", [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload),
    ], $payload, 120);

    if (in_array($resp['code'], [200, 201])) {
        $success = true;
        $response = json_decode($resp['body'], true);

        if (!$quiet) {
            echo "\nMigration successful!\n";
            echo json_encode($response, JSON_PRETTY_PRINT) . "\n\n";

            echo "Summary:\n";
            echo "  Status: " . ($response['status'] ?? 'unknown') . "\n";
            echo "  Databases updated: " . count($response['databases_updated'] ?? []) . "\n";
            echo "  Migrations applied: " . ($response['migrations_applied'] ?? 0) . "\n";
            echo "  Functions updated: " . ($response['functions_updated'] ?? 0) . "\n";
            echo "  Execution time: " . ($response['execution_time_ms'] ?? 0) . "ms\n";

            if (!empty($response['databases_updated'])) {
                echo "\nDatabases:\n";
                foreach ($response['databases_updated'] as $db) {
                    echo "  - {$db}\n";
                }
            }
        }
    } else {
        if ($resp['code'] === 0) {
            if (!$quiet) echo "  Connection failed (gateway not reachable)\n";
        } else {
            if (!$quiet) {
                echo "  Failed (HTTP {$resp['code']})\n";
                if ($resp['body']) echo "  {$resp['body']}\n";
            }
        }

        if ($attempt < $retryCount) {
            if (!$quiet) echo "  Retrying in {$retryDelay}s...\n";
            sleep($retryDelay);
        }
    }

    $attempt++;
}

if (!$success) {
    fwrite(STDERR, "ERROR: Failed after {$retryCount} attempts\n");
    exit(1);
}

exit(0);
