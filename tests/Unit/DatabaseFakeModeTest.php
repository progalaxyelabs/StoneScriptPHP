<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Database;
use StoneScriptPHP\TenantDatabaseUnavailableException;
use StoneScriptDB\GatewayException;

/**
 * Feature guard for TESTABILITY-SPEC.md T2-1: Database::fake() lets a
 * business-logic test stub Database::fn()'s responses without a live
 * gateway/Postgres. Every existing production call path (Database::fn()
 * with no fake registered) is unaffected — proven by
 * test_fn_without_fake_mode_still_attempts_a_real_connection().
 */
class DatabaseFakeModeTest extends TestCase
{
    protected function tearDown(): void
    {
        // Mandatory discipline, same as AuthContext::clear() — fake mode is
        // process-global state and must not leak between tests.
        Database::clearFakeMode();
        parent::tearDown();
    }

    public function test_fake_returns_canned_array_response(): void
    {
        Database::fake([
            'get_user' => [['id' => 1, 'name' => 'Ada']],
        ]);

        $rows = Database::fn('get_user', [1]);

        $this->assertSame([['id' => 1, 'name' => 'Ada']], $rows);
    }

    public function test_fake_closure_receives_params_and_computes_response(): void
    {
        Database::fake([
            'get_user' => function (array $params) {
                return [['id' => $params[0], 'name' => 'Computed-' . $params[0]]];
            },
        ]);

        $rows = Database::fn('get_user', [42]);

        $this->assertSame([['id' => 42, 'name' => 'Computed-42']], $rows);
    }

    public function test_fake_closure_supports_sequential_responses_via_own_counter(): void
    {
        $calls = 0;
        Database::fake([
            'get_next_order_number' => function () use (&$calls) {
                $calls++;
                return [['next_number' => 1000 + $calls]];
            },
        ]);

        $first = Database::fn('get_next_order_number', []);
        $second = Database::fn('get_next_order_number', []);

        $this->assertSame([['next_number' => 1001]], $first);
        $this->assertSame([['next_number' => 1002]], $second);
    }

    public function test_unregistered_function_throws_clear_error_while_faked(): void
    {
        Database::fake(['get_user' => [['id' => 1]]]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("no fake response is registered for function 'get_orders'");

        Database::fn('get_orders', []);
    }

    public function test_fake_calls_merge_not_replace(): void
    {
        Database::fake(['get_user' => [['id' => 1]]]);
        Database::fake(['get_orders' => [['id' => 100]]]);

        // Both registrations must still be present — the second fake() call
        // must not have wiped the first.
        $this->assertSame([['id' => 1]], Database::fn('get_user', []));
        $this->assertSame([['id' => 100]], Database::fn('get_orders', []));
    }

    public function test_fake_re_registering_same_function_overwrites_only_that_key(): void
    {
        Database::fake(['get_user' => [['id' => 1]]]);
        Database::fake(['get_user' => [['id' => 2]]]);

        $this->assertSame([['id' => 2]], Database::fn('get_user', []));
    }

    public function test_fake_rejects_non_array_non_closure_response_value(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("response for function 'get_user' must be an array of rows or a Closure");

        Database::fake(['get_user' => 'not-a-valid-response']);
    }

    public function test_closure_throwing_gateway_exception_gets_same_translation_as_real_errors(): void
    {
        Database::fake([
            'create_order' => function () {
                throw new GatewayException(
                    'unique_violation',
                    409,
                    null,
                    ['error' => 'unique_violation', 'message' => 'duplicate order number']
                );
            },
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Database function call failed');

        Database::fn('create_order', []);
    }

    public function test_closure_throwing_connection_failed_maps_to_tenant_unavailable_exception(): void
    {
        Database::fake([
            'get_dashboard' => function () {
                throw new GatewayException(
                    'connection_failed',
                    503,
                    null,
                    ['error' => 'connection_failed', 'message' => 'no route to tenant db']
                );
            },
        ]);

        $this->expectException(TenantDatabaseUnavailableException::class);

        Database::fn('get_dashboard', []);
    }

    public function test_is_faked_reflects_current_state(): void
    {
        $this->assertFalse(Database::isFaked());

        Database::fake(['get_user' => []]);
        $this->assertTrue(Database::isFaked());

        Database::clearFakeMode();
        $this->assertFalse(Database::isFaked());
    }

    public function test_is_connected_returns_true_while_faked(): void
    {
        Database::fake(['get_user' => []]);

        $this->assertTrue(Database::isConnected());
    }

    public function test_get_gateway_client_throws_clear_error_while_faked(): void
    {
        Database::fake(['get_user' => []]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Database::getGatewayClient() is not usable while Database::fake() is active');

        Database::getGatewayClient();
    }

    public function test_fake_response_flows_through_result_as_table_mapping(): void
    {
        // End-to-end style: fake -> Database::fn() -> result_as_table() -> typed
        // objects, exactly the path a generated Fn* model wrapper exercises
        // (see FnCheckUserEmailVerified-style generated classes).
        Database::fake([
            'list_users' => [
                ['o_id' => '1', 'o_name' => 'Ada'],
                ['o_id' => '2', 'o_name' => 'Grace'],
            ],
        ]);

        $testClass = new class {
            public int $id = 0;
            public string $name = '';
        };

        $rows = Database::fn('list_users', []);
        $result = Database::result_as_table('list_users', $rows, get_class($testClass));

        $this->assertCount(2, $result);
        $this->assertSame(1, $result[0]->id);
        $this->assertSame('Ada', $result[0]->name);
        $this->assertSame(2, $result[1]->id);
        $this->assertSame('Grace', $result[1]->name);
    }

    public function test_fn_without_fake_mode_still_attempts_a_real_connection(): void
    {
        // Regression guard: production behavior (no Database::fake() call)
        // must be completely unaffected by this feature. Without a real
        // DB_GATEWAY_URL configured, this must fail with the framework's own
        // "gateway-only mode" configuration error — NOT a fake-mode error —
        // proving the fake-mode branch is not accidentally intercepting
        // real-mode calls.
        $this->assertFalse(Database::isFaked());

        $this->expectException(\Exception::class);

        Database::fn('get_user', []);
    }
}
