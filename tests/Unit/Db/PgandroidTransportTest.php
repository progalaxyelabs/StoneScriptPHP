<?php

declare(strict_types=1);

namespace Tests\Unit\Db;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Db\DbTransportException;
use StoneScriptPHP\Db\PgandroidTransport;

/**
 * Unit tests for DB_MODE=pgandroid's DbTransport implementation.
 *
 * Per the handoff spec, the android-server C++ embed host's bridge function
 * (androidserver_db_exec) must be mockable in a unit test without a real
 * device — PgandroidTransport's constructor accepts an injectable bridge
 * callable for exactly this reason. These tests never touch the real global
 * androidserver_db_exec() function.
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

    public function test_bridge_throwing_is_classified_as_connection_failure(): void
    {
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
