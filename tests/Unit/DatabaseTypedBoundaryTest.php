<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Binding\TypedArray;
use StoneScriptPHP\Database;

/**
 * The "Database typed boundary" — Database::fn() is the ONE place that
 * marshals TypedArray/typed-objects <-> the PG wire (serializeParams(), see
 * its docblock), and the successor result methods
 * (result_as_typed_table()/result_as_object()) are the sanctioned way to
 * consume what comes back. This is deprecate-then-migrate: a raw-array data
 * param still works byte-for-byte as before, it just now emits an
 * E_USER_DEPRECATED notice steering new code toward TypedArray/DTOs.
 */
final class DatabaseTypedBoundaryTest extends TestCase
{
    protected function tearDown(): void
    {
        Database::clearFakeMode();
        parent::tearDown();
    }

    public function test_raw_nonempty_array_data_param_triggers_deprecation_but_still_works(): void
    {
        $received = null;
        Database::fake([
            'typed_boundary_test_fn' => function (array $params) use (&$received): array {
                $received = $params;
                return [['ok' => true]];
            },
        ]);

        $deprecations = [];
        set_error_handler(function (int $errno, string $errstr) use (&$deprecations): bool {
            $deprecations[] = $errstr;
            return true;
        }, E_USER_DEPRECATED);

        $result = Database::fn('typed_boundary_test_fn', [123, ['a', 'b', 'c']]);
        restore_error_handler();

        $this->assertNotEmpty($deprecations, 'a raw non-empty array data param must emit an E_USER_DEPRECATED notice');
        $this->assertStringContainsString('TypedArray', $deprecations[0]);
        $this->assertStringContainsString('DEPRECATED', $deprecations[0]);

        // Unchanged wire behavior: the raw array is still passed through as-is.
        $this->assertSame([123, ['a', 'b', 'c']], $received);
        $this->assertSame([['ok' => true]], $result);
    }

    public function test_empty_array_data_param_does_not_trigger_deprecation(): void
    {
        Database::fake([
            'typed_boundary_test_fn' => fn (array $params): array => [['ok' => true]],
        ]);

        $deprecationCount = 0;
        set_error_handler(function () use (&$deprecationCount): bool {
            $deprecationCount++;
            return true;
        }, E_USER_DEPRECATED);

        Database::fn('typed_boundary_test_fn', [123, []]);
        restore_error_handler();

        $this->assertSame(0, $deprecationCount, 'an empty array ([]) is exempt — it is indistinguishable from "no elements"');
    }

    public function test_scalar_data_params_do_not_trigger_deprecation(): void
    {
        Database::fake([
            'typed_boundary_test_fn' => fn (array $params): array => [['ok' => true]],
        ]);

        $deprecationCount = 0;
        set_error_handler(function () use (&$deprecationCount): bool {
            $deprecationCount++;
            return true;
        }, E_USER_DEPRECATED);

        Database::fn('typed_boundary_test_fn', [1, 'x', 9.5, true, null]);
        restore_error_handler();

        $this->assertSame(0, $deprecationCount);
    }

    public function test_typed_array_data_param_serializes_to_json_with_no_deprecation(): void
    {
        $received = null;
        Database::fake([
            'typed_boundary_test_fn' => function (array $params) use (&$received): array {
                $received = $params;
                return [['ok' => true]];
            },
        ]);

        $deprecationCount = 0;
        set_error_handler(function () use (&$deprecationCount): bool {
            $deprecationCount++;
            return true;
        }, E_USER_DEPRECATED);

        $typed = new TypedArray('string', ['x', 'y']);
        Database::fn('typed_boundary_test_fn', [42, $typed]);
        restore_error_handler();

        $this->assertSame(0, $deprecationCount, 'a TypedArray data param is the sanctioned path — no deprecation');
        $this->assertSame(42, $received[0]);
        $this->assertIsString($received[1], 'a TypedArray param must be marshalled to a JSON string, not passed as an object/array');
        $this->assertSame(['x', 'y'], json_decode($received[1], true));
    }

    public function test_fnTyped_reflects_params_object_into_correctly_ordered_positional_call(): void
    {
        $received = null;
        Database::fake([
            'create_widget' => function (array $params) use (&$received): array {
                $received = $params;
                return [['ok' => true]];
            },
        ]);

        // Declaration order is c, a, b -- deliberately non-alphabetical, to
        // prove Database::fnTyped() reflects DECLARATION order, not sorted
        // order or any other incidental ordering.
        $params = new class {
            public ?string $p_c = 'hello';
            public string $p_a = 'A1';
            public int $p_b = 7;
        };

        Database::fnTyped('create_widget', $params);

        $this->assertSame(['hello', 'A1', 7], $received);
    }

    public function test_fnTyped_serializes_typed_array_property_to_json(): void
    {
        $received = null;
        Database::fake([
            'create_widget_with_rows' => function (array $params) use (&$received): array {
                $received = $params;
                return [['ok' => true]];
            },
        ]);

        $params = new class {
            public string $p_name = 'widget';
            public TypedArray $p_tags;

            public function __construct()
            {
                $this->p_tags = new TypedArray('string', ['red', 'blue']);
            }
        };

        Database::fnTyped('create_widget_with_rows', $params);

        $this->assertSame('widget', $received[0]);
        $this->assertIsString($received[1]);
        $this->assertSame(['red', 'blue'], json_decode($received[1], true));
    }

    public function test_fnTyped_nullable_defaulted_property_passes_through_as_null(): void
    {
        $received = null;
        Database::fake([
            'create_widget' => function (array $params) use (&$received): array {
                $received = $params;
                return [['ok' => true]];
            },
        ]);

        $params = new class {
            public ?string $p_c = null;
            public string $p_a = 'A1';
            public int $p_b = 1;
        };

        Database::fnTyped('create_widget', $params);

        $this->assertSame([null, 'A1', 1], $received);
    }

    public function test_jsonserializable_dto_data_param_serializes_to_json(): void
    {
        $dto = new class implements \JsonSerializable {
            public function jsonSerialize(): array
            {
                return ['sku' => 'A1', 'qty' => 2];
            }
        };

        $received = null;
        Database::fake([
            'typed_boundary_test_fn' => function (array $params) use (&$received): array {
                $received = $params;
                return [['ok' => true]];
            },
        ]);

        Database::fn('typed_boundary_test_fn', [$dto]);

        $this->assertIsString($received[0]);
        $this->assertSame(['sku' => 'A1', 'qty' => 2], json_decode($received[0], true));
    }
}
