<?php
/**
 * sub_activate() Concurrency Test — real Postgres, real concurrent processes
 *
 * Proves the fix in src/Subscriptions/Schema/functions/sub_activate.pgsql
 * and src/Subscriptions/Schema/tables/sub_012_subscription_payments.pgsql:
 * N truly-concurrent calls to sub_activate() with the SAME gateway payment
 * id — including the very FIRST call, which must also create the
 * subscription row from scratch under the same race — must produce exactly
 * ONE subscription row, exactly ONE subscription_payments row, and extend
 * the expiry exactly ONCE. Never a double-apply, never a lost activation.
 *
 * This mirrors the manual-PDO-script convention already used by this
 * directory (test-integration.php, test-connection-pool.php): a real
 * PostgreSQL connection, no mocking, run with `php <this file>`.
 *
 * TEST DATA ONLY — never run against a real tenant database. This script
 * creates its own throwaway schema (dropped at start and end of the run)
 * inside a scratch test database and only ever writes rows it created
 * itself. It is not a migration and is never applied to a production DB.
 *
 * Concurrency mechanism: pcntl_fork() — N real child PROCESSES, each with
 * its own PDO connection, synchronized on a shared file-based barrier so
 * they all fire sub_activate() as close to simultaneously as the OS
 * scheduler allows. This is what actually exercises Postgres row-locking
 * (SELECT ... FOR UPDATE) and the unique-index ON CONFLICT gate — a
 * sequential loop in one process would never expose the race.
 *
 * Prerequisites (all via env vars — no defaults are hardcoded on purpose;
 * this is a public package and must not ship an internal host/credential):
 *   TEST_DB_HOST, TEST_DB_PORT, TEST_DB_NAME, TEST_DB_USER, TEST_DB_PASSWORD
 *   pcntl extension (bundled with the CLI SAPI on most dev workstations)
 * If TEST_DB_HOST is unset, this test SKIPs (exit 0) rather than failing —
 * it needs a real disposable Postgres, which CI/local dev must opt into.
 *
 * Run with:
 *   TEST_DB_HOST=... TEST_DB_USER=... TEST_DB_PASSWORD=... \
 *     php tests/Integration/test-sub-activate-concurrency.php
 *
 * Exit code 0 = PASS or SKIP, 1 = FAIL.
 */

declare(strict_types=1);

if (!extension_loaded('pcntl')) {
    fwrite(STDERR, "SKIP: pcntl extension not available — cannot run a true concurrency test.\n");
    exit(0);
}

$dbHost = getenv('TEST_DB_HOST');
if ($dbHost === false || $dbHost === '') {
    fwrite(STDERR, "SKIP: TEST_DB_HOST not set — this test needs a real, disposable PostgreSQL instance.\n");
    fwrite(STDERR, "      Set TEST_DB_HOST/TEST_DB_PORT/TEST_DB_NAME/TEST_DB_USER/TEST_DB_PASSWORD to run it.\n");
    exit(0);
}

$config = [
    'host' => $dbHost,
    'port' => (int) (getenv('TEST_DB_PORT') ?: 5432),
    'database' => getenv('TEST_DB_NAME') ?: 'gateway_test',
    'user' => getenv('TEST_DB_USER') ?: 'postgres',
    'password' => getenv('TEST_DB_PASSWORD') ?: '',
];

const SCHEMA = 'sub_activate_concurrency_test';
const CONCURRENCY = 15; // matches the discriminating count cited in sub_activate.pgsql's docblock
const PLATFORM_CODE = 'test_platform';
const TENANT_ID = 'test-tenant-concurrency';
const PAYMENT_ID = 'test-pay-concurrency-race-001';
const CHILD_TIMEOUT_SECONDS = 20; // overall watchdog — a real deadlock must FAIL, never hang forever

