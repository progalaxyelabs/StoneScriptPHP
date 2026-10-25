<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\RequestLogging\RequestLogRetention;

/**
 * Retention (13.2): the batch loop behind `php stone request-logs:purge`, the SQL function's guards, the CLI
 * registration, the shipped timer templates. The function's behaviour against real PostgreSQL is in
 * tests/Integration/sql/run-request-logs-retention-test.sh.
 */
class RequestLogRetentionTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public function test_loop_runs_until_a_short_batch_and_sums_deletions(): void
    {
        $calls = [];
        $returns = [100, 100, 100, 37];
        $r = new RequestLogRetention(function (int $days, int $batch) use (&$calls, &$returns): int {
            $calls[] = [$days, $batch];
            return array_shift($returns);
        });
        $out = $r->run(30, 100);
        $this->assertSame(['deleted' => 337, 'batches' => 4, 'complete' => true], $out);
        $this->assertSame([30, 100], $calls[0]);
    }

    public function test_batch_above_the_sql_cap_is_clamped_so_completion_is_not_reported_early(): void
    {
        $seen = [];
        $returns = [100000, 5];
        $out = (new RequestLogRetention(function (int $d, int $b) use (&$seen, &$returns): int {
            $seen[] = $b;
            return array_shift($returns);
        }))->run(30, 5000000);
        $this->assertSame([100000, 100000], $seen);
        $this->assertSame(100005, $out['deleted']);
        $this->assertTrue($out['complete']);
    }

    public function test_empty_table_is_one_call(): void
    {
        $n = 0;
        $out = (new RequestLogRetention(function () use (&$n): int { $n++; return 0; }))->run(30, 100);
        $this->assertSame(1, $n);
        $this->assertSame(0, $out['deleted']);
        $this->assertTrue($out['complete']);
    }

    public function test_batch_cap_stops_the_run_and_reports_incomplete(): void
    {
        $n = 0;
        $out = (new RequestLogRetention(function (int $d, int $b) use (&$n): int { $n++; return $b; }))->run(30, 50, 3);
        $this->assertSame(3, $n);
        $this->assertSame(150, $out['deleted']);
        $this->assertFalse($out['complete'], 'cap reached with full batches: more old rows remain');
    }

    public function test_a_failing_purge_propagates_so_the_cli_exits_non_zero(): void
    {
        $this->expectException(\RuntimeException::class);
        (new RequestLogRetention(function (): int { throw new \RuntimeException('gateway down'); }))->run(30);
    }

    public function test_zero_or_negative_days_is_rejected_never_meaning_delete_everything(): void
    {
        $called = false;
        $r = new RequestLogRetention(function () use (&$called): int { $called = true; return 0; });
        foreach ([0, -5] as $bad) {
            try {
                $r->run($bad);
                $this->fail('must reject days < 1');
            } catch (\InvalidArgumentException) {
            }
        }
        $this->assertFalse($called);
    }

    public function test_resolve_days_precedence_and_bad_values_fall_back_to_default(): void
    {
        unset($_ENV['REQUEST_LOG_RETENTION_DAYS'], $_SERVER['REQUEST_LOG_RETENTION_DAYS']);
        putenv('REQUEST_LOG_RETENTION_DAYS');
        $this->assertSame(30, RequestLogRetention::resolveDays());
        $_ENV['REQUEST_LOG_RETENTION_DAYS'] = '90';
        $this->assertSame(90, RequestLogRetention::resolveDays());
        $this->assertSame(7, RequestLogRetention::resolveDays('7'), 'explicit beats env');
        foreach (['0', '-1', 'abc', '', '1.5', ' '] as $bad) {
            $_ENV['REQUEST_LOG_RETENTION_DAYS'] = $bad;
            $this->assertSame(30, RequestLogRetention::resolveDays(), "bad value '$bad'");
        }
        unset($_ENV['REQUEST_LOG_RETENTION_DAYS']);
    }

    public function test_sql_function_is_shipped_batched_guarded_and_ddl_free(): void
    {
        $sql = (string) file_get_contents($this->root() . '/src/RequestLogging/Schema/functions/rl_purge_request_logs.pgsql');
        $this->assertStringContainsString('CREATE OR REPLACE FUNCTION rl_purge_request_logs(p_older_than_days INT, p_batch INT)', $sql);
        $this->assertStringContainsString('LIMIT', $sql);
        $this->assertStringContainsString('SKIP LOCKED', $sql);
        $this->assertStringContainsString('p_older_than_days < 1', $sql);
        $this->assertDoesNotMatchRegularExpression('/DROP\s+TABLE|TRUNCATE|DROP\s+DATABASE|DROP\s+SCHEMA|ALTER\s+TABLE/i', $sql);
    }

    public function test_occurred_at_index_is_declared_additively(): void
    {
        $sql = (string) file_get_contents($this->root() . '/src/RequestLogging/Schema/tables/req_001_request_logs.pgsql');
        $this->assertMatchesRegularExpression('/CREATE INDEX IF NOT EXISTS idx_request_logs_occurred_at\s+ON request_logs \(occurred_at\)/', $sql);
    }

    public function test_command_is_registered_and_cli_file_exists(): void
    {
        $stone = (string) file_get_contents($this->root() . '/stone');
        $this->assertStringContainsString("'request-logs:purge'", $stone);
        $this->assertStringContainsString('cli/request-logs-purge.php', $stone);
        $this->assertFileExists($this->root() . '/cli/request-logs-purge.php');
    }

    public function test_cli_rejects_a_bad_option_with_exit_1(): void
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->root() . '/stone') . ' request-logs:purge --days=0 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(1, $code, implode("\n", $out));
        $this->assertStringContainsString('--days', implode("\n", $out));
    }

    public function test_systemd_templates_ship_and_are_generic(): void
    {
        $svc = (string) file_get_contents($this->root() . '/contrib/systemd/stonescriptphp-request-log-purge.service');
        $tmr = (string) file_get_contents($this->root() . '/contrib/systemd/stonescriptphp-request-log-purge.timer');
        $this->assertStringContainsString('ExecStart=/usr/bin/php stone request-logs:purge', $svc);
        $this->assertStringContainsString('Type=oneshot', $svc);
        $this->assertStringContainsString('OnCalendar=', $tmr);
        $this->assertStringContainsString('Persistent=true', $tmr);
        $this->assertStringContainsString('WantedBy=timers.target', $tmr);
    }
}
