<?php
/**
 * sub_activate() Admin/NULL-payment_id Regression Test
 *
 * Proves the fix in src/Subscriptions/Routes/PostAdminActivateRoute.php:
 * this route used to substitute the literal string 'manual' whenever no
 * real gateway payment_id was supplied. Once sub_activate() gained its
 * unique-index idempotency gate on gateway_payment_id, that sentinel
 * became a footgun — the FIRST admin/manual activation in a database
 * would insert a payment row with gateway_payment_id='manual', and every
 * SUBSEQUENT admin activation for every OTHER tenant in that database
 * would then collide on the same value, silently report
 * already_applied=true, and never actually extend the subscription.
 *
 * This test proves: TWO DIFFERENT tenants, each activated with
 * p_payment_id = NULL (the real admin-route behavior post-fix), both get
 * a genuine, independent activation — neither is starved by the other.
 *
 * TEST DATA ONLY — see test-sub-activate-concurrency.php's docblock for
 * the shared throwaway-schema convention this test also uses.
 *
 * Prerequisites / run: same env vars as test-sub-activate-concurrency.php.
 * Exit code 0 = PASS or SKIP, 1 = FAIL.
 */

declare(strict_types=1);

$dbHost = getenv('TEST_DB_HOST');
if ($dbHost === false || $dbHost === '') {
    fwrite(STDERR, "SKIP: TEST_DB_HOST not set — this test needs a real, disposable PostgreSQL instance.\n");
    exit(0);
}

$config = [
    'host' => $dbHost,
    'port' => (int) (getenv('TEST_DB_PORT') ?: 5432),
    'database' => getenv('TEST_DB_NAME') ?: 'gateway_test',
    'user' => getenv('TEST_DB_USER') ?: 'postgres',
    'password' => getenv('TEST_DB_PASSWORD') ?: '',
];

const SCHEMA = 'sub_activate_admin_null_test';
const PLATFORM_CODE = 'test_platform';

function fail(string $msg): void
{
    fwrite(STDERR, "FAIL: $msg\n");
    exit(1);
}

echo "=== sub_activate() Admin/NULL-payment_id Regression Test ===\n";

try {
    $db = new PDO(
        sprintf('pgsql:host=%s;port=%d;dbname=%s', $config['host'], $config['port'], $config['database']),
        $config['user'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "SKIP: cannot reach test database ({$e->getMessage()}).\n");
    exit(0);
}

echo "[1/3] Provisioning throwaway schema '" . SCHEMA . "'\n";
$db->exec('DROP SCHEMA IF EXISTS ' . SCHEMA . ' CASCADE');
$db->exec('CREATE SCHEMA ' . SCHEMA);
$db->exec('SET search_path TO ' . SCHEMA . ', public');

$root = dirname(__DIR__, 2);
$db->exec(file_get_contents($root . '/src/Subscriptions/Schema/tables/sub_011_subscriptions.pgsql'));
$db->exec(file_get_contents($root . '/src/Subscriptions/Schema/tables/sub_012_subscription_payments.pgsql'));
$db->exec(file_get_contents($root . '/src/Subscriptions/Schema/functions/sub_activate.pgsql'));
echo "  ✓ schema deployed\n\n";

echo "[2/3] Activating tenant-A then tenant-B, both with p_payment_id = NULL (admin/manual activation)\n";

$stmt = $db->prepare(
    "SELECT sub_activate(:platform, :tenant, 'annual', 365, NULL, 'a@example.test', NULL, 0, NULL, NULL) AS result"
);
$stmt->execute(['platform' => PLATFORM_CODE, 'tenant' => 'admin-null-tenant-a']);
$resultA = json_decode($stmt->fetch()['result'], true);

$stmt->execute(['platform' => PLATFORM_CODE, 'tenant' => 'admin-null-tenant-b']);
$resultB = json_decode($stmt->fetch()['result'], true);

echo '  tenant-A: ' . json_encode($resultA) . "\n";
echo '  tenant-B: ' . json_encode($resultB) . "\n\n";

echo "[3/3] Asserting both tenants got a genuine, independent activation\n";

if (($resultA['already_applied'] ?? null) !== false) {
    fail("tenant-A: expected already_applied=false (first-ever activation), got " . json_encode($resultA['already_applied'] ?? null));
}
if ($resultA['status'] !== 'active') {
    fail("tenant-A: expected status=active, got {$resultA['status']}");
}
echo "  ✓ tenant-A activated: already_applied=false, status=active\n";

// This is the exact regression: with the old 'manual' sentinel + the new
// unique index, tenant-B's activation would collide on
// gateway_payment_id='manual' (shared with tenant-A) and silently report
// already_applied=true instead of actually activating.
if (($resultB['already_applied'] ?? null) !== false) {
    fail("tenant-B: expected already_applied=false (independent first-ever activation), got " . json_encode($resultB['already_applied'] ?? null) . " — THIS IS THE 'manual' SENTINEL REGRESSION: tenant-B was starved by tenant-A's earlier NULL-payment activation");
}
if ($resultB['status'] !== 'active') {
    fail("tenant-B: expected status=active, got {$resultB['status']}");
}
echo "  ✓ tenant-B activated independently: already_applied=false, status=active (not starved by tenant-A)\n\n";

// Also verify no gateway_payment_id='manual' (or NULL-colliding) row exists —
// NULL payment_ids must never be recorded as if they were a real gateway id.
$manualRows = (int) $db->query(
    "SELECT COUNT(*) AS c FROM subscription_payments WHERE gateway_payment_id = 'manual'"
)->fetch()['c'];
if ($manualRows > 0) {
    fail("found $manualRows subscription_payments row(s) with gateway_payment_id='manual' — the sentinel regression is back");
}
echo "  ✓ no gateway_payment_id='manual' sentinel rows recorded\n\n";

echo "[cleanup] Dropping throwaway schema\n";
$db->exec('DROP SCHEMA IF EXISTS ' . SCHEMA . ' CASCADE');

echo "=== PASS: two different tenants activated with p_payment_id=NULL are both genuinely activated, independently ===\n";
exit(0);
