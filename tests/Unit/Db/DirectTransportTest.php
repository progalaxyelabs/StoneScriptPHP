<?php

declare(strict_types=1);

namespace Tests\Unit\Db;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use StoneScriptPHP\Database\DbConnectionPool;
use StoneScriptPHP\Db\DbTransportException;
use StoneScriptPHP\Db\DirectTransport;

/**
 * Unit tests for DB_MODE=direct's DbTransport implementation.
 *
 * These are pure unit tests — no live Postgres required. Connection-failure
 * behavior is exercised via a mocked DbConnectionPool (constructor
 * injection, see DirectTransport::__construct()'s optional $pool param), so
 * the "database is unreachable -> 503" translation is verifiable without a
 * device/network. A skip-guarded happy-path integration test (bottom of
 * this file) covers the real PDO round trip when a test Postgres is
 * configured — mirrors the existing DatabaseTest::test_fn_accepts_array_parameters
 * skip-if-unconfigured pattern for gateway mode.
 */
class DirectTransportTest extends TestCase
{
    private function makeTransportWithMockPool(DbConnectionPool $pool): DirectTransport
    {
        return new DirectTransport('db-host', 5432, 'testdb', 'testuser', 'testpass', $pool);
    }

    public function test_constructor_throws_when_pdo_pgsql_extension_missing(): void
    {
        if (extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql is loaded in this environment — cannot exercise the missing-extension guard directly.');
        }

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('requires the pdo_pgsql PHP extension');

        new DirectTransport('h', 5432, 'db', 'u', 'p');
    }

    public function test_rejects_invalid_function_name(): void
    {
        $pool = $this->createMock(DbConnectionPool::class);
        $pool->expects($this->never())->method('getConnection');

        $transport = $this->makeTransportWithMockPool($pool);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('invalid database function name');

        $transport->callFunction('drop table users; --', []);
    }

    public function test_pool_connection_failure_wraps_as_connection_failure(): void
    {
        $pool = $this->createMock(DbConnectionPool::class);
        $pool->method('getConnection')->willThrowException(
            new PDOException('SQLSTATE[08006] Connection refused')
        );

        $transport = $this->makeTransportWithMockPool($pool);

        try {
            $transport->callFunction('get_user', [1]);
            $this->fail('Expected DbTransportException');
        } catch (DbTransportException $e) {
            $this->assertTrue($e->isConnectionFailure());
            $this->assertStringContainsString('Direct database connection failed', $e->getMessage());
        }
    }

    public function test_query_failure_on_live_pdo_wraps_as_db_transport_exception(): void
    {
        // A live PDO to a bogus in-process DSN would need a real server; instead
        // use a PDO subclass double whose prepare() throws, to exercise the
        // query-execution catch path (as opposed to the connection path above)
        // without a real database.
        $pdo = $this->getMockBuilder(PDO::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['prepare'])
            ->getMock();
        $pdo->method('prepare')->willThrowException(
            new PDOException('SQLSTATE[42883] function get_user(integer) does not exist')
        );

        $pool = $this->createMock(DbConnectionPool::class);
        $pool->method('getConnection')->willReturn($pdo);

        $transport = $this->makeTransportWithMockPool($pool);

        try {
            $transport->callFunction('get_user', [1]);
            $this->fail('Expected DbTransportException');
        } catch (DbTransportException $e) {
            // 42883 (undefined_function) is a query-level failure, not a
            // connection failure — must NOT be classified as connection loss.
            $this->assertFalse($e->isConnectionFailure());
            $this->assertStringContainsString('Direct database function call failed', $e->getMessage());
        }
    }

    public function test_normalizes_associative_params_to_positional(): void
    {
        $stmt = $this->getMockBuilder(\PDOStatement::class)->getMock();
        $stmt->expects($this->once())
            ->method('execute')
            ->with($this->identicalTo(['Ada', 30])) // positional order, keys stripped
            ->willReturn(true);
        $stmt->method('fetchAll')->willReturn([['id' => 1, 'name' => 'Ada']]);

        $pdo = $this->getMockBuilder(PDO::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['prepare'])
            ->getMock();
        $pdo->method('prepare')->willReturn($stmt);

        $pool = $this->createMock(DbConnectionPool::class);
        $pool->method('getConnection')->willReturn($pdo);

        $transport = $this->makeTransportWithMockPool($pool);
        $rows = $transport->callFunction('create_user', ['name' => 'Ada', 'age' => 30]);

        $this->assertSame([['id' => 1, 'name' => 'Ada']], $rows);
        $this->assertTrue($transport->isConnected());
    }

    // ── SQLSTATE connection-failure classification (private method, tested via reflection) ──

    #[DataProvider('sqlstateProvider')]
    public function test_isConnectionFailure_classifies_sqlstates(string $sqlstate, bool $expected): void
    {
        $pool = $this->createMock(DbConnectionPool::class);
        $transport = $this->makeTransportWithMockPool($pool);

        $method = new ReflectionMethod(DirectTransport::class, 'isConnectionFailure');
        $method->setAccessible(true);

        $e = new PDOException('simulated', 0);
        // PDOException::errorInfo is populated by the PDO/PDOStatement driver in
        // real usage; set it directly here to control the SQLSTATE under test.
        $e->errorInfo = [$sqlstate, null, 'simulated'];

        $this->assertSame($expected, $method->invoke($transport, $e));
    }

    public static function sqlstateProvider(): array
    {
        return [
            'connection exception (08000)' => ['08000', true],
            'connection refused (08006)' => ['08006', true],
            'connection does not exist (08003)' => ['08003', true],
            'admin shutdown (57P01)' => ['57P01', true],
            'undefined function (42883)' => ['42883', false],
            'unique violation (23505)' => ['23505', false],
            'raised business exception (P0001)' => ['P0001', false],
        ];
    }

    // ── Skip-guarded live integration test ──────────────────────────────────

    public function test_live_direct_connection_round_trip(): void
    {
        if (!getenv('TEST_DIRECT_DB_NAME')) {
            $this->markTestSkipped(
                'Requires a live Postgres reachable via TEST_DIRECT_DB_HOST/PORT/NAME/USER/PASSWORD — integration test.'
            );
        }

        $transport = new DirectTransport(
            getenv('TEST_DIRECT_DB_HOST') ?: 'localhost',
            (int) (getenv('TEST_DIRECT_DB_PORT') ?: 5432),
            (string) getenv('TEST_DIRECT_DB_NAME'),
            (string) getenv('TEST_DIRECT_DB_USER'),
            getenv('TEST_DIRECT_DB_PASSWORD') ?: null
        );

        // Postgres built-in scalar function usable as a smoke test without
        // needing a platform-specific function pre-created.
        $rows = $transport->callFunction('now', []);
        $this->assertIsArray($rows);
        $this->assertNotEmpty($rows);
        $this->assertTrue($transport->isConnected());
    }
}
