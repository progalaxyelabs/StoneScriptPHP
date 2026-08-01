<?php

declare(strict_types=1);

namespace Tests\Unit\Db;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Db\DbTransportException;
use StoneScriptPHP\Db\DirectTransport;
use StoneScriptPHP\Db\PgandroidTransport;

/**
 * Unit tests for DB_MODE=pgandroid's DbTransport implementation.
 *
 * Per the handoff spec, the android-server C++ embed host's bridge function
 * (androidserver_db_exec) must be mockable in a unit test without a real
 * device — PgandroidTransport's constructor accepts an injectable bridge
 * callable for exactly this reason. These tests never touch the real global
 * androidserver_db_exec() function.
 *
 * Bridge-error classification (query error -> 500 vs engine-unusable -> 503)
 * is exercised below per the pinned bridge error-signaling contract
 * documented on PgandroidTransport itself: a bridge Throwable whose message
 * contains `SQLSTATE[xxxxx]` is a query-level error, classified via
 * DirectTransport::CONNECTION_FAILURE_SQLSTATES (reused, not duplicated);
 * a bridge Throwable with no parseable SQLSTATE is the engine-unusable
 * signal and is always a connection failure.
 */
class PgandroidTransportTest extends TestCase
{
    public function test_happy_path_encodes_params_and_decodes_rows(): void
    {
        $seenFn = null;
        $seenParamsJson = null;

        $bridge = function (string $fn, string $paramsJson) use (&$seenFn, &$seenParamsJson): string {
            $seenFn = $fn;
            $seenParamsJson = $paramsJson;
            return json_encode([['id' => 1, 'name' => 'Ada']]);
        };

        $transport = new PgandroidTransport($bridge);
        $rows = $transport->callFunction('get_user', [1]);

        $this->assertSame('get_user', $seenFn);
        $this->assertSame('[1]', $seenParamsJson);
        $this->assertSame([['id' => 1, 'name' => 'Ada']], $rows);
        $this->assertTrue($transport->isConnected());
    }

    public function test_normalizes_associative_params_to_positional_json(): void
    {
        $seenParamsJson = null;
        $bridge = function (string $fn, string $paramsJson) use (&$seenParamsJson): string {
            $seenParamsJson = $paramsJson;
            return json_encode([]);
        };

        $transport = new PgandroidTransport($bridge);
        $transport->callFunction('create_user', ['name' => 'Ada', 'age' => 30]);

        $this->assertSame('["Ada",30]', $seenParamsJson);
    }

    public function test_bridge_throwing_without_sqlstate_is_classified_as_connection_failure(): void
    {
        // No SQLSTATE[...] in the message => the pinned "engine unusable"
        // signal (e.g. bridge/host unreachable) => always a 503.
        $bridge = function (): string {
            throw new \RuntimeException('native bridge unreachable');
        };

        $transport = new PgandroidTransport($bridge);

        try {
            $transport->callFunction('get_user', []);
            $this->fail('Expected DbTransportException');
        } catch (DbTransportException $e) {
            $this->assertTrue($e->isConnectionFailure());
            $this->assertStringContainsString('native bridge unreachable', $e->getMessage());
        }
    }

    #[DataProvider('engineUnusableMessageProvider')]
    public function test_engine_unusable_signals_are_classified_as_connection_failure(string $message): void
    {
        // Per the pinned contract: pgandroid-not-initialized and
        // pgandroid_exec_params()-returned-NULL (OOM) both carry no
        // SQLSTATE — both must map to 503, not 500.
        $bridge = function () use ($message): string {
            throw new \RuntimeException($message);
        };

        $transport = new PgandroidTransport($bridge);

        try {
            $transport->callFunction('get_user', []);
            $this->fail('Expected DbTransportException');
        } catch (DbTransportException $e) {
            $this->assertTrue($e->isConnectionFailure());
        }
    }

    public static function engineUnusableMessageProvider(): array
    {
        return [
            'not initialized' => ['pgandroid: engine not initialized'],
            'OOM / NULL exec_params result' => ['pgandroid: pgandroid_exec_params returned NULL (out of memory)'],
        ];
    }

