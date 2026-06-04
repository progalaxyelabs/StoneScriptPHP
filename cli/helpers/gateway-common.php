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
 * @return array{retry: int, delay: int, quiet: bool, force: bool, allow: string[], skip_verification: bool, database_id: ?string, schema_name: ?string, main_schema_name: ?string, tenant_schema_name: ?string}
 */
function parseGatewayOptions(array $argv): array
{
    // Granular per-operation safety flags → gateway allow-tokens (least-privilege).
    // Each flag unlocks exactly one guarded destructive operation.
    $allowFlagMap = [
        '--allow-drop-table'          => 'drop_table',
        '--allow-drop-column'         => 'drop_column',
        '--allow-column-type-change'  => 'modify_column_type',
        '--allow-add-not-null-column' => 'add_not_null_column',
        '--allow-set-not-null'        => 'set_not_null',
    ];

    $allow = [];
    foreach ($allowFlagMap as $flag => $token) {
        if (in_array($flag, $argv, true)) {
            $allow[] = $token;
        }
    }

    $options = [
        'retry' => 3,
        'delay' => 5,
        'quiet' => in_array('--quiet', $argv),
        // Back-compat allow-all escape hatch. `--force` permits every guarded
        // operation AND skips post-migration verification (legacy behavior).
        'force' => in_array('--force', $argv),
        // Granular per-operation allow-tokens (gate #1: schema diff dataloss/incompatible).
        'allow' => $allow,
        // Gate #2: bypass the holistic post-migration verification check only.
        'skip_verification' => in_array('--dangerously-skip-verification', $argv, true),
        'database_id' => null,
        'schema_name' => null,
        'main_schema_name' => null,
        'tenant_schema_name' => null,
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
        if (strpos($arg, '--main-schema-name=') === 0) {
            $options['main_schema_name'] = substr($arg, 19);
        }
        if (strpos($arg, '--tenant-schema-name=') === 0) {
            $options['tenant_schema_name'] = substr($arg, 21);
        }
    }

    return $options;
}

/**
 * Load the cross-DB-link authorization block from environment variables.
 *
 * deploy-manager injects these vars into the schema container when the
 * `--dangerously-allow-cross-db-link` flag is passed (#2909, deploy-manager v0.10.0):
 *
 *   GATEWAY_CROSS_DB_LINK_AUTHORIZED  = "true"
 *   GATEWAY_CROSS_DB_LINK_DEPLOY_REF  = "<version-tag>"
 *   GATEWAY_CROSS_DB_LINK_GRANTS      = '<json-array-of-grants>'
 *
 * When the vars are absent (normal deploys) this returns null and no
 * `cross_db_link` block is sent to the gateway — behaviour is unchanged.
 * When present, the returned array is merged into the /v2/migrate request
 * body so the gateway can materialise cross-DB staging tables.
 *
 * The gateway's fail-closed rule (#2908): any `cross_db/<link>.json`
 * manifest in the schema bundle is REJECTED when this block is absent.
 *
 * @return array{authorized: true, deploy_ref: string|null, grants: array}|null
 */
function loadCrossDbLinkAuth(): ?array
{
    $authorized = getenv('GATEWAY_CROSS_DB_LINK_AUTHORIZED');
    if (!$authorized || strtolower(trim($authorized)) !== 'true') {
        return null;
    }

    $grantsJson = getenv('GATEWAY_CROSS_DB_LINK_GRANTS');
    if (!$grantsJson) {
        fwrite(STDERR, "WARNING: GATEWAY_CROSS_DB_LINK_AUTHORIZED=true but GATEWAY_CROSS_DB_LINK_GRANTS is absent — cross-DB-link authorization will NOT be sent\n");
        return null;
    }

    $grants = json_decode($grantsJson, true);
    if (!is_array($grants)) {
        fwrite(STDERR, "WARNING: GATEWAY_CROSS_DB_LINK_GRANTS is not valid JSON — cross-DB-link authorization will NOT be sent\n");
        return null;
    }

    $deployRef = getenv('GATEWAY_CROSS_DB_LINK_DEPLOY_REF') ?: null;
    if (!$deployRef) {
        fwrite(STDERR, "WARNING: GATEWAY_CROSS_DB_LINK_DEPLOY_REF is absent — audit log deploy_ref will be null\n");
    }

    return [
        'authorized' => true,
        'deploy_ref' => $deployRef,
        'grants'     => $grants,
    ];
}

