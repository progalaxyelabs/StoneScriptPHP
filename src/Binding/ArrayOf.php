<?php

declare(strict_types=1);

namespace StoneScriptPHP\Binding;

use Attribute;

/**
 * Declares the element class for an array-typed DTO constructor parameter
 * or property — PHP cannot express `RowInput[]` in a type hint, so this
 * attribute is the PHPStan-checkable, non-regex-fragile way to say "this
 * `array $rows` parameter is a list of `RowInput`" for {@see DtoHydrator}.
 *
 * Preferred over the `@param RowInput[] $rows` docblock convention (which
 * DtoHydrator still supports as a zero-edit fallback for existing DTOs)
 * because it is statically checkable and immune to docblock drift/typos.
 *
 * Usage (legacy — `array $rows`, bare array, back-compat, unchanged):
 *   public function __construct(
 *       #[ArrayOf(DistributorInvoiceRowInput::class)]
 *       public readonly array $rows,
 *   ) {}
 *
 * Usage (typed — `TypedArray $rows`, new: hydrates to {@see TypedArray} of
 * $class; $class may also be a scalar keyword int|float|bool|string for a
 * homogeneous scalar list):
 *   public function __construct(
 *       #[ArrayOf(DistributorInvoiceRowInput::class)]
 *       public readonly TypedArray $rows,
 *   ) {}
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class ArrayOf
{
    /**
     * @param class-string $class Fully-qualified element class name.
     */
    public function __construct(
        public readonly string $class,
    ) {
    }
}
