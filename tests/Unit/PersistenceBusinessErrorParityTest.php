<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptDB\GatewayException;
use StoneScriptPHP\Database;
use StoneScriptPHP\Persistence\DbErrorMapper;
use StoneScriptPHP\Persistence\PersistenceException;
use StoneScriptPHP\Persistence\PublicError;

/**
 * PARITY: the business-rule translations applications often hand-roll in per-route exception
 * translators (substring match on a function's RAISE text -> a clean 4xx with a friendly message) must be
 * expressible in the framework convention with identical status + message, and without ever leaking the
 * raw text. Each case is run through BOTH supported mechanisms:
 *   A. a platform classifier registered once with DbErrorMapper::extend()
 *   B. the function-side `[public:code:status]` RAISE marker (no platform PHP at all)
 * A platform may delete its local copies only while this parity holds for each of its cases.
 */
final class PersistenceBusinessErrorParityTest extends TestCase
{
    /** @return array<string, array{0:string,1:int,2:string,3:string}> raw RAISE wording, status, public message, code */
    public static function cases(): array
    {
        return [
            'expired voucher (expired on)'  => ['Failed to create order: Voucher 7 expired on 2026-01-01 (SQLSTATE: P0001)', 400, 'This voucher has expired and cannot be applied. Please select a different voucher.', 'voucher_expired'],
            'expired voucher (cannot be applied)' => ['Voucher cannot be applied: voucher past expiry', 400, 'This voucher has expired and cannot be applied. Please select a different voucher.', 'voucher_expired'],
            'insufficient quantity'       => ['Insufficient quantity in selected warehouse (have 2, need 5)', 400, 'Not enough quantity available for this order', 'insufficient_quantity'],
            'missing line ids'            => ['Each line must have product_id and variant_id', 400, 'Each line must have a product and variant selected', 'line_required'],
            'empty order'                 => ['Cannot create an empty order', 400, 'Please add at least one item to the order', 'empty_order'],
            'variant not found'           => ['variant 9 not found or does not belong to product_id 4', 400, 'Selected variant could not be found for this product - please re-select', 'variant_not_found'],
            'quantity'                    => ['Quantity must be greater than 0', 400, 'Quantity must be greater than 0', 'bad_quantity'],
            'amount cap'                  => ['Order amount cannot exceed list total', 400, 'Order amount cannot exceed the list total for this product', 'amount_cap'],
        ];
    }

    protected function setUp(): void
    {
        DbErrorMapper::clearExtensions();
    }

    protected function tearDown(): void
    {
        DbErrorMapper::clearExtensions();
        Database::clearFakeMode();
    }

