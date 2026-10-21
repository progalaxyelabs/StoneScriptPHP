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
            [$out, $err, $code] = $this->runChild(
                [__DIR__ . '/../Fixtures/uncaught_db_failure.php', "http://127.0.0.1:{$this->port}", $mode]
            );
            $this->assertNotSame(0, $code, "$mode: the call must fail");
            $this->assertNoSentinel($out, "$mode stdout");
            $this->assertNoSentinel($err, "$mode stderr");
            // diagnosable: still names the failing function / constraint somewhere (stdout or stderr)
            $combined = $out . $err;
            $this->assertTrue(
                str_contains($combined, 'users_email_key') || str_contains($combined, 'create_user') || $mode === 'framework',
                "$mode: identifiers should remain visible"
            );
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
}
