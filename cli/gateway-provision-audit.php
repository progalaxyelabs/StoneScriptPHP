<?php
/**
 * StoneScriptPHP CLI - Gateway Provision Audit Database
 *
 * One-time-per-platform provisioning of the SIMPLE separate audit-DB pattern
 * (gateway v4.5.0+ required):
 *
 *   POST /admin/audit/provision
 *
 * Creates {platform}_audit, owned by the gateway's own gateway_audit_user
 * role (NOT this platform's runtime DB role) with a fixed schema
 * (audit_log table + audit_append/audit_read functions — no update/delete
 * function is ever installed, which is the actual tamper-resistance
 * mechanism; see StoneScriptPHP\Audit\AuditRecorder's class docblock).
 *
 * This does NOT replace gateway:migrate-main and is not part of it — the
 * audit database is deliberately outside the normal per-platform schema
 * pipeline (see stonescriptdb-gateway's src/audit/mod.rs module doc for
 * why). Run this ONCE per platform, then set AUDIT_TRAIL_ENABLED=true in
 * this platform's own .env to start writing records.
 *
 * Prerequisite (the gateway OPERATOR's one-time setup, not this platform's
 * concern): gateway_audit_user role created + AUDIT_DATABASE_URL configured
 * on the gateway itself. If that's missing, this command fails loud with a
 * clear message rather than silently doing nothing.
 *
 * Usage:
 *   php stone gateway:provision-audit
 *
 * Environment variables (required):
 *   DB_GATEWAY_URL         - Gateway URL (e.g., http://localhost:9000)
 *   PLATFORM_ID            - Platform identifier (e.g., myapp)
 *   DB_GATEWAY_ADMIN_TOKEN - Admin token (legacy: ADMIN_TOKEN)
 *
 * Options:
 *   --quiet   Suppress output except the resulting database name (for scripting)
 */

require_once __DIR__ . '/helpers/gateway-common.php';

$options = parseGatewayOptions($argv);
$env = loadGatewayEnv($options, false, false);

if (!$env['admin_token']) {
    fwrite(STDERR, "ERROR: DB_GATEWAY_ADMIN_TOKEN is required to provision the audit database.\n");
    exit(1);
}

if (!$options['quiet']) {
    echo "=== Gateway Provision Audit Database ===\n";
    echo "Platform: {$env['platform_id']}\n";
    echo "Gateway:  {$env['gateway_url']}\n\n";
}

$dbName = stepProvisionAudit(
    $env['gateway_url'],
    $env['platform_id'],
    $env['admin_token'],
    $options['quiet']
);

echo $dbName . "\n";

exit(0);
