<?php
/**
 * StoneScriptPHP CLI - Gateway List Databases
 *
 * Lists all tenant and main databases registered on the gateway for this platform.
 * Calls GET /platform/{platform}/databases (no authentication required).
 *
 * Usage:
 *   php stone gateway:list-databases [--schema=<name>]
 *
 * Environment variables (required):
 *   DB_GATEWAY_URL  - Gateway URL (e.g., http://localhost:9000)
 *   PLATFORM_ID     - Platform identifier (e.g., myapp)
 *
 * Options:
 *   --schema=<name>  Filter by schema name (e.g., --schema=tenant)
 *   --quiet          Suppress headers; output one database name per line (for scripting)
 */

require_once __DIR__ . '/helpers/gateway-common.php';

$options = parseGatewayOptions($argv);
$env = loadGatewayEnv($options, false, false);

// Parse optional --schema=<name> filter
$schemaFilter = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--schema=') === 0) {
        $schemaFilter = substr($arg, 9);
        break;
    }
}

if (!$options['quiet']) {
    echo "=== Gateway Databases for '{$env['platform_id']}' ===\n";
    if ($schemaFilter) {
        echo "Filter: schema={$schemaFilter}\n";
    }
    echo "Gateway: {$env['gateway_url']}\n\n";
}

$url = "{$env['gateway_url']}/platform/{$env['platform_id']}/databases";
if ($schemaFilter) {
    $url .= '?schema=' . urlencode($schemaFilter);
}

$resp = gatewayHttpRequest('GET', $url, [], null, 30);

if ($resp['code'] === 404) {
    fwrite(STDERR, "ERROR: Platform '{$env['platform_id']}' is not registered on the gateway.\n");
    exit(1);
}

if ($resp['code'] !== 200) {
    fwrite(STDERR, "ERROR: Failed to list databases (HTTP {$resp['code']})\n");
    if ($resp['body']) fwrite(STDERR, "  {$resp['body']}\n");
    exit(1);
}

$response = json_decode($resp['body'], true);
$databases = $response['databases'] ?? [];

if (empty($databases)) {
    if (!$options['quiet']) {
        echo "No databases found for platform '{$env['platform_id']}'";
        if ($schemaFilter) echo " with schema '{$schemaFilter}'";
        echo ".\n";
    }
    exit(0);
}

if ($options['quiet']) {
    foreach ($databases as $db) {
        echo ($db['database_name'] ?? '') . "\n";
    }
} else {
    echo "Databases: " . count($databases) . "\n\n";
    foreach ($databases as $db) {
        $name       = $db['database_name'] ?? '(unknown)';
        $id         = $db['id'] ?? '';
        $schemaName = $db['schema_name'] ?? '';
        $createdAt  = $db['created_at'] ?? '';
        echo "  {$name}\n";
        echo "    id={$id}  schema={$schemaName}  created={$createdAt}\n";
    }
}

exit(0);
