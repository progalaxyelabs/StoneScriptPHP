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
 * The deployment tooling injects these vars into the schema container when the
 * `--dangerously-allow-cross-db-link` flag is passed:
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
 * The gateway's fail-closed rule: any `cross_db/<link>.json`
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
 *   capability. When non-null, included in the request body as
 *   `cross_db_link: {authorized, deploy_ref, grants[]}`. Null (default) = absent, which
 *   causes the gateway to fail-closed any cross_db manifest in the schema bundle.
 *   Sourced from deployment tooling env vars via loadGatewayEnv()['cross_db_link'].
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
 * Step: Migrate ALL tenant databases using stored schema (async job model).
 *
 * Implements the INITIATE + POLL pattern per gateway spec §8.2.4 with
 * backward compatibility for legacy synchronous gateways per §8.2.5.
 *
 * Async path (gateway returns HTTP 202 + job_id):
 *   1. POST /v2/migrate-all → job_id
 *   2. Poll GET /v2/migrate-all/{job_id} with exponential backoff (3s → 15s cap)
 *      until a terminal status: completed / failed / partial / interrupted
 *   3. interrupted → re-initiate once and resume the poll loop
 *   4. Poll-transport errors (code 0 or 5xx on the GET) are retried; they do
 *      NOT signal migration failure
 *   5. MAX_WAIT ceiling: if exceeded, exits with a non-zero code but notes the
 *      server-side job is still running and can be re-polled by job_id
 *
 * Legacy sync path (gateway returns HTTP 200 with direct totals):
 *   Treated as an already-terminal result and reported directly (§8.2.5).
 *
 * Summary fix (§8.2.6): totals are derived from the actual response fields
 * (total_databases / succeeded / failed / results[]) — not from the previously
 * read but never-returned fields databases_updated / migrations_applied /
 * functions_updated at the top level.
 *
 * @param string      $gatewayUrl
 * @param string      $platformId
 * @param string      $schemaName
 * @param string|null $adminToken
 * @param bool        $force
 * @param int         $retryCount       Retry attempts for the INITIATE call only.
 * @param int         $retryDelay       Delay between INITIATE retries (seconds).
 * @param bool        $quiet
 * @param array       $allow            Granular allow-tokens (gate #1).
 * @param bool        $skipVerification Bypass holistic post-migration check only.
 * @param array|null  $crossDbLink      Cross-DB-link authorization block or null.
 * @param int         $maxWait          Max seconds the client will wait for async
 *                                      jobs (default: 3600 = 1 hour). The server
 *                                      job continues beyond this limit.
 * @return void Exits on failure.
 */
function stepMigrateAllDatabases(string $gatewayUrl, string $platformId, string $schemaName, ?string $adminToken, bool $force, int $retryCount, int $retryDelay, bool $quiet, array $allow = [], bool $skipVerification = false, ?array $crossDbLink = null, int $maxWait = 3600): void
{
    if (!$quiet) {
        echo "Migrating all tenant databases (POST /v2/migrate-all)...\n";
        if ($crossDbLink !== null) {
            echo "  cross-DB-link: authorized (deploy_ref=" . ($crossDbLink['deploy_ref'] ?? 'null') . ", grants=" . count($crossDbLink['grants'] ?? []) . ")\n";
        }
    }

    // Build the initiate request body (same shape for both sync and async gateways).
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

    // -----------------------------------------------------------------------
    // INITIATE (with retry on connection/5xx failures)
    // -----------------------------------------------------------------------
    $resp = gatewayInitiateMigrateAll($gatewayUrl, $body, $adminToken, $retryCount, $retryDelay, $quiet);

    // -----------------------------------------------------------------------
    // BACKWARD-COMPAT BRANCH (§8.2.5)
    // -----------------------------------------------------------------------
    if ($resp['code'] === 200 || $resp['code'] === 201) {
        // Legacy synchronous gateway: treat the response as terminal.
        $response = json_decode($resp['body'], true) ?? [];
        if (!$quiet) {
            echo "\n  Migration-all complete (legacy synchronous gateway).\n";
        }
        printMigrateAllSummary($response, $quiet);
        // Check for failures in results[].
        checkMigrateAllFailures($response, $quiet);
        return;
    }

    if ($resp['code'] === 204) {
        // No tenant databases exist yet.
        if (!$quiet) {
            echo "\n  No tenant databases found — skipping (nothing to migrate)\n";
        }
        return;
    }

    if ($resp['code'] !== 202) {
        fwrite(STDERR, "ERROR: Unexpected HTTP {$resp['code']} from POST /v2/migrate-all\n");
        if ($resp['body']) fwrite(STDERR, "  {$resp['body']}\n");
        exit(1);
    }

    // -----------------------------------------------------------------------
    // ASYNC PATH (§8.2.4) — HTTP 202 + job_id
    // -----------------------------------------------------------------------
    $initResponse = json_decode($resp['body'], true) ?? [];
    $jobId = $initResponse['job_id'] ?? null;

    if (!$jobId) {
        fwrite(STDERR, "ERROR: Gateway returned 202 but no job_id in response body\n");
        if ($resp['body']) fwrite(STDERR, "  {$resp['body']}\n");
        exit(1);
    }

    if (!$quiet) {
        $total = $initResponse['total_databases'] ?? '?';
        echo "  Job accepted: {$jobId} (status={$initResponse['status']}, total_databases={$total})\n";
        echo "  Polling for completion...\n";
    }

    gatewayPollMigrateAllJob($gatewayUrl, $jobId, $platformId, $schemaName, $body, $adminToken, $quiet, $maxWait);
}

/**
 * Send the POST /v2/migrate-all initiate request, retrying on connection/5xx errors.
 *
 * Returns the raw response array on the first non-retriable response (2xx, 202, 4xx)
 * or exits on persistent failure.
 *
 * @internal
 * @return array{code: int, body: string|false}
 */
function gatewayInitiateMigrateAll(string $gatewayUrl, array $body, ?string $adminToken, int $retryCount, int $retryDelay, bool $quiet): array
{
    $payload  = json_encode($body);
    $headers  = gatewayJsonHeaders($payload, $adminToken);
    $attempt  = 1;

    while (true) {
        if (!$quiet && $retryCount > 1) {
            echo "  Attempt {$attempt} of {$retryCount}...\n";
        }

        // Initiate request uses a short timeout (30s) because the async gateway
        // responds immediately (202). The legacy sync gateway blocks, so we keep
        // a generous timeout for it (300s) by using 300 for the legacy branch.
        // We cannot know upfront which gateway type we are talking to, so we use
        // 300s for the initiate call to remain compatible with the legacy sync path.
        $resp = gatewayHttpRequest('POST', "{$gatewayUrl}/v2/migrate-all", $headers, $payload, 300);

        // Success-range or client-error → return immediately (no retry).
        if ($resp['code'] >= 200 && $resp['code'] < 500) {
            return $resp;
        }

        // Connection failure (code=0) or 5xx → retriable.
        $isRetriable = ($resp['code'] === 0 || $resp['code'] >= 500);
        if ($isRetriable && $attempt < $retryCount) {
            if (!$quiet) {
                $label = $resp['code'] === 0 ? 'Connection failed' : "HTTP {$resp['code']}";
                echo "  {$label} — retrying in {$retryDelay}s...\n";
            }
            sleep($retryDelay);
            $attempt++;
            continue;
        }

        // Exhausted retries.
        fwrite(STDERR, "ERROR: Failed to initiate migrate-all after {$attempt} attempt(s) (HTTP {$resp['code']})\n");
        if ($resp['body']) fwrite(STDERR, "  {$resp['body']}\n");
        exit(1);
    }
}

/**
 * Poll GET /v2/migrate-all/{job_id} until a terminal state, then report.
 *
 * Implements the reference poll loop from gateway spec §8.2.4:
 *   - Exponential backoff: starts at 3s, grows ×1.5 per non-terminal poll, caps at 15s
 *   - Poll-transport errors (code=0 or 5xx) are retried; they do NOT signal job failure
 *   - `interrupted` status → re-initiate once and continue polling the new job
 *   - `failed` or `partial` → exit with non-zero after printing failed results
 *   - `completed` → print summary, return
 *   - MAX_WAIT ceiling: if exceeded, print the job_id and exit non-zero (server job continues)
 *
 * @internal
 * @return void Exits on failure or timeout.
 */
function gatewayPollMigrateAllJob(string $gatewayUrl, string $jobId, string $platformId, string $schemaName, array $initiateBody, ?string $adminToken, bool $quiet, int $maxWait): void
{
    $pollUrl      = "{$gatewayUrl}/v2/migrate-all/{$jobId}";
    $backoff      = 3;
    $backoffCap   = 15;
    $deadline     = time() + $maxWait;
    $transportRetries = 0;
    $maxTransportRetries = 10;
    // Whether we have already re-initiated after an `interrupted` status.
    $reinitiatedOnce = false;

    while (true) {
        sleep($backoff);

        // Check client-side deadline BEFORE the poll so we never silently hang.
        if (time() >= $deadline) {
            fwrite(STDERR, "ERROR: Client-side wait limit ({$maxWait}s) exceeded.\n");
            fwrite(STDERR, "  The server-side job is still running and can be re-polled:\n");
            fwrite(STDERR, "    GET {$gatewayUrl}/v2/migrate-all/{$jobId}\n");
            fwrite(STDERR, "  Re-run this command to attach to the in-flight job (single-flight guarantee).\n");
            exit(1);
        }

        $headers = [];
        if ($adminToken) {
            $headers[] = "Authorization: Bearer {$adminToken}";
        }

        $resp = gatewayHttpRequest('GET', $pollUrl, $headers, null, 30);

        // Transport error (connection failure or 5xx on the poll GET itself).
        if ($resp['code'] === 0 || $resp['code'] >= 500) {
            $transportRetries++;
            if ($transportRetries > $maxTransportRetries) {
                fwrite(STDERR, "ERROR: Poll transport failed {$maxTransportRetries} times in a row.\n");
                fwrite(STDERR, "  Last HTTP code: {$resp['code']}\n");
                fwrite(STDERR, "  Job id: {$jobId}\n");
                exit(1);
            }
            if (!$quiet) {
                $label = $resp['code'] === 0 ? 'poll connection failed' : "poll HTTP {$resp['code']}";
                echo "  [{$label}] retrying poll in {$backoff}s (transport retry {$transportRetries}/{$maxTransportRetries})...\n";
            }
            // Do not grow backoff on transport errors so we recover quickly.
            continue;
        }
        $transportRetries = 0; // Reset on any successful HTTP response.

        if ($resp['code'] === 404) {
            fwrite(STDERR, "ERROR: Job {$jobId} not found on gateway (HTTP 404).\n");
            exit(1);
        }

        if ($resp['code'] !== 200) {
            fwrite(STDERR, "ERROR: Unexpected HTTP {$resp['code']} while polling job {$jobId}\n");
            if ($resp['body']) fwrite(STDERR, "  {$resp['body']}\n");
            exit(1);
        }

        $job = json_decode($resp['body'], true) ?? [];
        $status = $job['status'] ?? 'unknown';

        switch ($status) {
            case 'completed':
                if (!$quiet) {
                    echo "\n  Job {$jobId} completed.\n";
                }
                printMigrateAllSummary($job, $quiet);
                return;

            case 'failed':
            case 'partial':
                if (!$quiet) {
                    echo "\n  Job {$jobId} terminal status: {$status}.\n";
                }
                printMigrateAllSummary($job, $quiet);
                checkMigrateAllFailures($job, $quiet);
                // checkMigrateAllFailures always exits for failed/partial.
                exit(1);

            case 'interrupted':
                // Gateway restarted mid-run. Re-initiate once and follow the new job.
                if ($reinitiatedOnce) {
                    fwrite(STDERR, "ERROR: Job was interrupted twice — aborting to avoid loops.\n");
                    fwrite(STDERR, "  Last job_id: {$jobId}\n");
                    exit(1);
                }
                $reinitiatedOnce = true;
                if (!$quiet) {
                    echo "  Job {$jobId} was interrupted (gateway restart). Re-initiating...\n";
                }
                $reResp = gatewayInitiateMigrateAll($gatewayUrl, $initiateBody, $adminToken, 3, 5, $quiet);
                if ($reResp['code'] !== 202) {
                    fwrite(STDERR, "ERROR: Re-initiate after interrupt returned HTTP {$reResp['code']}\n");
                    if ($reResp['body']) fwrite(STDERR, "  {$reResp['body']}\n");
                    exit(1);
                }
                $reInit = json_decode($reResp['body'], true) ?? [];
                $jobId  = $reInit['job_id'] ?? null;
                if (!$jobId) {
                    fwrite(STDERR, "ERROR: Re-initiate returned 202 but no job_id\n");
                    exit(1);
                }
                $pollUrl = "{$gatewayUrl}/v2/migrate-all/{$jobId}";
                if (!$quiet) {
                    echo "  Resumed as job {$jobId}\n";
                }
                // Reset backoff for the fresh job.
                $backoff = 3;
                continue 2; // continue the outer while loop

            case 'queued':
            case 'running':
                $succeeded = $job['succeeded'] ?? 0;
                $total     = $job['total_databases'] ?? '?';
                if (!$quiet) {
                    echo "  migrated {$succeeded}/{$total} databases… (status={$status})\n";
                }
                // Grow backoff, cap at $backoffCap.
                $backoff = (int) min((int) round($backoff * 1.5), $backoffCap);
                break;

            default:
                if (!$quiet) {
                    echo "  Unknown job status '{$status}' — continuing to poll...\n";
                }
                $backoff = (int) min((int) round($backoff * 1.5), $backoffCap);
                break;
        }
    }
}

/**
 * Print the migrate-all summary from a terminal job/response object.
 *
 * Handles both the async job shape (§8.2.2) and the legacy sync shape (§8.1),
 * computing per-database totals from results[] when top-level aggregate fields
 * are absent (§8.2.6 fix).
 *
 * @internal
 */
function printMigrateAllSummary(array $response, bool $quiet): void
{
    if ($quiet) {
        return;
    }

    $status       = $response['status'] ?? 'unknown';
    $totalDbs     = $response['total_databases'] ?? null;
    $succeeded    = $response['succeeded'] ?? null;
    $failed       = $response['failed'] ?? null;
    $execMs       = $response['execution_time_ms'] ?? null;
    $results      = $response['results'] ?? [];

    // §8.2.6: derive aggregate migration/function totals from per-database results[]
    // because the gateway does not return these as top-level fields on migrate-all.
    $migrationsApplied = 0;
    $functionsUpdated  = 0;
    $failedDbs         = [];

    foreach ($results as $r) {
        $migrationsApplied += (int) ($r['migrations_applied'] ?? 0);
        $functionsUpdated  += (int) ($r['functions_updated'] ?? 0);
        if (($r['status'] ?? '') !== 'completed') {
            $failedDbs[] = $r;
        }
    }

    // Fall back to legacy top-level keys for very old sync gateways that do
    // carry them (even though §8.2.6 says they're not guaranteed).
    if ($migrationsApplied === 0 && isset($response['migrations_applied'])) {
        $migrationsApplied = (int) $response['migrations_applied'];
    }
    if ($functionsUpdated === 0 && isset($response['functions_updated'])) {
        $functionsUpdated = (int) $response['functions_updated'];
    }

    // Derive succeeded/failed counts from results[] when top-level fields absent.
    if ($succeeded === null) {
        $succeeded = count(array_filter($results, fn($r) => ($r['status'] ?? '') === 'completed'));
    }
    if ($failed === null) {
        $failed = count(array_filter($results, fn($r) => ($r['status'] ?? '') !== 'completed'));
    }
    if ($totalDbs === null) {
        $totalDbs = count($results);
    }

    echo "  Status:              {$status}\n";
    echo "  Databases total:     {$totalDbs}\n";
    echo "  Databases succeeded: {$succeeded}\n";
    echo "  Databases failed:    {$failed}\n";
    echo "  Migrations applied:  {$migrationsApplied}\n";
    echo "  Functions updated:   {$functionsUpdated}\n";
    if ($execMs !== null) {
        echo "  Execution time:      {$execMs}ms\n";
    }

    if (!empty($failedDbs)) {
        echo "\n  Failed databases:\n";
        foreach ($failedDbs as $r) {
            $dbName = $r['database'] ?? 'unknown';
            $err    = $r['error'] ?? 'no error detail';
            echo "    - {$dbName}: {$err}\n";
        }
    }
}

/**
 * Check a terminal migrate-all response/job for failures and exit non-zero if any.
 *
 * Called after printMigrateAllSummary for both the async and legacy sync paths.
 *
 * @internal
 */
function checkMigrateAllFailures(array $response, bool $quiet): void
{
    $status  = $response['status'] ?? 'unknown';
    $results = $response['results'] ?? [];

    if ($status === 'completed') {
        return; // All good.
    }

    // Collect failed databases from results[] for the error message.
    $failedDbs = [];
    foreach ($results as $r) {
        if (($r['status'] ?? '') !== 'completed') {
            $failedDbs[] = ($r['database'] ?? 'unknown') . ': ' . ($r['error'] ?? 'no error detail');
        }
    }

    $errorMsg = $response['error'] ?? null;

    fwrite(STDERR, "\nERROR: migrate-all finished with status '{$status}'\n");
    if ($errorMsg) {
        fwrite(STDERR, "  {$errorMsg}\n");
    }
    foreach ($failedDbs as $line) {
        fwrite(STDERR, "  - {$line}\n");
    }
    fwrite(STDERR, "  Inspect results above and do NOT proceed with deployment.\n");
    exit(1);
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
