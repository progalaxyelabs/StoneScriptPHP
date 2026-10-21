<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptDB\GatewayException;
use StoneScriptPHP\Db\DbTransportException;
use StoneScriptPHP\Persistence\DbErrorMapper;
use StoneScriptPHP\Persistence\LogSanitizer;
use StoneScriptPHP\Persistence\SafeDbContext;

/**
 * Sentinel values (email, phone in a custom RAISE, password hash) are planted in PostgreSQL-shaped failures.
 * None of them may reach stdout, stderr, logs, exception strings or uncaught-exception output.
 */
final class NoPgDetailLeakTest extends TestCase
{
    private const SENTINELS = [
        'jane.sentinel@example.test',
        '919876500123',
        'sentinelhashvalue',
        'Key (email)',
        'DETAIL',
        'HINT',
    ];

    /** @var resource|null */
    private $stub = null;
    private int $port = 0;

    protected function tearDown(): void
    {
        if (is_resource($this->stub)) {
            proc_terminate($this->stub);
            proc_close($this->stub);
            $this->stub = null;
        }
    }

    private function assertNoSentinel(string $text, string $where): void
    {
        foreach (self::SENTINELS as $s) {
            $this->assertStringNotContainsString($s, $text, "sentinel '$s' leaked via $where");
        }
    }