    public function test_query_error_with_sqlstate_is_classified_as_500_not_connection_failure(): void
    {
        // unique_violation (23505) reached a LIVE engine — must be 500, the
        // exact bug this fix addresses (previously blanket-mapped to 503).
        $bridge = function (): string {
            throw new \RuntimeException(
                "SQLSTATE[23505]: duplicate key value violates unique constraint \"users_email_key\""
            );
        };

        $transport = new PgandroidTransport($bridge);

        try {
            $transport->callFunction('create_user', ['a@example.com']);
            $this->fail('Expected DbTransportException');
        } catch (DbTransportException $e) {
            $this->assertFalse($e->isConnectionFailure());
            $this->assertStringContainsString('SQLSTATE[23505]', $e->getMessage());
        }
    }

    public function test_raised_business_exception_sqlstate_is_classified_as_500(): void
    {
        // P0001 (raise_exception) — a business-rule RAISE EXCEPTION — is a
        // query-level failure, not a connection failure.
        $bridge = function (): string {
            throw new \RuntimeException("SQLSTATE[P0001]: name collides with an existing record");
        };

        $transport = new PgandroidTransport($bridge);

        try {
            $transport->callFunction('create_widget', ['dup-name']);
            $this->fail('Expected DbTransportException');
        } catch (DbTransportException $e) {
            $this->assertFalse($e->isConnectionFailure());
        }
    }

    #[DataProvider('sqlstateProvider')]
    public function test_sqlstate_classification_reuses_direct_transports_table(string $sqlstate, bool $expected): void
    {
        // Same table, same verdicts as DirectTransportTest::sqlstateProvider
        // — proves PgandroidTransport reuses DirectTransport's classification
        // instead of inventing a parallel one.
        $bridge = function () use ($sqlstate): string {
            throw new \RuntimeException("SQLSTATE[{$sqlstate}]: simulated");
        };

        $transport = new PgandroidTransport($bridge);

        try {
            $transport->callFunction('get_user', []);
            $this->fail('Expected DbTransportException');
        } catch (DbTransportException $e) {
            $this->assertSame($expected, $e->isConnectionFailure());
        }
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

    public function test_reuses_direct_transports_sqlstate_table_directly(): void
    {
        // Guardrail assertion: PgandroidTransport must not maintain its own
        // copy of the connection-failure SQLSTATE list.
        $this->assertContains('08006', DirectTransport::CONNECTION_FAILURE_SQLSTATES);
        $this->assertNotContains('23505', DirectTransport::CONNECTION_FAILURE_SQLSTATES);
    }

    public function test_non_json_bridge_response_throws(): void
    {
        $bridge = fn (): string => 'not-json{{{';

        $transport = new PgandroidTransport($bridge);

        $this->expectException(DbTransportException::class);
        $this->expectExceptionMessage('failed to decode bridge response');

        $transport->callFunction('get_user', []);
    }

    public function test_non_array_json_bridge_response_throws(): void
    {
        $bridge = fn (): string => json_encode('just-a-string');

        $transport = new PgandroidTransport($bridge);

        $this->expectException(DbTransportException::class);
        $this->expectExceptionMessage('non-array JSON response');

        $transport->callFunction('get_user', []);
    }

    public function test_default_bridge_fails_loud_when_native_function_not_registered(): void
    {
        // No androidserver_db_exec() global exists in the PHPUnit process
        // (it's only registered by the real android-server C++ embed host)
        // — the default (no-arg-constructor) bridge must fail loud, not
        // silently return an empty result.
        if (function_exists('androidserver_db_exec')) {
            $this->markTestSkipped('androidserver_db_exec() is unexpectedly registered in this process.');
        }

        $transport = new PgandroidTransport();

        try {
            $transport->callFunction('get_user', []);
            $this->fail('Expected DbTransportException');
        } catch (DbTransportException $e) {
            $this->assertTrue($e->isConnectionFailure());
            $this->assertStringContainsString('androidserver_db_exec() is not registered', $e->getMessage());
        }
    }

    public function test_not_connected_before_first_successful_call(): void
    {
        $transport = new PgandroidTransport(fn (): string => json_encode([]));
        $this->assertFalse($transport->isConnected());
    }
}
