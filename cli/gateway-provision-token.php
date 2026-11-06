<?php
/**
 * StoneScriptPHP CLI - Gateway Platform Token
 *
 * Issues a per-platform bearer token via POST /admin/platform-token, or replaces
 * it via POST /admin/platform-token/rotate (--rotate).
 *
 * The gateway shows a token only once and stores it hashed:
 *   - First issue: the token is printed to stdout.
 *   - The platform already has a token: nothing is printed; the command explains
 *     how to continue (use the stored token, or --rotate).
 *   - --rotate: a new token is printed and the previous one stops working at once.
 *     It is never done unless you pass the flag.
 *
 * Store the printed token as DB_GATEWAY_PLATFORM_TOKEN in your secret store.
 * The platform token is used for schema upload, database creation, migrations and
 * function calls.
 *
 * Usage:
 *   php stone gateway:provision-token [--rotate] [--quiet]
 *
 * Environment variables (required):
 *   DB_GATEWAY_URL         - Gateway URL (e.g., http://localhost:9000)
 *   PLATFORM_ID            - Platform identifier (e.g., myapp)
 *   DB_GATEWAY_ADMIN_TOKEN - Admin token (legacy: ADMIN_TOKEN)
 *
 * Options:
 *   --rotate  Replace an existing token
 *   --quiet   Print only the token on stdout (no other output). In CI, redirect
 *             stdout straight into your secret store; do not let it reach a build log.
 */

require_once __DIR__ . '/helpers/gateway-common.php';

$options = parseGatewayOptions($argv, [], ['--rotate']);
$rotate = in_array('--rotate', $argv, true);
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
    $options['quiet'],
    $rotate
);

// The token goes to stdout only (for capture into a secret store).
echo $token . "\n";

exit(0);