    private function gw(string $cause): GatewayException
    {
        return new GatewayException('query failed', 400, null, ['error' => 'query_failed', 'cause' => $cause]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cases')]
    public function test_mechanism_a_platform_classifier_gives_identical_status_and_message(string $raw, int $status, string $message, string $code): void
    {
        $needle = strtolower(match ($code) {
            'voucher_expired' => str_contains(strtolower($raw), 'expired on') ? 'expired on' : 'cannot be applied',
            'insufficient_quantity' => 'insufficient quantity in selected warehouse',
            'line_required' => 'must have product_id and variant_id',
            'empty_order' => 'cannot create an empty order',
            'variant_not_found' => 'not found or does not belong to product_id',
            'bad_quantity' => 'quantity must be greater than 0',
            'amount_cap' => 'cannot exceed list total',
        });
        DbErrorMapper::extend(fn (string $lower): ?array => str_contains($lower, $needle) ? [$status, $message] : null);

        Database::fake(['create_order' => function () use ($raw): never {
            throw $this->gw($raw);
        }]);
        try {
            Database::mutate('create_order', ['{}']);
            $this->fail('must throw');
        } catch (PersistenceException $e) {
            $r = DbErrorMapper::toResponse($e);
            $this->assertSame($status, $r->httpStatusCode);
            $this->assertSame($message, $r->message);
            $this->assertTrue($e->isPublic());
            if ($raw !== $message) {
                $this->assertStringNotContainsString($raw, $r->toJson());
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cases')]
    public function test_mechanism_b_function_side_raise_marker_gives_identical_status_and_message(string $raw, int $status, string $message, string $code): void
    {
        // The function is edited to: RAISE EXCEPTION '[public:<code>:<status>] <message>'
        $wrapped = "Failed to create order: [public:$code:$status] $message (SQLSTATE: P0001)";
        Database::fake(['create_order' => function () use ($wrapped): never {
            throw $this->gw($wrapped);
        }]);
        try {
            Database::mutate('create_order', ['{}']);
            $this->fail('must throw');
        } catch (PersistenceException $e) {
            $r = DbErrorMapper::toResponse($e);
            $this->assertSame($status, $r->httpStatusCode);
            $this->assertSame($message, $r->message);
            $this->assertSame($code, $r->data['error_code']);
        }
    }

    public function test_unrecognised_function_error_is_a_generic_500_that_hides_the_text(): void
    {
        Database::fake(['create_order' => function (): never {
            throw $this->gw('Failed to create order: some internal explosion at line 44');
        }]);
        try {
            Database::mutate('create_order', ['{}']);
            $this->fail();
        } catch (PersistenceException $e) {
            $r = DbErrorMapper::toResponse($e);
            $this->assertSame(500, $r->httpStatusCode);
            $this->assertStringNotContainsString('explosion', $r->toJson());
            $this->assertStringContainsString('explosion', $e->getPrevious()?->getMessage() . DbErrorMapper::rawCause($e->getPrevious()), 'cause kept for inspection');
        }
    }

    public function test_duplicate_order_number_409_with_dynamic_message_and_field_errors(): void
    {
        // A route-level catch: the order number comes from the request, the constraint name from the cause.
        $orderNumber = 'ORD-77';
        $orderDate = '2026-10-01';
        Database::fake(['update_order' => function (): never {
            throw $this->gw('duplicate key value violates unique constraint "orders_order_number_key" | DETAIL: Key (order_number)=(ORD-77) already exists.');
        }]);
        try {
            Database::mutate('update_order', ['{}']);
            $this->fail();
        } catch (PersistenceException $e) {
            $response = DbErrorMapper::causeContains($e, 'orders_order_number_key')
                ? DbErrorMapper::publicResponse(
                    409,
                    "Order number \"{$orderNumber}\" (dated {$orderDate}) is already used by another order. Please choose a different order number.",
                    null,
                    [['line' => null, 'field' => 'order_number', 'message' => 'This order number is already in use']]
                )
                : DbErrorMapper::toResponse($e, 'order');

            $this->assertSame(409, $response->httpStatusCode);
            $this->assertSame('Order number "ORD-77" (dated 2026-10-01) is already used by another order. Please choose a different order number.', $response->message);
            $this->assertSame([['line' => null, 'field' => 'order_number', 'message' => 'This order number is already in use']], $response->errors);
        }
    }

    public function test_same_duplicate_without_a_route_catch_is_the_generic_409_not_the_raw_text(): void
    {
        $r = DbErrorMapper::toResponse($this->gw('duplicate key value violates unique constraint "orders_order_number_key" | DETAIL: Key (order_number)=(ORD-77) already exists.'), 'order');
        $this->assertSame(409, $r->httpStatusCode);
        $this->assertSame('A order with these details already exists.', $r->message);
        $this->assertStringNotContainsString('ORD-77', $r->toJson());
    }

    public function test_global_extension_can_emit_the_order_409_with_data_and_errors(): void
    {
        DbErrorMapper::extend(fn (string $lower): ?PublicError => str_contains($lower, 'orders_order_number_key')
            ? new PublicError(409, 'This order number is already used by another order. Please choose a different order number.', null,
                [['line' => null, 'field' => 'order_number', 'message' => 'This order number is already in use']], 'duplicate_order_number')
            : null);
        $r = DbErrorMapper::toResponse($this->gw('duplicate key value violates unique constraint "orders_order_number_key"'));
        $this->assertSame(409, $r->httpStatusCode);
        $this->assertSame('order_number', $r->errors[0]['field']);
    }
}