function connect(array $cfg): PDO
{
    $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $cfg['host'], $cfg['port'], $cfg['database']);
    return new PDO($dsn, $cfg['user'], $cfg['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function fail(string $msg): void
{
    fwrite(STDERR, "FAIL: $msg\n");
    exit(1);
}

echo "=== sub_activate() Concurrency Test ===\n";
echo "Target: {$config['host']}:{$config['port']}/{$config['database']}\n\n";

try {
    $db = connect($config);
} catch (PDOException $e) {
    fwrite(STDERR, "SKIP: cannot reach test database ({$e->getMessage()}) — test environment not available.\n");
    exit(0);
}

// --- 1. Fresh throwaway schema (never touches any real tenant schema) ---
echo "[1/5] Provisioning throwaway schema '" . SCHEMA . "'\n";
$db->exec('DROP SCHEMA IF EXISTS ' . SCHEMA . ' CASCADE');
$db->exec('CREATE SCHEMA ' . SCHEMA);
$db->exec('SET search_path TO ' . SCHEMA . ', public');

$root = dirname(__DIR__, 2);
$tablesSql = file_get_contents($root . '/src/Subscriptions/Schema/tables/sub_011_subscriptions.pgsql');
$paymentsSql = file_get_contents($root . '/src/Subscriptions/Schema/tables/sub_012_subscription_payments.pgsql');
$functionSql = file_get_contents($root . '/src/Subscriptions/Schema/functions/sub_activate.pgsql');
if ($tablesSql === false || $paymentsSql === false || $functionSql === false) {
    fail('could not read schema source files from ' . $root);
}

$db->exec($tablesSql);
$db->exec($paymentsSql); // includes the duplicate-data guard — a no-op here since the schema is fresh
$db->exec($functionSql);
echo "  ✓ subscriptions, subscription_payments, sub_activate() deployed into test schema\n\n";

// --- 2. NO baseline subscription seeded on purpose ---
// The subscription row does not exist yet. This deliberately exercises
// defect #1's fix (the ensure-insert `INSERT ... ON CONFLICT DO NOTHING`)
// UNDER the same concurrent race as defect #2 — CONCURRENCY processes all
// racing to CREATE the subscription row for the first time, not just to
// extend an existing one. A test that pre-seeds the subscription (as an
// earlier draft of this test did) never proves the create-from-scratch
// path is race-free.
echo "[2/5] No baseline subscription seeded — the concurrent calls below must create it themselves\n\n";

// --- 3. Fire CONCURRENCY real child processes at sub_activate() with the SAME payment_id ---
echo '[3/5] Firing ' . CONCURRENCY . " concurrent processes at sub_activate() with payment_id='" . PAYMENT_ID . "'\n";

$barrierFile = sys_get_temp_dir() . '/sub_activate_concurrency_barrier_' . getmypid();
$readyDir = sys_get_temp_dir() . '/sub_activate_concurrency_ready_' . getmypid();
@mkdir($readyDir);
@unlink($barrierFile);

// Close the parent's connection before forking — an open PDO/SSL socket
// gets duplicated into every child's fd table, and a child's exit()
// closing its copy can tear down the shared TLS session and break the
// parent's connection ("SSL error: unexpected eof"). Reconnect fresh after
// all children are done.
$db = null;

$children = [];
for ($i = 0; $i < CONCURRENCY; $i++) {
    $pid = pcntl_fork();
    if ($pid === -1) {
        fail('pcntl_fork failed');
    }
    if ($pid === 0) {
        // Child process: connect, signal ready, wait for the barrier, then fire.
        try {
            $childDb = connect($config);
            $childDb->exec('SET search_path TO ' . SCHEMA . ', public');
            // Match the framework's assumed default isolation level — see
            // sub_activate.pgsql's v_sub IS NULL guard docblock for why
            // this function is only race-free-by-construction under
            // READ COMMITTED.
            $childDb->exec('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL READ COMMITTED');

            touch($readyDir . '/' . getmypid());

            // Wait for the parent's go-signal (barrier file), max 5s.
            $waited = 0;
            while (!file_exists($barrierFile) && $waited < 5_000_000) {
                usleep(1000);
                $waited += 1000;
            }

            $stmt = $childDb->prepare(
                "SELECT sub_activate(:platform, :tenant, 'paid_monthly', 30, :payment_id,
                                      'payer@example.test', NULL, 49900, 'card', NULL) AS result"
            );
            $stmt->execute([
                'platform' => PLATFORM_CODE,
                'tenant' => TENANT_ID,
                'payment_id' => PAYMENT_ID,
            ]);
            $row = $stmt->fetch();
            file_put_contents($readyDir . '/result_' . getmypid() . '.json', $row['result']);
        } catch (Throwable $e) {
            file_put_contents($readyDir . '/error_' . getmypid() . '.txt', $e->getMessage());
        }
        exit(0);
    }
    $children[] = $pid;
}

// Parent: wait until all children report ready, then release the barrier.
$deadline = time() + 10;
while (true) {
    $trueReady = count(array_filter(glob($readyDir . '/*') ?: [], fn($f) => !str_contains($f, 'result_') && !str_contains($f, 'error_')));
    if ($trueReady >= CONCURRENCY || time() > $deadline) {
        break;
    }
    usleep(2000);
}
touch($barrierFile); // release all children simultaneously

// Bounded wait per child (WNOHANG polling), not a blocking pcntl_waitpid —
// a real deadlock in the fix under test must FAIL this test, never hang
// the test runner forever.
$pending = $children;
$watchdogDeadline = time() + CHILD_TIMEOUT_SECONDS;
$exitStatuses = [];
while (!empty($pending) && time() < $watchdogDeadline) {
    foreach ($pending as $idx => $pid) {
        $res = pcntl_waitpid($pid, $status, WNOHANG);
        if ($res === $pid) {
            $exitStatuses[$pid] = $status;
            unset($pending[$idx]);
        }
    }
    if (!empty($pending)) {
        usleep(20_000);
    }
}

if (!empty($pending)) {
    foreach ($pending as $pid) {
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
    }
    @unlink($barrierFile);
    array_map('unlink', glob($readyDir . '/*') ?: []);
    @rmdir($readyDir);
    fail(count($pending) . ' of ' . CONCURRENCY . ' child processes did not finish within ' . CHILD_TIMEOUT_SECONDS . 's — likely a deadlock in the locking/ON CONFLICT logic under test (killed and cleaned up)');
}

// Detect children that died abnormally (signal-killed, e.g. a Postgres
// deadlock-victim termination propagating as a fatal error that somehow
// didn't get written to the error file) — this must not be silently
// treated as "completed without error".
$abnormal = [];
foreach ($exitStatuses as $pid => $status) {
    if (pcntl_wifsignaled($status)) {
        $abnormal[] = "pid=$pid killed by signal " . pcntl_wtermsig($status);
    } elseif (pcntl_wifexited($status) && pcntl_wexitstatus($status) !== 0) {
        $abnormal[] = "pid=$pid exited with code " . pcntl_wexitstatus($status);
    }
}
if (!empty($abnormal)) {
    fail("abnormal child exits:\n  " . implode("\n  ", $abnormal));
}

$errors = glob($readyDir . '/error_*.txt') ?: [];
if (!empty($errors)) {
    foreach ($errors as $ef) {
        fwrite(STDERR, "  child error: " . file_get_contents($ef) . "\n");
    }
    fail(count($errors) . ' of ' . CONCURRENCY . ' concurrent sub_activate() calls raised an error (see above)');
}

$resultFiles = glob($readyDir . '/result_*.json') ?: [];
$results = array_map(fn($rf) => file_get_contents($rf), $resultFiles);

// Every child that exited cleanly with no error file MUST have written a
// result — a child that silently produced neither (e.g. killed by a signal
// PHP itself doesn't surface as an exception) must not let this test
// report a smaller-than-expected count as a pass.
if (count($results) !== CONCURRENCY) {
    fail('expected ' . CONCURRENCY . ' results, got ' . count($results) . ' (' . count($errors) . ' errors, ' . count($abnormal) . ' abnormal exits — some child produced neither a result nor a recorded error)');
}
echo '  ✓ all ' . CONCURRENCY . ' concurrent calls completed cleanly with a result (0 errors, 0 abnormal exits)' . "\n\n";

// cleanup barrier files (results already read into memory above)
@unlink($barrierFile);
array_map('unlink', glob($readyDir . '/*') ?: []);
@rmdir($readyDir);

// --- 4. Assert exactly ONE subscription + ONE payment row + ONE expiry extension ---
echo "[4/5] Asserting single-application outcome\n";

$db = connect($config);
$db->exec('SET search_path TO ' . SCHEMA . ', public');

$subCount = (int) $db->query(
    "SELECT COUNT(*) AS c FROM subscriptions WHERE platform_code = '" . PLATFORM_CODE . "' AND tenant_id = '" . TENANT_ID . "'"
)->fetch()['c'];
if ($subCount !== 1) {
    fail("expected exactly 1 subscriptions row for platform_code/tenant_id, found $subCount — the create-from-scratch race (defect #1's fix) regressed under concurrency");
}
echo "  ✓ exactly 1 subscriptions row created (not " . CONCURRENCY . ") despite " . CONCURRENCY . " concurrent first-time activations\n";

$paymentCount = (int) $db->query(
    "SELECT COUNT(*) AS c FROM subscription_payments WHERE gateway_payment_id = '" . PAYMENT_ID . "'"
)->fetch()['c'];

if ($paymentCount !== 1) {
    fail("expected exactly 1 subscription_payments row for payment_id='" . PAYMENT_ID . "', found $paymentCount — the double-apply race regressed");
}
echo "  ✓ exactly 1 subscription_payments row for the payment_id (not " . CONCURRENCY . ")\n";

$appliedCount = 0;
foreach ($results as $json) {
    $decoded = json_decode($json, true);
    if (($decoded['already_applied'] ?? null) === false) {
        $appliedCount++;
    }
}
if ($appliedCount !== 1) {
    fail("expected exactly 1 of " . CONCURRENCY . " calls to report already_applied=false (the winner), found $appliedCount");
}
echo "  ✓ exactly 1 of " . CONCURRENCY . " calls won the idempotency gate (already_applied=false); the other " . (CONCURRENCY - 1) . " correctly reported already_applied=true\n";

$sub = $db->query(
    "SELECT expires_at, status, plan_code FROM subscriptions WHERE platform_code = '" . PLATFORM_CODE . "' AND tenant_id = '" . TENANT_ID . "'"
)->fetch();
if ($sub === false) {
    fail('subscription row missing after concurrent activation');
}
if ($sub['status'] !== 'active' || $sub['plan_code'] !== 'paid_monthly') {
    fail("subscription not activated correctly: status={$sub['status']} plan_code={$sub['plan_code']}");
}

// Expiry should reflect exactly ONE 30-day extension from NOW() (no
// pre-seeded baseline this time — see step 2), not CONCURRENCY stacked
// extensions. Allow a small tolerance for test wall-clock drift.
$expiresAt = new DateTime($sub['expires_at']);
$now = new DateTime('now');
$daysAhead = ($expiresAt->getTimestamp() - $now->getTimestamp()) / 86400;
if ($daysAhead < 28 || $daysAhead > 32) {
    fail("expiry extended incorrectly: expected ~30 days ahead, found " . round($daysAhead, 2) . " days — indicates a double/triple-apply of the expiry extension");
}
echo '  ✓ subscription expiry extended by exactly one 30-day period (~' . round($daysAhead, 1) . " days ahead), not stacked " . CONCURRENCY . "x\n\n";

// --- 5. Cleanup ---
echo "[5/5] Cleaning up throwaway schema\n";
$db->exec('DROP SCHEMA IF EXISTS ' . SCHEMA . ' CASCADE');
echo "  ✓ schema dropped\n\n";

echo "=== PASS: " . CONCURRENCY . " concurrent first-time sub_activate() calls with the same payment_id produced exactly 1 subscription + 1 payment row + 1 expiry extension ===\n";
exit(0);
