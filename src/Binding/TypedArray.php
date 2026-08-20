<?php

declare(strict_types=1);

namespace StoneScriptPHP\Binding;

/**
 * A generic, runtime-checked typed array — PHP's `Array<T>`.
 *
 * PHP has no real generics and `array` carries no element type, so a plain
 * `array $rows` is `mixed[]` at runtime: nothing stops a stray non-`RowInput`
 * from ending up in it, and static analysis only knows "array". `TypedArray<T>`
 * closes both gaps:
 *
 *   - **Runtime:** every element is validated against `T` on construction and
 *     on every `with()` — a `TypedArray` of `RowInput` can NEVER hold anything
 *     but `RowInput` instances. A wrong element is a loud {@see \TypeError} at
 *     the point it's added, not a silent corruption discovered three layers
 *     later.
 *   - **Static:** the `@template T` + `@implements` annotations make PHPStan
 *     treat it as a true generic — `$rows->first()` is known to be `?RowInput`,
 *     `foreach ($rows as $r)` types `$r` as `RowInput`, with zero casts.
 *
 * `T` may be a class/interface name (validated with `instanceof`) or one of the
 * builtin scalar keywords `int|float|bool|string|array|object` (validated with
 * the matching `is_*`). `float` also accepts `int` (widening), matching
 * {@see DtoHydrator}'s scalar rules.
 *
 * Immutable: `with()`/`filter()`/`map()` return new values, and the
 * ArrayAccess mutators throw. This mirrors the readonly-DTO house style — a
 * hydrated request object never mutates after binding.
 *
 * Integrates with the typed-request binder: {@see DtoHydrator} produces a
 * `TypedArray<T>` for a constructor parameter typed `TypedArray` whose element
 * class is declared via {@see ArrayOf} (or a `@param TypedArray<T> $name`
 * docblock), and {@see \JsonSerialize} makes it serialize as a bare JSON array
 * on the way back out through `res_ok()`.
 *
 * @template T
 * @implements \IteratorAggregate<int, T>
 * @implements \ArrayAccess<int, T>
 */
final class TypedArray implements \IteratorAggregate, \Countable, \ArrayAccess, \JsonSerializable
{
    private const BUILTINS = ['int', 'float', 'bool', 'string', 'array', 'object'];

    /**
     * Fully-qualified class/interface name, or a builtin scalar keyword.
     *
     * @var class-string<T>|'int'|'float'|'bool'|'string'|'array'|'object'
     */
    private readonly string $type;

    /** @var list<T> */
    private array $items;

    /**
     * @param class-string<T>|'int'|'float'|'bool'|'string'|'array'|'object' $type
     *   Element type: a class/interface name, or a builtin scalar keyword.
     * @param iterable<T> $items Initial elements — each validated against $type.
     *
     * @throws \InvalidArgumentException If $type is neither a builtin keyword
     *   nor an existing class/interface (a programming error, thrown eagerly).
     * @throws \TypeError If any element is not of type $type.
     */
    public function __construct(string $type, iterable $items = [])
    {
        if (!in_array($type, self::BUILTINS, true) && !class_exists($type) && !interface_exists($type)) {
            throw new \InvalidArgumentException(
                "TypedArray: element type '$type' is not a builtin scalar keyword (" .
                implode('|', self::BUILTINS) . ') or an existing class/interface.'
            );
        }

        $this->type = $type;
        $this->items = [];

        $index = 0;
        foreach ($items as $item) {
            $this->assertType($item, $index);
            $this->items[] = $item;
            $index++;
        }
    }

    /**
     * Convenience factory — reads more naturally at call sites than `new`.
     *
     * @template U
     * @param class-string<U>|'int'|'float'|'bool'|'string'|'array'|'object' $type
     * @param iterable<U> $items
     * @return self<U>
     */
    public static function of(string $type, iterable $items = []): self
    {
        return new self($type, $items);
    }

    /**
     * The element type this array was created with (class-string or keyword).
     *
     * @return class-string<T>|'int'|'float'|'bool'|'string'|'array'|'object'
     */
    public function type(): string
    {
        return $this->type;
    }

    /**
     * Return a NEW TypedArray with $items appended (this instance is unchanged).
     *
     * @param T ...$items
     * @return self<T>
     */
    public function with(mixed ...$items): self
    {
        return new self($this->type, [...$this->items, ...$items]);
    }

    /**
     * @return list<T>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * @return T|null
     */
    public function first(): mixed
    {
        return $this->items[0] ?? null;
    }

    /**
     * @return T|null
     */
    public function last(): mixed
    {
        return $this->items === [] ? null : $this->items[array_key_last($this->items)];
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Map every element to $fn's return value. The result is an untyped list
     * (the mapped type is arbitrary), NOT a TypedArray — use the constructor
     * if you want to re-wrap in a known type.
     *
     * @template R
     * @param callable(T, int): R $fn
     * @return list<R>
     */
    public function map(callable $fn): array
    {
        $out = [];
        foreach ($this->items as $i => $item) {
            $out[] = $fn($item, $i);
        }
        return $out;
    }

    /**
     * Return a NEW TypedArray<T> keeping only elements for which $fn is truthy.
     *
     * @param callable(T, int): bool $fn
     * @return self<T>
     */
    public function filter(callable $fn): self
    {
        $kept = [];
        foreach ($this->items as $i => $item) {
            if ($fn($item, $i)) {
                $kept[] = $item;
            }
        }
        return new self($this->type, $kept);
    }

    /**
     * @return \Traversable<int, T>
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->items);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    /**
     * @return T
     */
    public function offsetGet(mixed $offset): mixed
    {
        if (!array_key_exists($offset, $this->items)) {
            throw new \OutOfRangeException("TypedArray: no element at offset " . var_export($offset, true));
        }
        return $this->items[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): never
    {
        throw new \LogicException('TypedArray is immutable — use with() to derive a new instance.');
    }

    public function offsetUnset(mixed $offset): never
    {
        throw new \LogicException('TypedArray is immutable — use filter() to derive a new instance.');
    }

    /**
     * Serialize as a bare JSON array (so a TypedArray<T> in a response DTO goes
     * out over the wire indistinguishably from a plain list).
     *
     * @return list<T>
     */
    public function jsonSerialize(): array
    {
        return $this->items;
    }

    /**
     * @param T $item
     * @throws \TypeError
     */
    private function assertType(mixed $item, int $index): void
    {
        $ok = match ($this->type) {
            'int' => is_int($item),
            'float' => is_float($item) || is_int($item),
            'bool' => is_bool($item),
            'string' => is_string($item),
            'array' => is_array($item),
            'object' => is_object($item),
            default => $item instanceof $this->type,
        };

        if (!$ok) {
            $actual = get_debug_type($item);
            throw new \TypeError(
                "TypedArray<{$this->type}>: element at index $index must be of type {$this->type}, $actual given."
            );
        }
    }
}
