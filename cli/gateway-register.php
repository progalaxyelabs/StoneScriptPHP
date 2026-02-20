<?php
/**
 * StoneScriptPHP CLI - Gateway Register (V2 API)
 *
 * Three-step registration flow:
 *   1. Register platform (idempotent)
 *   2. Upload schema archive
 *   3. Create database from stored schema
 *
 * Usage:
 *   php stone gateway:register [options]
 *
 * Environment variables (required):
 *   DB_GATEWAY_URL    - Gateway URL (e.g., http://localhost:9000)
 *   PLATFORM_ID       - Platform identifier (e.g., myapp)
 *   SCHEMA_NAME       - Schema version name (e.g., v1.0, latest)
 *
 * Environment variables (optional):
 *   DATABASE_ID       - Database identifier (default: main)
 *   ADMIN_TOKEN       - Admin token for /admin/* endpoints
 *
 * Options:
 *   --retry=<n>       Number of retry attempts (default: 3)
 *   --delay=<s>       Delay between retries in seconds (default: 5)
 *   --quiet           Suppress output
 *   --main            Register main DB schema instead of tenant schema (nested layouts only)
 *   --database-id=<id> Override DATABASE_ID
 *   --schema-name=<n>  Override SCHEMA_NAME
 *
 * Example:
 *   DB_GATEWAY_URL=http://localhost:9000 PLATFORM_ID=myapp SCHEMA_NAME=v1.0 \
 *     php stone gateway:register
 */

require_once __DIR__ . '/helpers/schema-archive-builder.php';

// Configuration from environment
$gatewayUrl = getenv('DB_GATEWAY_URL');
$platformId = getenv('PLATFORM_ID');
$schemaName = getenv('SCHEMA_NAME') ?: null;
$databaseId = getenv('DATABASE_ID') ?: 'main';
$adminToken = getenv('ADMIN_TOKEN') ?: null;

// Parse options
$retryCount = 3;
$retryDelay = 5;
$quiet = in_array('--quiet', $argv);
$migrateMain = in_array('--main', $argv);

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

$postgresqlPath = ROOT_PATH . '/src/postgresql';
$cacheDir = ROOT_PATH . '/.cache';

// Validate postgresql folder exists
if (!is_dir($postgresqlPath)) {
    fwrite(STDERR, "ERROR: PostgreSQL folder not found at: {$postgresqlPath}\n");
    exit(1);
}

$target = $migrateMain ? 'main' : 'tenant';

if (!$quiet) {
    echo "=== DB Gateway Registration (V2) ===\n";
    echo "Platform:    {$platformId}\n";
    echo "Schema:      {$schemaName}\n";
    echo "Database ID: {$databaseId}\n";
    echo "Target:      {$target}\n";
    echo "Gateway:     {$gatewayUrl}\n";
    echo "\n";
}

// Create cache directory
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

// Generate timestamped filename
$timestamp = date('Ymd_His_') . substr(microtime(), 2, 6);
$tarFile = "{$cacheDir}/postgresql_{$platformId}_{$timestamp}.tar.gz";

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

// ─── Step 1: Register platform (idempotent) ──────────────────────────────────
if (!$quiet) {
    echo "Step 1/3: Registering platform...\n";
}

$payload = json_encode(['platform' => $platformId]);
$resp = httpRequest('POST', "{$gatewayUrl}/platform/register", [
    'Content-Type: application/json',
    'Content-Length: ' . strlen($payload),
], $payload, 30);

if (in_array($resp['code'], [200, 201, 409])) {
    if (!$quiet) {
        echo "  Platform registered (or already exists)\n\n";
    }
} else {
    fwrite(STDERR, "ERROR: Failed to register platform (HTTP {$resp['code']})\n");
    if ($resp['body']) fwrite(STDERR, "  {$resp['body']}\n");
    exit(1);
}

// ─── Step 2: Upload schema archive ───────────────────────────────────────────
if (!$quiet) {
    echo "Step 2/3: Uploading schema '{$schemaName}'...\n";
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
        $schemaInfo = json_decode($resp['body'], true);
        echo "  Schema uploaded\n";
        if ($schemaInfo) {
            echo "  has_tables: " . ($schemaInfo['has_tables'] ? 'yes' : 'no') . "\n";
            echo "  has_functions: " . ($schemaInfo['has_functions'] ? 'yes' : 'no') . "\n";
            echo "  has_migrations: " . ($schemaInfo['has_migrations'] ? 'yes' : 'no') . "\n";
        }
        echo "\n";
    }
} else {
    fwrite(STDERR, "ERROR: Failed to upload schema (HTTP {$resp['code']})\n");
    if ($resp['body']) fwrite(STDERR, "  {$resp['body']}\n");
    exit(1);
}

// ─── Step 3: Create database from stored schema ──────────────────────────────
if (!$quiet) {
    echo "Step 3/3: Creating database '{$databaseId}'...\n";
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
    ]);

    $headers = [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload),
    ];
    if ($adminToken) {
        $headers[] = "Authorization: Bearer {$adminToken}";
    }

    $resp = httpRequest('POST', "{$gatewayUrl}/admin/database/create", $headers, $payload, 60);

    if (in_array($resp['code'], [200, 201])) {
        $success = true;
        $response = json_decode($resp['body'], true);

        if (!$quiet) {
            echo "\nRegistration successful!\n";
            echo json_encode($response, JSON_PRETTY_PRINT) . "\n";
        }
    } elseif ($resp['code'] === 409) {
        // Database already exists — not an error
        $success = true;
        if (!$quiet) {
            echo "\n  Database already exists\n";
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
