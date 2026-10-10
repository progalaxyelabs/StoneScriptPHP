<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Database;
use StoneScriptPHP\Database\HydrationException;
use StoneScriptPHP\Database\RowHydrator;

enum HydratorStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}

final class HydratorModel
{
    public int $id;
    public ?string $note;
    public string $amount;
    public float $qty;
    public bool $paid;
    public ?\DateTime $due;
    public ?int $count;
}

final class HydratorStrictModel
{
    public string $name;
}

final class HydratorIntModel
{
    public int $n;
}

final class HydratorBoolModel
{
    public bool $b;
}

final class HydratorEnumModel
{
    public HydratorStatus $status;
}

final class HydratorArrayModel
{
    public array $doc;
}

/**
 * L15 NULL fidelity: SQL NULL stays null end to end in strict mode; legacy mode keeps the
 * old coercion but says so; both are explicit and selectable.
 */
final class RowHydratorTest extends TestCase
{
    protected function setUp(): void
    {
        RowHydrator::reset();
        putenv('DB_HYDRATION_MODE');
        unset($_ENV['DB_HYDRATION_MODE']);
    }

    protected function tearDown(): void
    {
        RowHydrator::reset();
        putenv('DB_HYDRATION_MODE');
        unset($_ENV['DB_HYDRATION_MODE']);
    }

    /** @return list<string> */
    private function captureDeprecations(callable $fn): array
    {
        $seen = [];
        set_error_handler(function (int $no, string $msg) use (&$seen): bool {
            $seen[] = $msg;
            return true;
        }, E_USER_DEPRECATED);
        try {
            $fn();
        } finally {
            restore_error_handler();
        }
        return $seen;
    }

    // ---- mode selection ----

    public function test_default_mode_is_legacy(): void
    {
        $this->assertSame('legacy', RowHydrator::mode());
    }

    public function test_env_selects_strict_and_unknown_falls_back_to_legacy(): void
    {
        putenv('DB_HYDRATION_MODE=strict');
        $this->assertSame('strict', RowHydrator::mode());

        RowHydrator::reset();
        putenv('DB_HYDRATION_MODE=bogus');
        $prev = ini_set('error_log', '/dev/null');
        $this->assertSame('legacy', RowHydrator::mode());
        ini_set('error_log', (string) $prev);
    }

    public function test_bootstrap_config_wins_over_env(): void
    {
        putenv('DB_HYDRATION_MODE=legacy');
        RowHydrator::bootstrap(['db' => ['hydration_mode' => 'strict']]);
        $this->assertSame('strict', RowHydrator::mode());
    }

    public function test_explicit_set_mode_wins_and_validates(): void
    {
        RowHydrator::setMode('strict');
        $this->assertSame('strict', RowHydrator::mode());
        $this->expectException(\InvalidArgumentException::class);
        RowHydrator::setMode('nope');
    }

    // ---- strict: NULL stays NULL ----

    public function test_strict_null_into_nullable_properties_stays_null(): void
    {
        RowHydrator::setMode('strict');
        $m = Database::array_to_class_object('f', [
            'id' => 1, 'note' => null, 'amount' => '10.50', 'qty' => '2.500', 'paid' => 't', 'due' => null, 'count' => null,
        ], HydratorModel::class);

        $this->assertNull($m->note);
        $this->assertNull($m->due);
        $this->assertNull($m->count);
    }

    public function test_strict_null_into_non_nullable_property_throws_with_actionable_message(): void
    {
        RowHydrator::setMode('strict');
        try {
            Database::array_to_class_object('get_thing', ['name' => null], HydratorStrictModel::class);
            $this->fail('expected HydrationException');
        } catch (HydrationException $e) {
            $this->assertStringContainsString('get_thing', $e->getMessage());
            $this->assertStringContainsString('HydratorStrictModel::$name', $e->getMessage());
            $this->assertStringContainsString('?string', $e->getMessage());
            $this->assertStringContainsString('@out name ?string', $e->getMessage());
        }
    }

    public function test_strict_null_int_and_bool_are_not_invented(): void
    {
        RowHydrator::setMode('strict');
        $this->expectException(HydrationException::class);
        Database::array_to_class_object('f', ['n' => null], HydratorIntModel::class);
    }