/**
 * Load and validate gateway environment variables.
 *
 * @param array  $options     Parsed CLI options (may override env vars)
 * @param bool   $requireDb   Whether DATABASE_ID is required
 * @return array{gateway_url: string, platform_id: string, schema_name: string, main_schema_name: string, tenant_schema_name: string, database_id: string, admin_token: ?string, cross_db_link: array|null}
 */
function loadGatewayEnv(array $options, bool $requireDb = true): array
{
    $gatewayUrl = getenv('DB_GATEWAY_URL');
    $platformId = getenv('PLATFORM_ID');
    $schemaName = $options['schema_name'] ?: (getenv('SCHEMA_NAME') ?: null);
    $databaseId = $options['database_id'] ?: (getenv('DATABASE_ID') ?: 'main');
    $adminToken = getenv('DB_GATEWAY_ADMIN_TOKEN') ?: (getenv('ADMIN_TOKEN') ?: null);

    // Main schema: MAIN_SCHEMA_NAME takes priority, falls back to SCHEMA_NAME
    $mainSchemaName = $options['main_schema_name'] ?: (getenv('MAIN_SCHEMA_NAME') ?: $schemaName);

    // Tenant schema: TENANT_SCHEMA_NAME takes priority, falls back to SCHEMA_NAME
    $tenantSchemaName = $options['tenant_schema_name'] ?: (getenv('TENANT_SCHEMA_NAME') ?: $schemaName);

    if (!$gatewayUrl) {
        fwrite(STDERR, "ERROR: DB_GATEWAY_URL environment variable is required\n");
        exit(1);
    }

    if (!$platformId) {
        fwrite(STDERR, "ERROR: PLATFORM_ID environment variable is required\n");
        exit(1);
    }

    if (!$schemaName && !$mainSchemaName && !$tenantSchemaName) {
        fwrite(STDERR, "ERROR: SCHEMA_NAME (or MAIN_SCHEMA_NAME/TENANT_SCHEMA_NAME) environment variable is required (or use --schema-name=...)\n");
        exit(1);
    }

    if ($requireDb && !$databaseId) {
        fwrite(STDERR, "ERROR: DATABASE_ID environment variable is required (or use --database-id=...)\n");
        exit(1);
    }

    return [
        'gateway_url'   => $gatewayUrl,
        'platform_id'   => $platformId,
        'schema_name'   => $schemaName,
        'main_schema_name'   => $mainSchemaName,
        'tenant_schema_name' => $tenantSchemaName,
        'database_id'   => $databaseId,
        'admin_token'   => $adminToken,
        'cross_db_link' => loadCrossDbLinkAuth(),
    ];
}

/**
 * Build JSON request headers, optionally adding the admin bearer token.
 *
 * The gateway's /v2/migrate, /v2/migrate-all and /admin/* endpoints are guarded by
 * admin_auth_middleware (shared admin bearer + IP allowlist). When an admin token is
 * available it MUST be presented as `Authorization: Bearer <token>`. When no token is
 * configured (local/ungated gateway) the header is omitted so behaviour is unchanged.
 *
 * @param string      $payload     JSON body the request will send (for Content-Length).
 * @param string|null $adminToken  Admin token, or null to omit the Authorization header.
 * @return string[] HTTP header lines.
 */
function gatewayJsonHeaders(string $payload, ?string $adminToken = null): array
{
    $headers = [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload),
    ];
    if ($adminToken) {
        $headers[] = "Authorization: Bearer {$adminToken}";
    }
    return $headers;
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

        $headers = gatewayJsonHeaders($payload, $adminToken);

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
/**
 * @param array|null $crossDbLink  Authorization block for the gateway's cross-DB-link
 *   capability (#2908/#2909). When non-null, included in the request body as
 *   `cross_db_link: {authorized, deploy_ref, grants[]}`. Null (default) = absent, which
 *   causes the gateway to fail-closed any cross_db manifest in the schema bundle.
 *   Sourced from deploy-manager env vars via loadGatewayEnv()['cross_db_link'].
 */
