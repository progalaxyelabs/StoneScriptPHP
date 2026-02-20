<?php
/**
 * StoneScriptPHP CLI Helper — Gateway Common Functions
 *
 * Shared code for gateway:register-main, gateway:register-tenant,
 * gateway:migrate-main, and gateway:migrate-tenant commands.
 */

require_once __DIR__ . '/schema-archive-builder.php';

/**
 * Parse gateway CLI options from argv.
 *
 * @param array $argv
 * @return array{retry: int, delay: int, quiet: bool, force: bool, database_id: ?string, schema_name: ?string}
 */
function parseGatewayOptions(array $argv): array
{
    $options = [
        'retry' => 3,
        'delay' => 5,
        'quiet' => in_array('--quiet', $argv),
        'force' => in_array('--force', $argv),
        'database_id' => null,
        'schema_name' => null,
    ];

    foreach ($argv as $arg) {
        if (strpos($arg, '--retry=') === 0) {
            $options['retry'] = (int) substr($arg, 8);
        }
        if (strpos($arg, '--delay=') === 0) {
            $options['delay'] = (int) substr($arg, 8);
        }
        if (strpos($arg, '--database-id=') === 0) {
            $options['database_id'] = substr($arg, 14);
        }
        if (strpos($arg, '--schema-name=') === 0) {
            $options['schema_name'] = substr($arg, 14);
        }
    }

    return $options;
}

/**
 * Load and validate gateway environment variables.
 *
 * @param array  $options     Parsed CLI options (may override env vars)
 * @param bool   $requireDb   Whether DATABASE_ID is required
 * @return array{gateway_url: string, platform_id: string, schema_name: string, database_id: string, admin_token: ?string}
 */
function loadGatewayEnv(array $options, bool $requireDb = true): array
{
    $gatewayUrl = getenv('DB_GATEWAY_URL');
    $platformId = getenv('PLATFORM_ID');
    $schemaName = $options['schema_name'] ?: (getenv('SCHEMA_NAME') ?: null);
    $databaseId = $options['database_id'] ?: (getenv('DATABASE_ID') ?: 'main');
    $adminToken = getenv('ADMIN_TOKEN') ?: null;

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

    if ($requireDb && !$databaseId) {
        fwrite(STDERR, "ERROR: DATABASE_ID environment variable is required (or use --database-id=...)\n");
        exit(1);
    }

    return [
        'gateway_url' => $gatewayUrl,
        'platform_id' => $platformId,
        'schema_name' => $schemaName,
        'database_id' => $databaseId,
        'admin_token' => $adminToken,
    ];
}

/**
 * Make an HTTP request using stream context.
 *
 * @return array{code: int, body: string}
 */
function gatewayHttpRequest(string $method, string $url, array $headers, ?string $body, int $timeout): array
{
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

/**
 * Build and get path to schema archive.
 *
 * @param string $target  'main' or 'tenant'
 * @param string $platformId
 * @param string $suffix  Archive filename suffix (e.g., 'register', 'migrate')
 * @param bool   $quiet
 * @return array{tar_file: string, stats: array}
 */
function buildGatewayArchive(string $target, string $platformId, string $suffix, bool $quiet): array
{
    $postgresqlPath = ROOT_PATH . '/src/postgresql';
    $cacheDir = ROOT_PATH . '/.cache';

    if (!is_dir($postgresqlPath)) {
        fwrite(STDERR, "ERROR: PostgreSQL folder not found at: {$postgresqlPath}\n");
        exit(1);
    }

    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }

    $timestamp = date('Ymd_His_') . substr(microtime(), 2, 6);
    $tarFile = "{$cacheDir}/postgresql_{$platformId}_{$suffix}_{$timestamp}.tar.gz";

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

    return ['tar_file' => $tarFile, 'stats' => $stats];
}

/**
 * Step: Register platform (idempotent).
 *
 * @return void Exits on failure.
 */
function stepRegisterPlatform(string $gatewayUrl, string $platformId, bool $quiet): void
{
    if (!$quiet) {
        echo "Registering platform...\n";
    }

    $payload = json_encode(['platform' => $platformId]);
    $resp = gatewayHttpRequest('POST', "{$gatewayUrl}/platform/register", [
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
}

/**
 * Step: Upload schema archive.
 *
 * @return void Exits on failure.
 */
function stepUploadSchema(string $gatewayUrl, string $platformId, string $schemaName, string $tarFile, bool $quiet): void
{
    if (!$quiet) {
        echo "Uploading schema '{$schemaName}'...\n";
    }

    $boundary = uniqid();
    $body = '';

    $body .= "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"schema_name\"\r\n\r\n";
    $body .= "{$schemaName}\r\n";

    $fileContent = file_get_contents($tarFile);
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"schema\"; filename=\"postgresql.tar.gz\"\r\n";
    $body .= "Content-Type: application/gzip\r\n\r\n";
    $body .= $fileContent . "\r\n";
    $body .= "--{$boundary}--\r\n";

    $resp = gatewayHttpRequest('POST', "{$gatewayUrl}/platform/{$platformId}/schema", [
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
}

/**
 * Step: Create database from stored schema (with retry).
 *
 * @return void Exits on failure.
 */
function stepCreateDatabase(string $gatewayUrl, string $platformId, string $schemaName, string $databaseId, ?string $adminToken, int $retryCount, int $retryDelay, bool $quiet): void
{
    if (!$quiet) {
        echo "Creating database '{$databaseId}'...\n";
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

        $resp = gatewayHttpRequest('POST', "{$gatewayUrl}/admin/database/create", $headers, $payload, 60);

        if (in_array($resp['code'], [200, 201])) {
            $success = true;
            $response = json_decode($resp['body'], true);
            if (!$quiet) {
                echo "\n  Database created successfully!\n";
                echo "  " . json_encode($response, JSON_PRETTY_PRINT) . "\n";
            }
        } elseif ($resp['code'] === 409) {
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
}

/**
 * Step: Migrate database using stored schema (with retry).
 *
 * @return void Exits on failure.
 */
function stepMigrateDatabase(string $gatewayUrl, string $platformId, string $schemaName, string $databaseId, bool $force, int $retryCount, int $retryDelay, bool $quiet): void
{
    if (!$quiet) {
        echo "Migrating database '{$databaseId}'...\n";
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

        $resp = gatewayHttpRequest('POST', "{$gatewayUrl}/v2/migrate", [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload),
        ], $payload, 120);

        if (in_array($resp['code'], [200, 201])) {
            $success = true;
            $response = json_decode($resp['body'], true);

            if (!$quiet) {
                echo "\n  Migration successful!\n";
                echo "  Status: " . ($response['status'] ?? 'unknown') . "\n";
                echo "  Databases updated: " . count($response['databases_updated'] ?? []) . "\n";
                echo "  Migrations applied: " . ($response['migrations_applied'] ?? 0) . "\n";
                echo "  Functions updated: " . ($response['functions_updated'] ?? 0) . "\n";
                echo "  Execution time: " . ($response['execution_time_ms'] ?? 0) . "ms\n";

                if (!empty($response['databases_updated'])) {
                    echo "\n  Databases:\n";
                    foreach ($response['databases_updated'] as $db) {
                        echo "    - {$db}\n";
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
}
