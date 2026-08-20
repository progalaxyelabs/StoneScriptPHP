<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Binding\ArrayOf;
use StoneScriptPHP\Binding\BindingException;
use StoneScriptPHP\Binding\DtoHydrator;
use StoneScriptPHP\Binding\TypedArray;
use StoneScriptPHP\Binding\UnsupportedDtoShapeException;

enum HydratorTestStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}

final class HydratorTestRow
{
    public function __construct(
        public readonly string $sku,
        public readonly float $qty,
    ) {
    }
}

final class HydratorTestNested
{
    public function __construct(
        public readonly string $city,
        public readonly ?string $zip = null,
    ) {
    }
}

final class HydratorTestRequest
{
    public function __construct(
        public readonly int $id,
        public readonly float $price,
        public readonly bool $active,
        public readonly string $name,
        public readonly ?string $note = null,
        public readonly string $withDefault = 'fallback',
        public readonly ?HydratorTestNested $address = null,
        public readonly ?HydratorTestStatus $status = null,
        #[ArrayOf(HydratorTestRow::class)]
        public readonly array $rows = [],
    ) {
    }
}

/** @param HydratorTestRow[] $rows */
final class HydratorTestDocblockRequest
{
    /**
     * @param HydratorTestRow[] $rows
     */
    public function __construct(
        public readonly array $rows,
    ) {
    }
}

final class HydratorTestRequiredNested
{
    public function __construct(
        public readonly HydratorTestNested $address,
    ) {
    }
}

final class HydratorTestPlainArray
{
    public function __construct(
        public readonly array $bag,
    ) {
    }
}

final class HydratorTestUnionUnsupported
{
    public function __construct(
        public readonly int|string $mixed,
    ) {
    }
}

/** A `TypedArray`-typed constructor param — the new (additive) binding path
 * alongside the legacy `array $x` + #[ArrayOf] case above. */
final class HydratorTestTypedArrayOfObjects
{
    public function __construct(
        #[ArrayOf(HydratorTestRow::class)]
        public readonly TypedArray $rows,
    ) {
    }
}

final class HydratorTestTypedArrayOfScalars
{
    public function __construct(
        #[ArrayOf('string')]
        public readonly TypedArray $tags,
    ) {
    }
}

final class HydratorTestTypedArrayMissingAttribute
{
    public function __construct(
        public readonly TypedArray $rows,
    ) {
    }
}

/**
 * The authoritative test matrix for StoneScriptPHP\Binding\DtoHydrator —
 * see SPEC-typed-request-binder.md §9 for the checklist this file
 * implements.
 */
final class DtoHydratorTest extends TestCase
{
    private function baseValid(): array
    {
        return ['id' => 1, 'price' => 9.5, 'active' => true, 'name' => 'x'];
    }

    // ── scalars ──────────────────────────────────────────────────────

    public function test_scalar_ok(): void
    {
        $r = DtoHydrator::hydrate(HydratorTestRequest::class, $this->baseValid());
        $this->assertSame(1, $r->id);
        $this->assertSame(9.5, $r->price);
        $this->assertTrue($r->active);
        $this->assertSame('x', $r->name);
    }

    public function test_int_coerced_from_numeric_string(): void
    {
        $data = $this->baseValid();
        $data['id'] = '42';
        $r = DtoHydrator::hydrate(HydratorTestRequest::class, $data);
        $this->assertSame(42, $r->id);
    }

    public function test_int_wrong_type_rejects_non_numeric_string_400(): void
    {
        $data = $this->baseValid();
        $data['id'] = 'abc';
        $this->expectException(BindingException::class);
        DtoHydrator::hydrate(HydratorTestRequest::class, $data);
    }

    public function test_int_never_silently_truncates_a_float_string(): void
    {
        $data = $this->baseValid();
        $data['id'] = '12.5';
        try {
            DtoHydrator::hydrate(HydratorTestRequest::class, $data);
            $this->fail('expected BindingException — "12.5" must not silently become 12');
        } catch (BindingException $e) {
            $this->assertSame('id', $e->errors()[0]['field']);
        }
    }

    public function test_float_coerced_from_numeric_string(): void
    {
        $data = $this->baseValid();
        $data['price'] = '9.99';
        $r = DtoHydrator::hydrate(HydratorTestRequest::class, $data);
        $this->assertSame(9.99, $r->price);
    }

    public function test_float_wrong_type_400(): void
    {
        $data = $this->baseValid();
        $data['price'] = 'not-a-number';
        $this->expectException(BindingException::class);
        DtoHydrator::hydrate(HydratorTestRequest::class, $data);
    }