function stepMigrateDatabase(string $gatewayUrl, string $platformId, string $schemaName, string $databaseId, ?string $adminToken, bool $force, int $retryCount, int $retryDelay, bool $quiet, array $allow = [], bool $skipVerification = false, ?array $crossDbLink = null): void
{
    if (!$quiet) {
        echo "Migrating database '{$databaseId}'...\n";
        if ($crossDbLink !== null) {
            echo "  cross-DB-link: authorized (deploy_ref=" . ($crossDbLink['deploy_ref'] ?? 'null') . ", grants=" . count($crossDbLink['grants'] ?? []) . ")\n";
        }
    }

    $attempt = 1;
    $success = false;

    while ($attempt <= $retryCount && !$success) {
        if (!$quiet) {
            echo "  Attempt {$attempt} of {$retryCount}...\n";
        }

        $body = [
            'platform'          => $platformId,
            'schema_name'       => $schemaName,
            'database_id'       => $databaseId,
            'force'             => $force,
            'allow'             => array_values($allow),
            'skip_verification' => $skipVerification,
        ];
        if ($crossDbLink !== null) {
            $body['cross_db_link'] = $crossDbLink;
        }
        $payload = json_encode($body);

        $headers = gatewayJsonHeaders($payload, $adminToken);

        $resp = gatewayHttpRequest('POST', "{$gatewayUrl}/v2/migrate", $headers, $payload, 120);

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

/**
 * Step: Migrate ALL tenant databases using stored schema (with retry).
 *
 * Calls POST /v2/migrate-all — migrates all existing tenant databases for the
 * platform sequentially. Skips gracefully if no tenant databases exist.
 *
 * @return void Exits on failure.
 */
function stepMigrateAllDatabases(string $gatewayUrl, string $platformId, string $schemaName, ?string $adminToken, bool $force, int $retryCount, int $retryDelay, bool $quiet, array $allow = [], bool $skipVerification = false, ?array $crossDbLink = null): void
{
    if (!$quiet) {
        echo "Migrating all tenant databases (POST /v2/migrate-all)...\n";
        if ($crossDbLink !== null) {
            echo "  cross-DB-link: authorized (deploy_ref=" . ($crossDbLink['deploy_ref'] ?? 'null') . ", grants=" . count($crossDbLink['grants'] ?? []) . ")\n";
        }
    }

    $attempt = 1;
    $success = false;

    while ($attempt <= $retryCount && !$success) {
        if (!$quiet) {
            echo "  Attempt {$attempt} of {$retryCount}...\n";
        }

        $body = [
            'platform'          => $platformId,
            'schema_name'       => $schemaName,
            'force'             => $force,
            'allow'             => array_values($allow),
            'skip_verification' => $skipVerification,
        ];
        if ($crossDbLink !== null) {
            $body['cross_db_link'] = $crossDbLink;
        }
        $payload = json_encode($body);

        $headers = gatewayJsonHeaders($payload, $adminToken);

        $resp = gatewayHttpRequest('POST', "{$gatewayUrl}/v2/migrate-all", $headers, $payload, 300);

        if (in_array($resp['code'], [200, 201])) {
            $success = true;
            $response = json_decode($resp['body'], true);

            if (!$quiet) {
                echo "\n  Migration-all successful!\n";
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
        } elseif ($resp['code'] === 204) {
            // 204 = no tenant databases exist yet, skip gracefully
            $success = true;
            if (!$quiet) {
                echo "\n  No tenant databases found — skipping (nothing to migrate)\n";
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
 * Compute a deterministic SHA-256 hash of all files under a directory.
 *
 * Hashes both relative paths and file contents so renames and edits are detected.
 *
 * @param string $dirPath Absolute path to the directory to hash.
 * @return string 64-char hex SHA-256, or empty string if directory does not exist.
 */
function computeSchemaHash(string $dirPath): string
{
    if (!is_dir($dirPath)) {
        return '';
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dirPath, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $files[] = $file->getRealPath();
        }
    }

    sort($files);

    $hash = hash_init('sha256');
    foreach ($files as $filePath) {
        $relativePath = substr($filePath, strlen($dirPath));
        hash_update($hash, $relativePath);
        hash_update($hash, file_get_contents($filePath));
    }

    return hash_final($hash);
}
