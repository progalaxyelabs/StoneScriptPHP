<?php
/**
 * StoneScriptPHP CLI - Gateway Provision Platform Token
 *
 * Provisions (or re-provisions) a per-platform bearer token via:
 *   POST /admin/platform-token
 *
 * Gateway v4.1.0+ requires a platform token (not the admin token) for:
 *   POST /admin/database/create
 *   POST /v2/migrate
 *   POST /v2/migrate-all
 *   GET  /v2/migrate-all/{job_id}
 *
 * This command prints the token so you can store it in your .env:
 *   DB_GATEWAY_PLATFORM_TOKEN=<token>
 *
 * Re-provisioning replaces any existing platform token — any copy stored
 * elsewhere must be updated after re-provisioning.
 *
 * Usage:
 *   php stone gateway:provision-token
 *
 * Environment variables (required):
 *   DB_GATEWAY_URL         - Gateway URL (e.g., http://localhost:9000)
 *   PLATFORM_ID            - Platform identifier (e.g., myapp)
 *   DB_GATEWAY_ADMIN_TOKEN - Admin token (legacy: ADMIN_TOKEN)
 *
 * Options:
 *   --quiet   Suppress output except the token itself (for scripting)
 */

require_once __DIR__ . '/helpers/gateway-common.php';

$options = parseGatewayOptions($argv);
$env = loadGatewayEnv($options, false, false);

if (!$env['admin_token']) {
    fwrite(STDERR, "ERROR: DB_GATEWAY_ADMIN_TOKEN is required to provision a platform token.\n");
    exit(1);
}

if (!$options['quiet']) {
    echo "=== Gateway Provision Platform Token ===\n";
    echo "Platform: {$env['platform_id']}\n";
    echo "Gateway:  {$env['gateway_url']}\n\n";
}

$token = stepProvisionPlatformToken(
    $env['gateway_url'],
    $env['platform_id'],
    $env['admin_token'],
    $options['quiet']
);

// Always print the token so it can be captured by scripts / CI pipelines.
echo $token . "\n";

exit(0);
