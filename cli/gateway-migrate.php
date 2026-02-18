<?php
/**
 * StoneScriptPHP CLI - Gateway Migrate
 *
 * Hot migration of schema to db-gateway without container restart.
 * Updates all tenant databases or a specific tenant.
 *
 * Usage:
 *   php stone gateway:migrate [options]
 *
 * Environment variables (required):
 *   DB_GATEWAY_URL    - Gateway URL (e.g., http://localhost:9000)
 *   PLATFORM_ID       - Platform identifier (e.g., myapp)
 *
 * Environment variables (optional):
 *   TENANT_ID         - Specific tenant (omit to migrate ALL tenants)
 *
 * Options:
 *   --retry=<n>       Number of retry attempts (default: 3)
 *   --delay=<s>       Delay between retries in seconds (default: 5)
 *   --quiet           Suppress output
 *   --tenant=<id>     Override TENANT_ID environment variable
 *   --main            Migrate main DB schema instead of tenant schema (nested layouts only)
 *   --force           Send force=true to bypass schema validation (use with caution)
 *
 * Example:
 *   # Migrate all tenants
 *   DB_GATEWAY_URL=http://localhost:9000 PLATFORM_ID=myapp php stone gateway:migrate
 *
 *   # Migrate specific tenant
 *   php stone gateway:migrate --tenant=clinic_001
 *
 *   # Migrate main database (nested layout)
 *   php stone gateway:migrate --main
 */

require_once __DIR__ . '/helpers/schema-archive-builder.php';

// Configuration from environment
$gatewayUrl = getenv('DB_GATEWAY_URL');
$platformId = getenv('PLATFORM_ID');
$tenantId = getenv('TENANT_ID') ?: null;

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
    if (strpos($arg, '--tenant=') === 0) {
        $tenantId = substr($arg, 9);
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

$postgresqlPath = ROOT_PATH . '/src/postgresql';
$cacheDir = ROOT_PATH . '/.cache';

// Validate postgresql folder exists
if (!is_dir($postgresqlPath)) {
    fwrite(STDERR, "ERROR: PostgreSQL folder not found at: {$postgresqlPath}\n");
    exit(1);
}

$target = $migrateMain ? 'main' : 'tenant';

if (!$quiet) {
    echo "=== DB Gateway Schema Migration ===\n";
    echo "Platform: {$platformId}\n";
    if ($tenantId) {
        echo "Tenant: {$tenantId} (single tenant)\n";
    } else {
        echo "Tenant: ALL (will migrate all tenant databases)\n";
    }
    echo "Target: {$target}\n";
    echo "Gateway: {$gatewayUrl}\n";
    if ($force) {
        echo "Force: enabled (bypassing schema validation)\n";
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

// Cleanup function
function cleanup($file) {
    if (file_exists($file)) {
        unlink($file);
        $tarPath = preg_replace('/\.gz$/', '', $file);
        if (file_exists($tarPath)) {
            unlink($tarPath);
        }
    }
}

// Register shutdown handler for cleanup
register_shutdown_function(function() use ($tarFile, $quiet) {
    cleanup($tarFile);
    if (!$quiet) {
        echo "Cleaned up: {$tarFile}\n";
    }
});

// Step 1: Create tar.gz
if (!$quiet) {
    echo "Creating schema archive...\n";
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

// Step 2: Send to gateway with retry logic
if (!$quiet) {
    echo "Sending migration to gateway...\n";
}

$attempt = 1;
$success = false;
$response = null;

while ($attempt <= $retryCount && !$success) {
    if (!$quiet) {
        echo "Attempt {$attempt} of {$retryCount}...\n";
    }

    // Build multipart request
    $boundary = uniqid();
    $body = '';

    // Add platform field
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"platform\"\r\n\r\n";
    $body .= "{$platformId}\r\n";

    // Add tenant_id if present
    if ($tenantId) {
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"tenant_id\"\r\n\r\n";
        $body .= "{$tenantId}\r\n";
    }

    // Add force flag if set
    if ($force) {
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"force\"\r\n\r\n";
        $body .= "true\r\n";
    }

    // Add schema file
    $fileContent = file_get_contents($tarFile);
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"schema\"; filename=\"postgresql.tar.gz\"\r\n";
    $body .= "Content-Type: application/gzip\r\n\r\n";
    $body .= $fileContent . "\r\n";
    $body .= "--{$boundary}--\r\n";

    // Send request
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => [
                "Content-Type: multipart/form-data; boundary={$boundary}",
                'Content-Length: ' . strlen($body)
            ],
            'content' => $body,
            'timeout' => 120, // Longer timeout for migrations
            'ignore_errors' => true
        ]
    ]);

    $result = @file_get_contents("{$gatewayUrl}/migrate", false, $context);

    // Parse response
    $httpCode = 0;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (preg_match('/^HTTP\/\d\.\d (\d+)/', $header, $matches)) {
                $httpCode = (int) $matches[1];
            }
        }
    }

    if ($httpCode === 200 && $result) {
        $success = true;
        $response = json_decode($result, true);

        if (!$quiet) {
            echo "\n✅ Migration successful!\n";
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
        if ($httpCode === 0) {
            if (!$quiet) {
                echo "Connection failed (gateway not reachable)\n";
            }
        } else {
            if (!$quiet) {
                echo "Request failed with HTTP {$httpCode}\n";
                if ($result) echo "{$result}\n";
            }
        }

        if ($attempt < $retryCount) {
            if (!$quiet) {
                echo "Retrying in {$retryDelay}s...\n";
            }
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