    private function startStub(): void
    {
        $this->port = random_int(41000, 59000);
        $this->stub = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$this->port}", __DIR__ . '/../Fixtures/leaky_gateway_stub.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        for ($i = 0; $i < 60; $i++) {
            $s = @fsockopen('127.0.0.1', $this->port);
            if ($s) {
                fclose($s);
                return;
            }
            usleep(100000);
        }
        $this->markTestSkipped('stub gateway did not start');
    }

    /** @return array{0:string,1:string,2:int} stdout, stderr, exit code */
    private function runChild(array $argv, array $env = []): array
    {
        $p = proc_open(
            array_merge([PHP_BINARY], $argv),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            array_merge(['PATH' => getenv('PATH') ?: '/usr/bin'], $env)
        );
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [(string) $out, (string) $err, proc_close($p)];
    }

    public function testUncaughtDatabaseFailureNeverPrintsRawChain(): void
    {
        $this->startStub();
        foreach (['framework', 'bare'] as $mode) {
            $logDir = sys_get_temp_dir() . '/nopg-logs-' . $mode . '-' . bin2hex(random_bytes(3));
            mkdir($logDir, 0775, true);
            [$out, $err, $code] = $this->runChild(
                [__DIR__ . '/../Fixtures/uncaught_db_failure.php', "http://127.0.0.1:{$this->port}", $mode],
                ['STONESCRIPTPHP_LOG_DIR' => $logDir]
            );
            $this->assertNotSame(0, $code, "$mode: the call must fail");
            $this->assertNoSentinel($out, "$mode stdout");
            $this->assertNoSentinel($err, "$mode stderr");

            $logs = '';
            foreach (glob($logDir . '/*') ?: [] as $f) {
                $logs .= (string) file_get_contents($f);
            }
            if ($mode === 'framework') {
                // the handler really ran and really wrote a log: assert on it, and on its identifiers
                $this->assertNotSame('', $logs, 'framework handler must write a log file');
                $this->assertNoSentinel($logs, 'framework log file');
                $this->assertStringContainsString('constraint=users_email_key', $logs);
                $this->assertStringContainsString('function=create_user', $logs);
                $this->assertStringContainsString('"correlation_id"', $logs . $out);
            } else {
                // bare PHP prints the value-free chain itself: identifiers must be visible there
                $this->assertStringContainsString('constraint=users_email_key', $out . $err);
                $this->assertStringContainsString('function=create_user', $out . $err);
            }
        }
    }

    public function testGatewayCliOutputIsValueFree(): void
    {
        $this->startStub();
        $env = [
            'DB_GATEWAY_URL' => "http://127.0.0.1:{$this->port}",
            'PLATFORM_ID' => 'p',
            'DB_GATEWAY_PLATFORM' => 'p',
        ];
        [$out, $err, $code] = $this->runChild([__DIR__ . '/../Fixtures/run_cli.php', __DIR__ . '/../../cli/gateway-list-schemas.php'], $env);
        $this->assertNotSame(0, $code);
        $this->assertNoSentinel($out, 'cli stdout');
        $this->assertNoSentinel($err, 'cli stderr');
        $this->assertStringContainsString('error=query_failed', $out . $err);
    }

    public function testGatewayExceptionChainThroughDatabaseFnIsSafe(): void
    {
        $body = [
            'error' => 'query_failed',
            'database' => 'tenant_db',
            'cause' => 'User +919876500123 exists | DETAIL: Key (email)=(jane.sentinel@example.test) already exists. | hash $2y$10$sentinelhashvalue',
        ];
        $ge = new GatewayException('Gateway returned HTTP 500: query_failed [database=tenant_db]', 500, null, $body);
        \StoneScriptPHP\Database::fake(['create_user' => static function () use ($ge) {
            throw $ge;
        }]);
        try {
            \StoneScriptPHP\Database::fn('create_user', ['jane.sentinel@example.test']);
            $this->fail('expected exception');
        } catch (\Exception $e) {
            $this->assertNoSentinel((string) $e, '(string)$e');
            $this->assertNoSentinel($e->getMessage(), 'message');
            $this->assertNoSentinel(print_r($e, true), 'print_r');
            $this->assertNoSentinel(LogSanitizer::describe($e), 'describe');
            $this->assertNoSentinel(LogSanitizer::safeTrace($e), 'safeTrace');
            // in-process classification still sees the raw cause
            $this->assertTrue(DbErrorMapper::causeContains($e, 'jane.sentinel'));
            $this->assertSame(409, DbErrorMapper::classify($e)[0]);
        } finally {
            \StoneScriptPHP\Database::clearFakeMode();
        }
    }

    public function testCustomRaiseWithUnquotedValueNeverReachesLogLine(): void
    {
        $ge = new GatewayException('Gateway returned HTTP 500: query_failed', 500, null, [
            'error' => 'query_failed',
            'cause' => 'User +919876500123 exists',
        ]);
        $wrapped = new \Exception('Database function call failed: ' . $ge->getMessage(), 500, $ge);
        $line = DbErrorMapper::failureLine($wrapped);
        $this->assertNoSentinel($line, 'failureLine');
        $this->assertStringContainsString('query_failed', $line);
    }

    public function testDirectTransportFailureDropsDriverTextAndChain(): void
    {
        $pdo = new \PDOException('SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "users_email_key" DETAIL:  Key (email)=(jane.sentinel@example.test) already exists. User +919876500123 exists');
        $pdo->errorInfo = ['23505', 7, 'x'];
        $e = DbTransportException::fromDriverFailure('Direct database function call failed', $pdo, false);

        $this->assertNull($e->getPrevious());
        $this->assertSame('23505', $e->getSqlState());
        $this->assertSame('users_email_key', $e->getSafeContext()['constraint']);
        foreach ([$e->getMessage(), (string) $e, print_r($e, true), serialize($e), LogSanitizer::describe($e)] as $t) {
            $this->assertNoSentinel($t, 'DbTransportException');
        }
        $this->assertSame(409, DbErrorMapper::classify($e)[0], 'classification still works from SQLSTATE/raw');
        $this->assertStringContainsString('jane.sentinel', (string) $e->getRawCauseForDebug());
    }

    public function testPdoAndPgLastErrorSummaries(): void
    {
        $raw = "ERROR:  duplicate key value violates unique constraint \"users_phone_key\"\nDETAIL:  Key (phone)=(+919876500123) already exists.";
        $s = SafeDbContext::summarize('Seeder SQL failed', $raw);
        $this->assertNoSentinel($s, 'summarize');
        $this->assertStringContainsString('constraint=users_phone_key', $s);

        $pdo = new \PDOException('SQLSTATE[22P02]: Invalid text representation: invalid input syntax for type uuid: "jane.sentinel@example.test"');
        $pdo->errorInfo = ['22P02', 7, 'x'];
        $m = SafeDbContext::message($pdo);
        $this->assertNoSentinel($m, 'pdo message');
        $this->assertStringContainsString('sqlstate=22P02', $m);

        $this->assertNoSentinel(SafeDbContext::summarize('x', 'invalid value jane.sentinel@example.test'), 'no identifiers');
    }

    public function testSafeBodyRendererIsAllowList(): void
    {
        require_once __DIR__ . '/../../cli/helpers/safe-output.php';
        $out = gw_safe_body((string) json_encode([
            'error' => 'migration_failed',
            'database' => 'tenant_db',
            'cause' => 'Key (email)=(jane.sentinel@example.test)',
            'message' => 'jane.sentinel@example.test',
            'unexpected' => 'jane.sentinel@example.test',
        ]));
        $this->assertNoSentinel($out, 'gw_safe_body');
        $this->assertStringContainsString('error=migration_failed', $out);
        $this->assertNoSentinel(gw_safe_body('<html>jane.sentinel@example.test</html>'), 'non-json');
        $this->assertNoSentinel(gw_safe_error_text('Migration failed in db: 001.sql - Key (email)=(jane.sentinel@example.test)'), 'error text');
    }

    public function testFrameworkHandlerDisablesExceptionArgs(): void
    {
        \StoneScriptPHP\ExceptionHandler::getInstance()->register();
        $this->assertSame('1', ini_get('zend.exception_ignore_args'));
        restore_exception_handler();
        restore_error_handler();
    }

    public function testCliGuardNeutralisesPgWarningsAndUncaughtPdo(): void
    {
        foreach (['warning', 'uncaught'] as $scenario) {
            [$out, $err, $code] = $this->runChild([__DIR__ . '/../Fixtures/cli_guard_probe.php', $scenario]);
            $this->assertNoSentinel($out, "$scenario stdout");
            $this->assertNoSentinel($err, "$scenario stderr");
            $this->assertStringNotContainsString('sentineluser', $out . $err);
            $this->assertStringNotContainsString('jane_sentinel_db', $out . $err);
            if ($scenario === 'uncaught') {
                $this->assertSame(1, $code);
                $this->assertStringContainsString('sqlstate=23505', $err);
                $this->assertStringContainsString('constraint=users_email_key', $err);
            } else {
                $this->assertStringContainsString('Database warning in pg_query()', $err);
                $this->assertStringContainsString('constraint=users_email_key', $err);
            }
        }
    }

    public function testQuietWrapperTurnsPgWarningIntoSafeSummary(): void
    {
        $r = SafeDbContext::quiet(static function () {
            trigger_error("pg_query(): Query failed: ERROR:  duplicate key value violates unique constraint \"users_email_key\"\nDETAIL:  Key (email)=(jane.sentinel@example.test) already exists.", E_USER_WARNING);
            return false;
        }, $error, 'Seeder SQL failed');
        $this->assertFalse($r);
        $this->assertNoSentinel((string) $error, 'quiet');
        $this->assertStringContainsString('constraint=users_email_key', (string) $error);
    }

    public function testPgandroidBridgeWithoutSqlstateKeepsDriverTextOutOfTheMessage(): void
    {
        $t = new \StoneScriptPHP\Db\PgandroidTransport(static function (): string {
            throw new \RuntimeException('engine said: password for jane.sentinel@example.test rejected; +919876500123');
        });
        try {
            $t->callFunction('f', []);
            $this->fail('expected');
        } catch (DbTransportException $e) {
            $this->assertTrue($e->isConnectionFailure());
            foreach ([$e->getMessage(), (string) $e, print_r($e, true), serialize($e), LogSanitizer::describe($e)] as $x) {
                $this->assertNoSentinel($x, 'pgandroid');
            }
            $this->assertStringContainsString('database unavailable', $e->getMessage());
        }
        // typed signal needs no message inspection
        $t = new \StoneScriptPHP\Db\PgandroidTransport(static function (): string {
            throw new \StoneScriptPHP\Db\EngineUnavailableException('engine_not_initialized', 'jane.sentinel@example.test');
        });
        try {
            $t->callFunction('f', []);
            $this->fail('expected');
        } catch (DbTransportException $e) {
            $this->assertTrue($e->isConnectionFailure());
            $this->assertStringContainsString('reason=engine_not_initialized', $e->getMessage());
            $this->assertNoSentinel($e->getMessage(), 'typed');
        }
    }

    public function testRawCauseNotOnTheTransportExceptionObject(): void
    {
        $pdo = new \PDOException('SQLSTATE[23505]: x ERROR:  duplicate key value violates unique constraint "users_email_key"' . "\nDETAIL:  Key (email)=(jane.sentinel@example.test) already exists.");
        $e = DbTransportException::fromDriverFailure('Direct database function call failed', $pdo, false);
        $this->assertNotNull($e->getRawCauseForDebug());
        $this->assertNoSentinel(var_export($e, true), 'var_export');
        $this->assertNoSentinel(print_r((array) $e, true), '(array)');
        foreach ((new \ReflectionObject($e))->getProperties() as $p) {
            if (!$p->isStatic()) {
                $p->setAccessible(true);
                $this->assertNoSentinel(print_r($p->getValue($e), true), $p->getName());
            }
        }
    }

    public function testUnserializeRestoresConnectionFailureFlag(): void
    {
        $e = DbTransportException::fromDriverFailure('x', new \RuntimeException('down'), true);
        $u = unserialize(serialize($e));
        $this->assertTrue($u->isConnectionFailure());
        $this->assertNull($u->getRawCauseForDebug());
        $e2 = DbTransportException::fromDriverFailure('x', new \RuntimeException('SQLSTATE[23505]: dup'), false);
        $this->assertFalse(unserialize(serialize($e2))->isConnectionFailure());
    }

    public function testRefreshTokenEnsureSchemaDecidesFromSqlstateNotMessageText(): void
    {
        $store = new \StoneScriptPHP\Auth\RefreshTokens\PostgresRefreshTokenStore();
        // real GatewayException, as a gateway would produce it for a missing function
        $missing = new GatewayException('Gateway returned HTTP 500: query_failed', 500, null, [
            'error' => 'query_failed',
            'cause' => 'function auth_rt_inspect(text, integer) does not exist | HINT: jane.sentinel@example.test',
        ]);
        \StoneScriptPHP\Database::fake(['auth_rt_inspect' => static function () use ($missing) { throw $missing; }]);
        try {
            $store::resetVerification();
            try {
                $store->ensureSchema();
                $this->fail('expected');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('schema is missing', $e->getMessage());
                $this->assertNoSentinel($e->getMessage() . (string) $e, 'missing schema');
            }
            // a different failure (a business error, not a missing schema) must NOT be reported as missing schema
            $other = new GatewayException('Gateway returned HTTP 500: query_failed', 500, null, [
                'error' => 'query_failed', 'cause' => 'deadlock detected | DETAIL: jane.sentinel@example.test',
            ]);
            \StoneScriptPHP\Database::fake(['auth_rt_inspect' => static function () use ($other) { throw $other; }]);
            $store::resetVerification();
            try {
                $store->ensureSchema();
                $this->fail('expected');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('unavailable', $e->getMessage());
                $this->assertNoSentinel($e->getMessage() . (string) $e, 'outage');
            }
        } finally {
            \StoneScriptPHP\Database::clearFakeMode();
            $store::resetVerification();
        }
    }

    /** @return array<string,array{0:string,1:?string,2:?string}> raw driver text, expected app_code, expected sqlstate */
    public static function appCodeProvider(): array
    {
        return [
            'bridge code' => ['SQLSTATE[P0001]: not_found', 'not_found', 'P0001'],
            'bridge code + text' => ['SQLSTATE[P0001]: slug_taken: slug acme is taken', 'slug_taken:', 'P0001'],
            'pdo code' => ["SQLSTATE[P0001]: Raise exception: 7 ERROR:  RATE_LIMITED\nCONTEXT:  PL/pgSQL function f() line 3", 'RATE_LIMITED', 'P0001'],
            'bracket tag' => ["ERROR:  [ER102] Stock short\nDETAIL: x", '[ER102]', null],
            'sentence' => ["SQLSTATE[P0001]: Insufficient stock for item", null, 'P0001'],
            'value sentence' => ["ERROR:  User +919876500123 exists", null, null],
            'code only in detail' => ["ERROR:  Something failed\nDETAIL:  not_found", null, null],
            'code only in hint' => ["SQLSTATE[P0001]: Something failed\nHINT:  slug_taken", null, 'P0001'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('appCodeProvider')]
    public function testAppErrorCodeExtraction(string $raw, ?string $code, ?string $state): void
    {
        $ctx = SafeDbContext::fromText($raw);
        $this->assertSame($code, $ctx['app_code'] ?? null);
        $this->assertSame($state, $ctx['sqlstate'] ?? null);
        $e = DbTransportException::fromDriverFailure('Direct database function call failed', new \RuntimeException($raw), false);
        $this->assertSame($code, $e->getAppErrorCode());
        if ($code !== null) {
            $this->assertStringContainsString('code=' . $code, $e->getMessage());
        }
        $this->assertNoSentinel($e->getMessage(), 'app code message');
    }

    public function testSmugglingThroughDriverTextIsNotLifted(): void
    {
        $cases = [
            "SQLSTATE[23505]: x ERROR:  duplicate key value violates unique constraint \"users_name_key\"\nDETAIL:  Key (name)=(Rahul column Sharma) already exists.",
            "SQLSTATE[P0001]: Raise exception: 7 ERROR:  Duplicate for constraint \"smuggled_ident\"",
            "SQLSTATE[P0001]: Order for column \"smuggled_col\" is invalid",
            "ERROR:  oops | TABLE: smuggled_tbl | COLUMN: smuggled_col",
            "ERROR:  x\nDETAIL:  null value in column \"smuggled_col\" violates not-null constraint",
        ];
        foreach ($cases as $raw) {
            $e = DbTransportException::fromDriverFailure('failed', new \RuntimeException($raw), false);
            $all = $e->getMessage() . json_encode($e->getSafeContext());
            foreach (['Sharma', 'Rahul', 'smuggled_ident', 'smuggled_col', 'smuggled_tbl'] as $s) {
                $this->assertStringNotContainsString($s, $all, "leaked $s from: $raw");
            }
        }
    }
}