    public function test_strict_null_bool_not_invented(): void
    {
        RowHydrator::setMode('strict');
        $this->expectException(HydrationException::class);
        Database::array_to_class_object('f', ['b' => null], HydratorBoolModel::class);
    }

    // ---- strict: typed conversions ----

    public function test_strict_converts_wire_values_to_declared_types(): void
    {
        RowHydrator::setMode('strict');
        $m = Database::array_to_class_object('f', [
            'o_id' => '42', 'o_note' => 'x', 'o_amount' => 1200, 'o_qty' => '2.5', 'o_paid' => true,
            'o_due' => '2026-10-10 10:00:00+00', 'o_count' => 3.0,
        ], HydratorModel::class);

        $this->assertSame(42, $m->id);
        $this->assertSame('1200', $m->amount);
        $this->assertSame(2.5, $m->qty);
        $this->assertTrue($m->paid);
        $this->assertInstanceOf(\DateTime::class, $m->due);
        $this->assertSame(3, $m->count);
    }

    public function test_strict_json_float_into_string_property_throws_instead_of_drifting(): void
    {
        RowHydrator::setMode('strict');
        $this->expectException(HydrationException::class);
        $this->expectExceptionMessage('NUMERIC as text');
        Database::array_to_class_object('f', [
            'id' => 1, 'note' => null, 'amount' => 1200.5, 'qty' => 1, 'paid' => false, 'due' => null, 'count' => null,
        ], HydratorModel::class);
    }

    public function test_strict_enum_with_wrong_value_type_is_a_hydration_exception_not_a_typeerror(): void
    {
        RowHydrator::setMode('strict');
        $this->expectException(HydrationException::class);
        Database::array_to_class_object('f', ['status' => ['not', 'scalar']], HydratorEnumModel::class);
    }

    public function test_strict_timestamp_without_zone_is_utc_regardless_of_default_timezone(): void
    {
        RowHydrator::setMode('strict');
        $prev = date_default_timezone_get();
        date_default_timezone_set('Asia/Kolkata');
        try {
            $m = Database::array_to_class_object('f', [
                'id' => 1, 'note' => null, 'amount' => '1', 'qty' => 1, 'paid' => false, 'due' => '2026-10-10 10:00:00', 'count' => null,
            ], HydratorModel::class);
            $this->assertSame('2026-10-10T10:00:00+00:00', $m->due->format('c'));

            $m2 = Database::array_to_class_object('f', [
                'id' => 1, 'note' => null, 'amount' => '1', 'qty' => 1, 'paid' => false, 'due' => '2026-10-10 10:00:00+05:30', 'count' => null,
            ], HydratorModel::class);
            $this->assertSame('2026-10-10T10:00:00+05:30', $m2->due->format('c'), 'an explicit offset is kept');
        } finally {
            date_default_timezone_set($prev);
        }
    }

    public function test_legacy_notice_cannot_throw_even_if_errors_become_exceptions(): void
    {
        RowHydrator::setMode('legacy');
        set_error_handler(static function (int $no, string $msg): never {
            throw new \ErrorException($msg, 0, $no);
        });
        $prev = ini_set('error_log', '/dev/null');
        try {
            $m = Database::array_to_class_object('f', ['name' => null], HydratorStrictModel::class);
            $this->assertSame('', $m->name);
        } finally {
            ini_set('error_log', (string) $prev);
            restore_error_handler();
        }
    }

    public function test_strict_decimal_string_property_keeps_exact_text(): void
    {
        RowHydrator::setMode('strict');
        $m = Database::array_to_class_object('f', [
            'id' => 1, 'note' => null, 'amount' => '12345678901234567.1250', 'qty' => 1, 'paid' => false, 'due' => null, 'count' => null,
        ], HydratorModel::class);
        $this->assertSame('12345678901234567.1250', $m->amount, 'a string-typed NUMERIC is never routed through float');
    }

    public function test_strict_bool_wire_forms(): void
    {
        RowHydrator::setMode('strict');
        foreach ([true, 't', 'true', '1', 1] as $v) {
            $this->assertTrue(Database::array_to_class_object('f', ['b' => $v], HydratorBoolModel::class)->b, var_export($v, true));
        }
        foreach ([false, 'f', 'false', '0', 0] as $v) {
            $this->assertFalse(Database::array_to_class_object('f', ['b' => $v], HydratorBoolModel::class)->b, var_export($v, true));
        }
    }