    public function test_bool_coerced_from_string(): void
    {
        $data = $this->baseValid();
        $data['active'] = 'false';
        $r = DtoHydrator::hydrate(HydratorTestRequest::class, $data);
        $this->assertFalse($r->active);
    }

    public function test_bool_wrong_type_400(): void
    {
        $data = $this->baseValid();
        $data['active'] = 'maybe';
        $this->expectException(BindingException::class);
        DtoHydrator::hydrate(HydratorTestRequest::class, $data);
    }

    public function test_string_wrong_type_400(): void
    {
        $data = $this->baseValid();
        $data['name'] = ['not', 'a', 'string'];
        $this->expectException(BindingException::class);
        DtoHydrator::hydrate(HydratorTestRequest::class, $data);
    }

    // ── nullable / defaults / required ──────────────────────────────

    public function test_nullable_present_null_ok(): void
    {
        $data = $this->baseValid();
        $data['note'] = null;
        $r = DtoHydrator::hydrate(HydratorTestRequest::class, $data);
        $this->assertNull($r->note);
    }

    public function test_absent_with_default_uses_default(): void
    {
        $r = DtoHydrator::hydrate(HydratorTestRequest::class, $this->baseValid());
        $this->assertSame('fallback', $r->withDefault);
    }

    public function test_absent_required_no_default_400(): void
    {
        $data = $this->baseValid();
        unset($data['name']);
        try {
            DtoHydrator::hydrate(HydratorTestRequest::class, $data);
            $this->fail('expected BindingException');
        } catch (BindingException $e) {
            $this->assertSame('name', $e->errors()[0]['field']);
            $this->assertStringContainsString('required', $e->errors()[0]['message']);
        }
    }

    public function test_present_null_non_nullable_400(): void
    {
        $data = $this->baseValid();
        $data['name'] = null;
        try {
            DtoHydrator::hydrate(HydratorTestRequest::class, $data);
            $this->fail('expected BindingException');
        } catch (BindingException $e) {
            $this->assertSame('name', $e->errors()[0]['field']);
            $this->assertStringContainsString('null', $e->errors()[0]['message']);
        }
    }

    // ── nested DTO ───────────────────────────────────────────────────

    public function test_nested_dto_ok(): void
    {
        $data = $this->baseValid();
        $data['address'] = ['city' => 'Bengaluru'];
        $r = DtoHydrator::hydrate(HydratorTestRequest::class, $data);
        $this->assertInstanceOf(HydratorTestNested::class, $r->address);
        $this->assertSame('Bengaluru', $r->address->city);
        $this->assertNull($r->address->zip);
    }

    public function test_nested_dto_error_bubbles_with_field_name(): void
    {
        $data = ['address' => ['zip' => '560001']]; // city missing (required)
        try {
            DtoHydrator::hydrate(HydratorTestRequiredNested::class, $data);
            $this->fail('expected BindingException');
        } catch (BindingException $e) {
            $this->assertSame('city', $e->errors()[0]['field']);
        }
    }

    // ── array-of-DTO via #[ArrayOf] ──────────────────────────────────

    public function test_array_of_dto_via_attribute_ok(): void
    {
        $data = $this->baseValid();
        $data['rows'] = [
            ['sku' => 'A', 'qty' => 1],
            ['sku' => 'B', 'qty' => 2.5],
        ];
        $r = DtoHydrator::hydrate(HydratorTestRequest::class, $data);
        $this->assertCount(2, $r->rows);
        $this->assertInstanceOf(HydratorTestRow::class, $r->rows[0]);
        $this->assertSame('A', $r->rows[0]->sku);
        $this->assertSame(2.5, $r->rows[1]->qty);
    }

    public function test_array_of_dto_collects_all_row_errors_not_fail_fast(): void
    {
        $data = $this->baseValid();
        $data['rows'] = [
            ['sku' => 'A'],       // missing qty
            ['sku' => 'B', 'qty' => 'notanumber'], // wrong type
        ];
        try {
            DtoHydrator::hydrate(HydratorTestRequest::class, $data);
            $this->fail('expected BindingException');
        } catch (BindingException $e) {
            $errors = $e->errors();
            $this->assertCount(2, $errors, 'both row errors must be collected, not fail-fast on the first');
            $this->assertSame(1, $errors[0]['line']);
            $this->assertSame('qty', $errors[0]['field']);
            $this->assertSame(2, $errors[1]['line']);
        }
    }

