<?php
/**
 * StoneScriptPHP CLI - Gateway List Schemas
 *
 * Lists all schema archives registered on the gateway for this platform.
 * Calls GET /platform/{platform}/schemas (no authentication required).
 *
 * Usage:
 *   php stone gateway:list-schemas
 *
 * Environment variables (required):
 *   DB_GATEWAY_URL  - Gateway URL (e.g., http://localhost:9000)
 *   PLATFORM_ID     - Platform identifier (e.g., myapp)
 *
 * Options:
 *   --quiet   Suppress headers; output one schema name per line (for scripting)
 */

require_once __DIR__ . '/helpers/gateway-common.php';

$options = parseGatewayOptions($argv);
$env = loadGatewayEnv($options, false, false);

if (!$options['quiet']) {
    echo "=== Gateway Schemas for '{$env['platform_id']}' ===\n";
    echo "Gateway: {$env['gateway_url']}\n\n";
}

$resp = gatewayHttpRequest(
    'GET',
    "{$env['gateway_url']}/platform/{$env['platform_id']}/schemas",
    [],
    null,
    30
);

if ($resp['code'] === 404) {
    fwrite(STDERR, "ERROR: Platform '{$env['platform_id']}' is not registered on the gateway.\n");
    exit(1);
}

if ($resp['code'] !== 200) {
    fwrite(STDERR, "ERROR: Failed to list schemas (HTTP {$resp['code']})\n");
    if ($resp['body']) fwrite(STDERR, "  {$resp['body']}\n");
    exit(1);
}

$response = json_decode($resp['body'], true);
$schemas = $response['schemas'] ?? [];

if (empty($schemas)) {
    if (!$options['quiet']) {
        echo "No schemas registered for platform '{$env['platform_id']}'.\n";
        echo "Upload a schema with: php stone gateway:register-main\n";
    }
    exit(0);
}

if ($options['quiet']) {
    foreach ($schemas as $schema) {
        echo ($schema['name'] ?? '') . "\n";
    }
} else {
    echo "Schemas: " . count($schemas) . "\n\n";
    foreach ($schemas as $schema) {
        $name       = $schema['name'] ?? '(unknown)';
        $hasTables  = ($schema['has_tables'] ?? false) ? 'yes' : 'no';
        $hasFns     = ($schema['has_functions'] ?? false) ? 'yes' : 'no';
        $hasMigs    = ($schema['has_migrations'] ?? false) ? 'yes' : 'no';
        $hasSeeders = ($schema['has_seeders'] ?? false) ? 'yes' : 'no';
        echo "  {$name}\n";
        echo "    tables={$hasTables}  functions={$hasFns}  migrations={$hasMigs}  seeders={$hasSeeders}\n";
    }
}

exit(0);