    public function test_strict_rejects_values_that_do_not_fit(): void
    {
        RowHydrator::setMode('strict');
        foreach ([['n' => '12.5'], ['n' => 'abc'], ['n' => 3.5], ['n' => '99999999999999999999']] as $row) {
            try {
                Database::array_to_class_object('f', $row, HydratorIntModel::class);
                $this->fail('expected HydrationException for ' . json_encode($row));
            } catch (HydrationException $e) {
                $this->assertStringContainsString('HydratorIntModel::$n', $e->getMessage());
            }
        }
        $this->expectException(HydrationException::class);
        Database::array_to_class_object('f', ['b' => 'maybe'], HydratorBoolModel::class);
    }

    public function test_strict_backed_enum_and_json_array(): void
    {
        RowHydrator::setMode('strict');
        $e = Database::array_to_class_object('f', ['status' => 'closed'], HydratorEnumModel::class);
        $this->assertSame(HydratorStatus::Closed, $e->status);

        $a = Database::array_to_class_object('f', ['doc' => '{"a":1}'], HydratorArrayModel::class);
        $this->assertSame(['a' => 1], $a->doc);
        $a2 = Database::array_to_class_object('f', ['doc' => ['b' => 2]], HydratorArrayModel::class);
        $this->assertSame(['b' => 2], $a2->doc);

        $this->expectException(HydrationException::class);
        Database::array_to_class_object('f', ['status' => 'bogus'], HydratorEnumModel::class);
    }

    public function test_strict_missing_column_still_fails(): void
    {
        RowHydrator::setMode('strict');
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('mismatch in function result fields and class properties');
        Database::array_to_class_object('f', [], HydratorStrictModel::class);
    }

    // ---- legacy: unchanged behaviour + notice ----

    public function test_legacy_keeps_historical_coercion_and_warns_once_per_property(): void
    {
        RowHydrator::setMode('legacy');

        $notices = $this->captureDeprecations(function (): void {
            $a = Database::array_to_class_object('get_thing', ['name' => null], HydratorStrictModel::class);
            $this->assertSame('', $a->name);
            $b = Database::array_to_class_object('get_thing', ['name' => null], HydratorStrictModel::class);
            $this->assertSame('', $b->name);
            $this->assertSame(0, Database::array_to_class_object('f', ['n' => null], HydratorIntModel::class)->n);
            $this->assertFalse(Database::array_to_class_object('f', ['b' => null], HydratorBoolModel::class)->b);
        });

        $this->assertCount(3, $notices, 'one notice per class::property, not per row');
        $this->assertStringContainsString('HydratorStrictModel::$name', $notices[0]);
        $this->assertStringContainsString('DEPRECATED', $notices[0]);
        $this->assertStringContainsString('DB_HYDRATION_MODE=strict', $notices[0]);
    }

    public function test_legacy_nullable_properties_never_warn_and_stay_null(): void
    {
        RowHydrator::setMode('legacy');
        $notices = $this->captureDeprecations(function (): void {
            $m = Database::array_to_class_object('f', [
                'id' => 1, 'note' => null, 'amount' => '1', 'qty' => '1', 'paid' => 't', 'due' => null, 'count' => null,
            ], HydratorModel::class);
            $this->assertNull($m->note);
        });
        $this->assertSame([], $notices);
    }

    public function test_result_helpers_all_share_the_mode(): void
    {
        RowHydrator::setMode('strict');
        $rows = [['name' => null]];
        foreach (['result_as_object', 'result_as_single', 'result_as_table', 'result_as_typed_table'] as $method) {
            try {
                Database::$method('f', $rows, HydratorStrictModel::class);
                $this->fail("$method must honour strict mode");
            } catch (HydrationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_union_and_untyped_properties_fail_with_a_proper_exception(): void
    {
        $model = new class {
            public int|string $u = 1;
        };
        $this->expectException(HydrationException::class);
        Database::array_to_class_object('f', ['u' => 1], get_class($model));
    }
}