    public function test_array_of_non_array_input_400(): void
    {
        $data = $this->baseValid();
        $data['rows'] = 'not-an-array';
        $this->expectException(BindingException::class);
        DtoHydrator::hydrate(HydratorTestRequest::class, $data);
    }

    // ── array-of-DTO via @param docblock fallback ────────────────────

    public function test_array_of_dto_via_docblock_fallback_ok(): void
    {
        $r = DtoHydrator::hydrate(HydratorTestDocblockRequest::class, [
            'rows' => [['sku' => 'X', 'qty' => 3]],
        ]);
        $this->assertInstanceOf(HydratorTestRow::class, $r->rows[0]);
        $this->assertSame('X', $r->rows[0]->sku);
    }

    // ── backed enum ───────────────────────────────────────────────────

    public function test_backed_enum_ok(): void
    {
        $data = $this->baseValid();
        $data['status'] = 'active';
        $r = DtoHydrator::hydrate(HydratorTestRequest::class, $data);
        $this->assertSame(HydratorTestStatus::Active, $r->status);
    }

    public function test_backed_enum_invalid_400(): void
    {
        $data = $this->baseValid();
        $data['status'] = 'bogus';
        try {
            DtoHydrator::hydrate(HydratorTestRequest::class, $data);
            $this->fail('expected BindingException');
        } catch (BindingException $e) {
            $this->assertSame('status', $e->errors()[0]['field']);
        }
    }

    // ── plain array (no ArrayOf) — legacy `public ?array` compat ─────

    public function test_plain_array_property_passthrough_untouched(): void
    {
        $r = DtoHydrator::hydrate(HydratorTestPlainArray::class, ['bag' => ['anything', 'goes', 1, 2]]);
        $this->assertSame(['anything', 'goes', 1, 2], $r->bag);
    }

    // ── root shape ─────────────────────────────────────────────────

    public function test_root_not_an_array_400(): void
    {
        $this->expectException(BindingException::class);
        DtoHydrator::hydrate(HydratorTestRequest::class, 'not-an-array');
    }

    // ── unsupported DTO shape (dev-time, not a 400) ───────────────────

    public function test_unsupported_union_type_throws_dev_time_exception_not_binding_exception(): void
    {
        $this->expectException(UnsupportedDtoShapeException::class);
        DtoHydrator::hydrate(HydratorTestUnionUnsupported::class, ['mixed' => 5]);
    }

    // ── TypedArray-typed parameter (new, additive) ────────────────────

    public function test_typed_array_param_hydrates_to_typed_array_of_dto(): void
    {
        $r = DtoHydrator::hydrate(HydratorTestTypedArrayOfObjects::class, [
            'rows' => [
                ['sku' => 'A1', 'qty' => 2.0],
                ['sku' => 'B2', 'qty' => 3.5],
            ],
        ]);

        $this->assertInstanceOf(TypedArray::class, $r->rows);
        $this->assertSame(HydratorTestRow::class, $r->rows->type());
        $this->assertSame(2, $r->rows->count());
        $this->assertInstanceOf(HydratorTestRow::class, $r->rows->first());
        $this->assertSame('A1', $r->rows->first()->sku);
        $this->assertSame('B2', $r->rows->last()->sku);
    }

    public function test_typed_array_param_hydrates_to_typed_array_of_scalars(): void
    {
        $r = DtoHydrator::hydrate(HydratorTestTypedArrayOfScalars::class, ['tags' => ['a', 'b', 'c']]);

        $this->assertInstanceOf(TypedArray::class, $r->tags);
        $this->assertSame('string', $r->tags->type());
        $this->assertSame(['a', 'b', 'c'], $r->tags->all());
    }

    public function test_typed_array_param_rejects_bad_row_with_field_error(): void
    {
        try {
            DtoHydrator::hydrate(HydratorTestTypedArrayOfObjects::class, [
                'rows' => [['sku' => 'A1', 'qty' => 'not-a-number']],
            ]);
            $this->fail('expected BindingException');
        } catch (BindingException $e) {
            $this->assertSame('qty', $e->errors()[0]['field']);
        }
    }

    public function test_typed_array_param_without_array_of_attribute_is_dev_time_error(): void
    {
        $this->expectException(UnsupportedDtoShapeException::class);
        DtoHydrator::hydrate(HydratorTestTypedArrayMissingAttribute::class, ['rows' => [['sku' => 'A1', 'qty' => 1.0]]]);
    }

    public function test_typed_array_param_non_list_value_is_binding_error(): void
    {
        $this->expectException(BindingException::class);
        DtoHydrator::hydrate(HydratorTestTypedArrayOfObjects::class, ['rows' => 'not-an-array']);
    }
}
