<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\AuthContext;
use StoneScriptPHP\RequestLogging\RequestContext;
use StoneScriptPHP\RequestLogging\RequestLogger;

/**
 * 13.2: the request-log write is bounded and quiet, and probes are not logged.
 */
class RequestLoggerBoundsTest extends TestCase
{
    /** @var resource|null */
    private $server = null;

    protected function setUp(): void
    {
        RequestLogger::reset();
        \StoneScriptPHP\Http\ClientIp::reset();
        RequestContext::reset();
        AuthContext::clear();
        http_response_code(200);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/items';
        $_SERVER['HTTP_HOST'] = 'api.example.test';
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        unset($_ENV['DB_GATEWAY_URL'], $_ENV['DB_GATEWAY_PLATFORM'], $_ENV['REQUEST_LOG_SKIP_PATHS'], $_SERVER['REQUEST_LOG_SKIP_PATHS']);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        RequestLogger::reset();
        RequestContext::reset();
        unset($_ENV['DB_GATEWAY_URL'], $_ENV['DB_GATEWAY_PLATFORM'], $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_SERVER['HTTP_HOST'], $_SERVER['REMOTE_ADDR']);
        http_response_code(200);
    }

    /** Start a fake gateway that accepts the request and then stalls for $seconds. */
    private function startSlowGateway(int $seconds): string
    {
        $dir = sys_get_temp_dir() . '/ssp-slowgw-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($dir, 0700);
        file_put_contents($dir . '/index.php', "<?php sleep($seconds); header('Content-Type: application/json'); echo '{\"rows\":[]}';");
        $probe = stream_socket_server('tcp://127.0.0.1:0', $e, $m);
        $port = (int) substr(strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);
        $this->server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $dir],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        for ($i = 0; $i < 50; $i++) {
            $c = @fsockopen('127.0.0.1', $port, $en, $es, 0.2);
            if ($c) { fclose($c); break; }
            usleep(100000);
        }
        return "http://127.0.0.1:$port";
    }

    public function test_slow_gateway_cannot_hold_the_worker_beyond_the_bound_and_fails_open(): void
    {
        $_ENV['DB_GATEWAY_URL'] = $this->startSlowGateway(8);
        $_ENV['DB_GATEWAY_PLATFORM'] = 'example';
        $lines = [];
        RequestLogger::arm([], microtime(true));
        RequestLogger::setFailureSink(function (string $l) use (&$lines): void { $lines[] = $l; });

        $t = microtime(true);
        RequestLogger::persistRequestLog();
        $elapsed = microtime(true) - $t;

        $this->assertLessThan(3.5, $elapsed, 'gateway stalls 8 s; the write must give up at ~2 s');
        $this->assertCount(1, $lines, 'exactly one summarized error line');
        $this->assertStringContainsString('fail-open', $lines[0]);
    }

    public function test_after_a_failure_the_worker_skips_writes_during_cooldown_and_logs_one_line(): void
    {
        $_ENV['DB_GATEWAY_URL'] = 'http://127.0.0.1:1'; // connection refused
        $_ENV['DB_GATEWAY_PLATFORM'] = 'example';
        $lines = [];
        RequestLogger::arm(['request_logging' => ['failure_cooldown_seconds' => 30]], microtime(true));
        RequestLogger::setFailureSink(function (string $l) use (&$lines): void { $lines[] = $l; });

        $t = microtime(true);
        for ($i = 0; $i < 50; $i++) {
            RequestLogger::persistRequestLog();
        }
        $this->assertLessThan(3.0, microtime(true) - $t, '50 requests during an outage cost one attempt, not 50');
        $this->assertCount(1, $lines, 'no flood');
    }

    public function test_one_transient_failure_does_not_cost_the_next_failure_row(): void
    {
        $written = [];
        $calls = 0;
        RequestLogger::arm([], microtime(true));
        RequestLogger::setFailureSink(function (string $l): void {});
        RequestLogger::setWriteAdapter(function (array $r) use (&$written, &$calls): void {
            if (++$calls === 1) {
                throw new \RuntimeException('timeout');
            }
            $written[] = $r['status'];
        });

        RequestLogger::persistRequestLog();   // 200: transient write failure
        http_response_code(500);
        RequestLogger::persistRequestLog();   // the 500 must be written
        $this->assertSame([500], $written);
    }

    public function test_breaker_opens_after_three_consecutive_failures_skips_success_rows_but_still_probes_failures(): void
    {
        $attempts = [];
        $healthy = false;
        RequestLogger::arm([], microtime(true));
        RequestLogger::setFailureSink(function (string $l): void {});
        RequestLogger::setWriteAdapter(function (array $r) use (&$attempts, &$healthy): void {
            $attempts[] = $r['status'];
            if (!$healthy) {
                throw new \RuntimeException('down');
            }
        });

        for ($i = 0; $i < 3; $i++) {
            RequestLogger::persistRequestLog();           // 3 consecutive failures -> open
        }
        $this->assertSame([200, 200, 200], $attempts);

        for ($i = 0; $i < 20; $i++) {
            RequestLogger::persistRequestLog();           // open: success rows are skipped
        }
        $this->assertCount(3, $attempts, 'no attempts for success rows while open');

        http_response_code(500);
        RequestLogger::persistRequestLog();               // open: a failure row is still attempted (probe)
        RequestLogger::persistRequestLog();               // ... but only one probe per interval
        $this->assertSame([200, 200, 200, 500], $attempts, 'bounded: one failure probe, then skipped');
    }

    public function test_successful_write_closes_the_breaker(): void
    {
        $n = 0;
        $fail = true;
        RequestLogger::arm([], microtime(true));
        RequestLogger::setFailureSink(function (string $l): void {});
        RequestLogger::setWriteAdapter(function (array $r) use (&$n, &$fail): void {
            $n++;
            if ($fail) { throw new \RuntimeException('x'); }
        });
        RequestLogger::persistRequestLog();
        RequestLogger::persistRequestLog();
        $fail = false;
        RequestLogger::persistRequestLog();               // success resets the consecutive count
        $fail = true;
        RequestLogger::persistRequestLog();
        RequestLogger::persistRequestLog();               // only 2 consecutive again: breaker still closed
        $fail = false;
        RequestLogger::persistRequestLog();
        $this->assertSame(6, $n);
    }

    public function test_timeouts_are_configurable_clamped_and_default_to_one_and_two_seconds(): void
    {
        RequestLogger::arm([], microtime(true));
        $this->assertSame(['connect' => 1, 'total' => 2], RequestLogger::getWriteTimeouts());

        RequestLogger::reset();
        RequestLogger::arm(['request_logging' => ['connect_timeout_seconds' => 3, 'write_timeout_seconds' => 5]], microtime(true));
        $this->assertSame(['connect' => 3, 'total' => 5], RequestLogger::getWriteTimeouts());

        RequestLogger::reset();
        RequestLogger::arm(['request_logging' => ['connect_timeout_seconds' => 0, 'write_timeout_seconds' => 999]], microtime(true));
        $this->assertSame(['connect' => 1, 'total' => 10], RequestLogger::getWriteTimeouts(), '0 -> default, huge -> capped');
    }

    public function test_default_probes_are_not_logged_but_real_traffic_is(): void
    {
        $rows = [];
        RequestLogger::arm([], microtime(true));
        RequestLogger::setWriteAdapter(function (array $r) use (&$rows): void { $rows[] = $r['path']; });

        foreach (['/health', '/health/', '/healthz', '/api/items'] as $p) {
            $_SERVER['REQUEST_URI'] = $p . '?x=1';
            RequestLogger::persistRequestLog();
        }
        $this->assertSame(['/api/items'], $rows);
    }

    public function test_successful_head_requests_are_not_logged(): void
    {
        $rows = [];
        RequestLogger::arm([], microtime(true));
        RequestLogger::setWriteAdapter(function (array $r) use (&$rows): void { $rows[] = $r['method']; });
        $_SERVER['REQUEST_METHOD'] = 'HEAD';
        $_SERVER['REQUEST_URI'] = '/anything';
        RequestLogger::persistRequestLog();
        $this->assertSame([], $rows);
    }

    public function test_a_failing_probe_is_still_captured(): void
    {
        $rows = [];
        RequestLogger::arm([], microtime(true));
        RequestLogger::setWriteAdapter(function (array $r) use (&$rows): void { $rows[] = $r; });

        $_SERVER['REQUEST_URI'] = '/health';
        http_response_code(503);
        RequestLogger::persistRequestLog();

        $_SERVER['REQUEST_METHOD'] = 'HEAD';
        http_response_code(500);
        RequestLogger::persistRequestLog();

        http_response_code(200);
        RequestContext::captureException(new \RuntimeException('boom'));
        RequestLogger::persistRequestLog();

        $this->assertCount(3, $rows, 'status >= 400 and recorded errors are never skipped');
    }

    public function test_skip_paths_are_configurable_by_config_and_env(): void
    {
        RequestLogger::arm(['request_logging' => ['skip_paths' => ['/ping', 'status/']]], microtime(true));
        $this->assertSame(['/ping', '/status'], RequestLogger::getSkipPaths());

        RequestLogger::reset();
        $_ENV['REQUEST_LOG_SKIP_PATHS'] = '/up, /metrics';
        RequestLogger::arm([], microtime(true));
        $this->assertSame(['/up', '/metrics'], RequestLogger::getSkipPaths());

        RequestLogger::reset();
        RequestLogger::arm(['request_logging' => ['skip_paths' => []]], microtime(true));
        $this->assertSame([], RequestLogger::getSkipPaths(), 'explicit empty list = log everything');
        unset($_ENV['REQUEST_LOG_SKIP_PATHS']);
    }

    public function test_should_skip_matches_on_path_boundaries_only(): void
    {
        $this->assertTrue(RequestLogger::shouldSkip('GET', '/health/db', 200, false, ['/health']));
        $this->assertFalse(RequestLogger::shouldSkip('GET', '/healthcare', 200, false, ['/health']));
        $this->assertFalse(RequestLogger::shouldSkip('POST', '/api/health-report', 200, false, ['/health']));
    }
}
