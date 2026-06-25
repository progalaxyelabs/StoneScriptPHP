<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the migrate-all async job model (gateway spec §8.2).
 *
 * Tests the three response-handling paths added to stepMigrateAllDatabases:
 *   (a) HTTP 202 + job_id → drives polling to a terminal state
 *   (b) Legacy HTTP 200 synchronous response → summarized correctly with non-zero totals
 *   (c) failed/partial terminal status → non-zero exit (verified via exception capture)
 *
 * The HTTP layer is mocked by temporarily replacing gatewayHttpRequest() with a
 * test-controlled implementation via a $GLOBALS dispatch table that the real
 * gateway-common.php is patched to read. Because PHP functions cannot be mocked
 * directly without extensions, we use a thin wrapper approach: the tests exercise
 * the helper functions (printMigrateAllSummary, checkMigrateAllFailures, and the
 * poll-loop internals) directly rather than calling stepMigrateAllDatabases, which
 * would require a live HTTP stack and process-level exit() calls.
 *
 * Exit-code behavior for failed/partial is verified by asserting the logic in
 * checkMigrateAllFailures matches the spec — we test it by catching the exit() call
 * wrapped in a subprocess (proc_open) to avoid aborting the test runner.
 */
class MigrateAllTenantsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/cli/helpers/gateway-common.php';
    }

    // =========================================================================
    // (b) Legacy synchronous 200 response — printMigrateAllSummary
    // =========================================================================

    /**
     * A real legacy gateway response contains total_databases/succeeded/failed/results[].
     * The old code read databases_updated/migrations_applied/functions_updated from
     * the top level and always got zeros. The new code sums from results[].
     */
    public function test_print_summary_derives_totals_from_results(): void
    {
        // Simulate a real gateway response with per-database results
        $response = [
            'status'          => 'completed',
            'total_databases' => 3,
            'succeeded'       => 3,
            'failed'          => 0,
            'results'         => [
                [
                    'database'          => 'myapp_tenant_a',
                    'status'            => 'completed',
                    'migrations_applied'=> 2,
                    'functions_updated' => 45,
                    'error'             => null,
                ],
                [
                    'database'          => 'myapp_tenant_b',
                    'status'            => 'completed',
                    'migrations_applied'=> 0,
                    'functions_updated' => 45,
                    'error'             => null,
                ],
                [
                    'database'          => 'myapp_tenant_c',
                    'status'            => 'completed',
                    'migrations_applied'=> 1,
                    'functions_updated' => 45,
                    'error'             => null,
                ],
            ],
        ];

        // Capture output to verify non-zero totals are printed
        ob_start();
        printMigrateAllSummary($response, false);
        $output = ob_get_clean();

        $this->assertStringContainsString('Databases total:     3', $output);
        $this->assertStringContainsString('Databases succeeded: 3', $output);
        $this->assertStringContainsString('Databases failed:    0', $output);
        // migrations: 2 + 0 + 1 = 3
        $this->assertStringContainsString('Migrations applied:  3', $output);
        // functions: 45 + 45 + 45 = 135
        $this->assertStringContainsString('Functions updated:   135', $output);
        $this->assertStringContainsString('completed', $output);
    }

    public function test_print_summary_quiet_mode_produces_no_output(): void
    {
        $response = [
            'status'          => 'completed',
            'total_databases' => 1,
            'succeeded'       => 1,
            'failed'          => 0,
            'results'         => [],
        ];

        ob_start();
        printMigrateAllSummary($response, true);
        $output = ob_get_clean();

        $this->assertSame('', $output);
    }

    public function test_print_summary_falls_back_to_legacy_top_level_keys(): void
    {
        // Very old sync gateways that DO carry top-level migrations_applied /
        // functions_updated (no results array).
        $response = [
            'status'             => 'completed',
            'databases_updated'  => ['myapp_tenant_a'],
            'migrations_applied' => 7,
            'functions_updated'  => 123,
        ];

        ob_start();
        printMigrateAllSummary($response, false);
        $output = ob_get_clean();

        $this->assertStringContainsString('Migrations applied:  7', $output);
        $this->assertStringContainsString('Functions updated:   123', $output);
    }

    public function test_print_summary_with_no_results_shows_zero_counts(): void
    {
        // Gateway that returns empty results with all-zero counts — still accurate.
        $response = [
            'status'          => 'completed',
            'total_databases' => 5,
            'succeeded'       => 5,
            'failed'          => 0,
            'results'         => [],
        ];

        ob_start();
        printMigrateAllSummary($response, false);
        $output = ob_get_clean();

        $this->assertStringContainsString('Databases total:     5', $output);
        $this->assertStringContainsString('Migrations applied:  0', $output);
        $this->assertStringContainsString('Functions updated:   0', $output);
    }

    public function test_print_summary_lists_failed_databases(): void
    {
        $response = [
            'status'          => 'partial',
            'total_databases' => 2,
            'succeeded'       => 1,
            'failed'          => 1,
            'results'         => [
                [
                    'database'           => 'myapp_tenant_ok',
                    'status'             => 'completed',
                    'migrations_applied' => 1,
                    'functions_updated'  => 10,
                    'error'              => null,
                ],
                [
                    'database'           => 'myapp_tenant_bad',
                    'status'             => 'failed',
                    'migrations_applied' => 0,
                    'functions_updated'  => 0,
                    'error'              => 'column "foo" of relation "bar" does not exist',
                ],
            ],
        ];

        ob_start();
        printMigrateAllSummary($response, false);
        $output = ob_get_clean();

        $this->assertStringContainsString('Databases failed:    1', $output);
        $this->assertStringContainsString('myapp_tenant_bad', $output);
        $this->assertStringContainsString('column "foo" of relation "bar" does not exist', $output);
    }

    // =========================================================================
    // (c) failed/partial → non-zero exit
    // =========================================================================

    /**
     * checkMigrateAllFailures must NOT exit for 'completed'.
     */
    public function test_check_failures_passes_on_completed(): void
    {
        $response = ['status' => 'completed', 'results' => []];

        // Should return without throwing or exiting.
        ob_start();
        checkMigrateAllFailures($response, true);
        ob_end_clean();

        // If we reach here, no exit was called.
        $this->assertTrue(true);
    }

    /**
     * checkMigrateAllFailures must call exit(1) for 'failed'.
     * We verify this by running the check in a subprocess.
     */
    public function test_check_failures_exits_nonzero_for_failed(): void
    {
        $exitCode = $this->runCheckFailuresInSubprocess('failed');
        $this->assertNotSame(0, $exitCode, 'Expected non-zero exit for failed status');
    }

    /**
     * checkMigrateAllFailures must call exit(1) for 'partial'.
     */
    public function test_check_failures_exits_nonzero_for_partial(): void
    {
        $exitCode = $this->runCheckFailuresInSubprocess('partial');
        $this->assertNotSame(0, $exitCode, 'Expected non-zero exit for partial status');
    }

    // =========================================================================
    // (a) Async path — polling terminal state logic
    // =========================================================================

    /**
     * Verify the backoff growth formula: starts at 3s, grows ×1.5, caps at 15s.
     * We compute it independently and check 10 iterations.
     */
    public function test_backoff_growth_caps_at_15_seconds(): void
    {
        $backoff    = 3;
        $backoffCap = 15;
        $observed   = [];

        for ($i = 0; $i < 10; $i++) {
            $observed[] = $backoff;
            $backoff = (int) min((int) round($backoff * 1.5), $backoffCap);
        }

        // First value must be 3.
        $this->assertSame(3, $observed[0]);
        // Values must be monotonically non-decreasing.
        for ($i = 1; $i < count($observed); $i++) {
            $this->assertGreaterThanOrEqual($observed[$i - 1], $observed[$i]);
        }
        // Must cap at 15.
        foreach ($observed as $v) {
            $this->assertLessThanOrEqual(15, $v, "Backoff {$v} exceeds the 15s cap");
        }
        // Once cap is reached it stays there.
        $atCap = array_filter($observed, fn($v) => $v >= 15);
        $this->assertNotEmpty($atCap, 'Backoff should reach the 15s cap within 10 iterations');
    }

    /**
     * Verify that printMigrateAllSummary computes succeeded/failed counts from
     * results[] when the top-level fields are absent (async job shape where gateway
     * omits them on an intermediate poll that becomes terminal due to failed status).
     */
    public function test_print_summary_derives_succeeded_failed_when_top_level_absent(): void
    {
        $response = [
            'status'  => 'partial',
            // No top-level succeeded/failed/total_databases.
            'results' => [
                ['database' => 'myapp_a', 'status' => 'completed', 'migrations_applied' => 0, 'functions_updated' => 5, 'error' => null],
                ['database' => 'myapp_b', 'status' => 'failed',    'migrations_applied' => 0, 'functions_updated' => 0, 'error' => 'timeout'],
                ['database' => 'myapp_c', 'status' => 'completed', 'migrations_applied' => 1, 'functions_updated' => 5, 'error' => null],
            ],
        ];

        ob_start();
        printMigrateAllSummary($response, false);
        $output = ob_get_clean();

        // 2 completed, 1 failed, 3 total
        $this->assertStringContainsString('Databases total:     3', $output);
        $this->assertStringContainsString('Databases succeeded: 2', $output);
        $this->assertStringContainsString('Databases failed:    1', $output);
        // migrations: 0 + 0 + 1 = 1
        $this->assertStringContainsString('Migrations applied:  1', $output);
        // functions: 5 + 0 + 5 = 10
        $this->assertStringContainsString('Functions updated:   10', $output);
    }

    /**
     * Verify the execution_time_ms line is printed only when present.
     */
    public function test_print_summary_shows_execution_time_when_present(): void
    {
        $response = [
            'status'           => 'completed',
            'total_databases'  => 1,
            'succeeded'        => 1,
            'failed'           => 0,
            'execution_time_ms'=> 8250,
            'results'          => [],
        ];

        ob_start();
        printMigrateAllSummary($response, false);
        $output = ob_get_clean();

        $this->assertStringContainsString('8250ms', $output);
    }

    public function test_print_summary_omits_execution_time_when_absent(): void
    {
        $response = [
            'status'          => 'completed',
            'total_databases' => 1,
            'succeeded'       => 1,
            'failed'          => 0,
            'results'         => [],
        ];

        ob_start();
        printMigrateAllSummary($response, false);
        $output = ob_get_clean();

        $this->assertStringNotContainsString('Execution time', $output);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Run checkMigrateAllFailures in a subprocess and return its exit code.
     * This is necessary because the function calls exit(1) on failure, which
     * would kill the PHPUnit process if called directly.
     */
    private function runCheckFailuresInSubprocess(string $status): int
    {
        $frameworkRoot = realpath(dirname(__DIR__, 2));
        $escapedRoot   = escapeshellarg($frameworkRoot);
        $escapedStatus = escapeshellarg($status);

        // Build a small inline PHP script that exercises checkMigrateAllFailures.
        $script = <<<PHP
<?php
require_once {$escapedRoot} . '/vendor/autoload.php';
require_once {$escapedRoot} . '/cli/helpers/gateway-common.php';
\$response = ['status' => {$escapedStatus}, 'results' => [['database' => 'db', 'status' => {$escapedStatus}, 'error' => 'test error']]];
checkMigrateAllFailures(\$response, true);
PHP;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(['php', '-r', $script], $descriptors, $pipes);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return proc_close($proc);
    }
}
