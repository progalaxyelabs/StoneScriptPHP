<?php

declare(strict_types=1);

namespace StoneScriptPHP\Binding;

/**
 * Generic, recursive typed-DTO hydrator.
 *
 * Given a target class and a raw decoded-JSON/form array, reflects the
 * class's constructor and hydrates each parameter — scalars (strict,
 * never a silent wrong-cast), nested userland-class DTOs (recursive),
 * arrays-of-DTO (via the {@see ArrayOf} attribute, or a `@param X[] $name`
 * constructor docblock fallback), and backed enums (`Enum::from()`).
 *
 * Design + the full test matrix are documented in
 * SPEC-typed-request-binder.md (framework repo root's companion doc,
 * committed alongside this class) — read that first if you're modifying
 * this file's binding rules.
 *
 * Every error encountered anywhere in the object graph is collected before
 * throwing (never fail-fast) so a single {@see BindingException} reports
 * every broken field/row in one 400 response — required for the
 * multi-row-invoice shape this was built to replace hand-rolled validators
 * for.
 */
final class DtoHydrator
{
    /**
     * @param class-string $class
     * @param mixed $data Raw decoded request payload for this object (must
     *   be an associative array — scalars/lists are rejected).
     * @return object An instance of $class.
     * @throws BindingException On any shape/type/presence problem — never
     *   thrown for business-rule issues (those stay in handler code).
     * @throws UnsupportedDtoShapeException On a DTO authoring mistake this
     *   hydrator cannot express (dev-time, not user-facing).
     */
    public static function hydrate(string $class, mixed $data, string $rootLabel = ''): object
    {
        if (!is_array($data)) {
            throw new BindingException([[
                'line' => null,
                'field' => $rootLabel !== '' ? $rootLabel : self::shortName($class),
                'message' => 'must be an object',
            ]]);
        }

        /** @var array<int, array{line: ?int, field: string, message: string}> $errors */
        $errors = [];
        $instance = self::bindObject($class, $data, $rootLabel !== '' ? [$rootLabel] : [], $errors);

        if ($errors !== []) {
            throw new BindingException($errors);
        }

        // $instance is guaranteed non-null here: bindObject() only returns
        // null when it recorded at least one error, and we just asserted
        // $errors is empty.
        if ($instance === null) {
            throw new UnsupportedDtoShapeException("DtoHydrator: internal inconsistency binding $class");
        }

        return $instance;
    }

    /**
     * @param class-string $class
     * @param array<array-key, mixed> $data
     * @param array<int, int|string> $path
     * @param array<int, array{line: ?int, field: string, message: string}> $errors
     */
    private static function bindObject(string $class, array $data, array $path, array &$errors): ?object
    {
        if (!class_exists($class)) {
            throw new UnsupportedDtoShapeException("DtoHydrator: target class does not exist: $class");
        }

        $rc = new \ReflectionClass($class);
        $ctor = $rc->getConstructor();

        if ($ctor === null) {
            return $rc->newInstance();
        }

        /** @var array<string, mixed> $args */
        $args = [];
        $ok = true;

        foreach ($ctor->getParameters() as $param) {
            $name = $param->getName();
            $paramPath = [...$path, $name];
            $present = array_key_exists($name, $data);

            if (!$present) {
                if ($param->isDefaultValueAvailable()) {
                    $args[$name] = $param->getDefaultValue();
                    continue;
                }
                self::addError($errors, $paramPath, 'is required');
                $ok = false;
                continue;
            }

            $value = $data[$name];
            [$bound, $success] = self::bindValue($param, $value, $paramPath, $errors, $ctor);

            if (!$success) {
                $ok = false;
                continue;
            }
            $args[$name] = $bound;
        }

        if (!$ok) {
            return null;
        }

        return $rc->newInstanceArgs($args);
    }

    /**
     * @param array<int, int|string> $path
     * @param array<int, array{line: ?int, field: string, message: string}> $errors
     * @return array{0: mixed, 1: bool}
     */
    private static function bindValue(
        \ReflectionParameter $param,
        mixed $value,
        array $path,
        array &$errors,
        \ReflectionMethod $ctor
    ): array {
        $type = $param->getType();

        if ($type === null) {
            return [$value, true]; // untyped constructor param — raw passthrough
        }

        $nullable = false;
        $named = null;

        if ($type instanceof \ReflectionNamedType) {
            $named = $type;
            $nullable = $type->allowsNull();
        } elseif ($type instanceof \ReflectionUnionType) {
            $subtypes = array_values(array_filter(
                $type->getTypes(),
                static fn (\ReflectionType $t) => $t instanceof \ReflectionNamedType
            ));
            $nonNull = array_values(array_filter($subtypes, static fn (\ReflectionNamedType $t) => $t->getName() !== 'null'));
            $hasNull = count($subtypes) !== count($nonNull);
            if (count($nonNull) === 1 && $hasNull) {
                // Exactly "X|null" — treat identically to the "?X" case.
                $named = $nonNull[0];
                $nullable = true;
            } else {
                throw new UnsupportedDtoShapeException(
                    "DtoHydrator: unsupported union type on parameter \${$param->getName()} — " .
                    'only a single class/scalar type, optionally nullable, is supported.'
                );
            }
        } else {
            throw new UnsupportedDtoShapeException(
                "DtoHydrator: unsupported parameter type on \${$param->getName()} (intersection types are not supported)."
            );
        }

        if ($value === null) {
            if ($nullable) {
                return [null, true];
            }
            self::addError($errors, $path, 'must not be null');
            return [null, false];
        }

        $typeName = $named->getName();

        if ($named->isBuiltin()) {
            return self::bindBuiltin($typeName, $param, $value, $path, $errors, $ctor);
        }

        // Backed enum
        if (enum_exists($typeName) && is_subclass_of($typeName, \BackedEnum::class)) {
            if (!is_int($value) && !is_string($value)) {
                self::addError($errors, $path, 'must be a string or integer enum value');
                return [null, false];
            }
            try {
                /** @var class-string<\BackedEnum> $enumClass */
                $enumClass = $typeName;
                return [$enumClass::from($value), true];
            } catch (\ValueError) {
                self::addError($errors, $path, 'is not a valid value');
                return [null, false];
            }
        }

        // TypedArray<T> — a constructor parameter explicitly typed `TypedArray`
        // (as opposed to the legacy `array $x` + #[ArrayOf] combo handled by
        // bindBuiltin()'s 'array' case, which stays bare-array for backward
        // compatibility). The element type is REQUIRED via #[ArrayOf(...)] —
        // there is no docblock fallback here (TypedArray already carries its
        // own runtime type, so guessing from a docblock would be redundant
        // and error-prone); a `TypedArray $x` param with no #[ArrayOf] is a
        // DTO-authoring mistake, not a data problem, so it's a loud
        // UnsupportedDtoShapeException rather than a per-request 400.
        if ($typeName === TypedArray::class) {
            if (!is_array($value) || !array_is_list($value)) {
                self::addError($errors, $path, 'must be an array');
                return [null, false];
            }
            $elementType = self::resolveArrayOfElementClass($param, $ctor);
            if ($elementType === null) {
                throw new UnsupportedDtoShapeException(
                    "DtoHydrator: TypedArray parameter \${$param->getName()} requires " .
                    '#[ArrayOf(...)] to declare its element type.'
                );
            }
            return self::bindTypedArrayOf($elementType, $value, $path, $errors, $param, $ctor);
        }

        // Nested userland DTO
        if (class_exists($typeName) || interface_exists($typeName)) {
            if (!is_array($value)) {
                self::addError($errors, $path, 'must be an object');
                return [null, false];
            }
            $nested = self::bindObject($typeName, $value, $path, $errors);
            return [$nested, $nested !== null];
        }

        throw new UnsupportedDtoShapeException("DtoHydrator: cannot bind unknown type '$typeName' on \${$param->getName()}");
    }

    /**
     * @param array<int, int|string> $path
     * @param array<int, array{line: ?int, field: string, message: string}> $errors
     * @return array{0: mixed, 1: bool}
     */
    private static function bindBuiltin(
        string $typeName,
        \ReflectionParameter $param,
        mixed $value,
        array $path,
        array &$errors,
        \ReflectionMethod $ctor
    ): array {
        switch ($typeName) {
            case 'int':
                if (is_int($value)) {
                    return [$value, true];
                }
                if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
                    return [(int) $value, true];
                }
                if (is_float($value) && floor($value) === $value && !is_infinite($value)) {
                    return [(int) $value, true];
                }
                self::addError($errors, $path, 'must be an integer');
                return [null, false];

            case 'float':
                if (is_float($value) || is_int($value)) {
                    return [(float) $value, true];
                }
                if (is_string($value) && is_numeric($value)) {
                    return [(float) $value, true];
                }
                self::addError($errors, $path, 'must be a number');
                return [null, false];

            case 'bool':
                if (is_bool($value)) {
                    return [$value, true];
                }
                if (is_int($value) && ($value === 0 || $value === 1)) {
                    return [(bool) $value, true];
                }
                if (is_string($value)) {
                    $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($parsed !== null) {
                        return [$parsed, true];
                    }
                }
                self::addError($errors, $path, 'must be a boolean');
                return [null, false];

            case 'string':
                if (is_string($value)) {
                    return [$value, true];
                }
                if (is_int($value) || is_float($value)) {
                    return [(string) $value, true];
                }
                self::addError($errors, $path, 'must be a string');
                return [null, false];

            case 'array':
                if (!is_array($value)) {
                    self::addError($errors, $path, 'must be an array');
                    return [null, false];
                }
                $elementClass = self::resolveArrayOfElementClass($param, $ctor);
                if ($elementClass === null) {
                    return [$value, true]; // raw passthrough — legacy `public ?array` compatibility
                }
                return self::bindArrayOf($elementClass, $value, $path, $errors);

            case 'mixed':
                return [$value, true];

            default:
                throw new UnsupportedDtoShapeException("DtoHydrator: unsupported builtin type '$typeName' on \${$param->getName()}");
        }
    }

    /**
     * @param class-string $elementClass
     * @param array<array-key, mixed> $list
     * @param array<int, int|string> $path
     * @param array<int, array{line: ?int, field: string, message: string}> $errors
     * @return array{0: mixed, 1: bool}
     */
    private static function bindArrayOf(string $elementClass, array $list, array $path, array &$errors): array
    {
        $result = [];
        $ok = true;

        foreach ($list as $idx => $element) {
            $elementPath = [...$path, is_int($idx) ? $idx : (int) $idx];
            if (!is_array($element)) {
                self::addError($errors, $elementPath, 'must be an object');
                $ok = false;
                continue;
            }
            $bound = self::bindObject($elementClass, $element, $elementPath, $errors);
            if ($bound === null) {
                $ok = false;
                continue;
            }
            $result[] = $bound;
        }

        return [$result, $ok];
    }

    /**
     * Bind a JSON list into a {@see TypedArray} of $elementType — the
     * TypedArray-typed-parameter counterpart to {@see bindArrayOf()} (which
     * stays bare-array for the legacy `array $x` + #[ArrayOf] case).
     * $elementType may be a scalar keyword (int|float|bool|string), bound via
     * the existing {@see bindBuiltin()} scalar cases (reused as-is — those
     * cases never touch $param, only $path/$errors), or a class/interface,
     * bound recursively via {@see bindObject()}.
     *
     * @param class-string|'int'|'float'|'bool'|'string' $elementType
     * @param array<array-key, mixed> $list
     * @param array<int, int|string> $path
     * @param array<int, array{line: ?int, field: string, message: string}> $errors
     * @return array{0: mixed, 1: bool}
     */
    private static function bindTypedArrayOf(
        string $elementType,
        array $list,
        array $path,
        array &$errors,
        \ReflectionParameter $param,
        \ReflectionMethod $ctor
    ): array {
        $isScalar = in_array($elementType, ['int', 'float', 'bool', 'string'], true);

        if (!$isScalar && !class_exists($elementType) && !interface_exists($elementType)) {
            throw new UnsupportedDtoShapeException(
                "DtoHydrator: TypedArray element type '$elementType' declared via #[ArrayOf(...)] " .
                'on $' . $param->getName() . ' is not a supported scalar keyword ' .
                '(int|float|bool|string) or an existing class/interface.'
            );
        }

        $result = [];
        $ok = true;

        foreach ($list as $idx => $element) {
            $elementPath = [...$path, is_int($idx) ? $idx : (int) $idx];

            if ($isScalar) {
                [$bound, $success] = self::bindBuiltin($elementType, $param, $element, $elementPath, $errors, $ctor);
            } else {
                if (!is_array($element)) {
                    self::addError($errors, $elementPath, 'must be an object');
                    $ok = false;
                    continue;
                }
                $bound = self::bindObject($elementType, $element, $elementPath, $errors);
                $success = $bound !== null;
            }

            if (!$success) {
                $ok = false;
                continue;
            }
            $result[] = $bound;
        }

        if (!$ok) {
            return [null, false];
        }

        // The element-level binding above already guarantees every $result
        // entry matches $elementType, so this construction cannot throw —
        // TypedArray's own runtime check is a second, redundant-by-design
        // safety net, not the primary validation path here.
        return [new TypedArray($elementType, $result), true];
    }

    /**
     * Resolve the element class for an `array $param` — prefers the
     * {@see ArrayOf} attribute, falls back to a `@param X[] $name`
     * constructor docblock (cheap regex, not a full docblock parser).
     *
     * @return class-string|null
     */
    private static function resolveArrayOfElementClass(\ReflectionParameter $param, \ReflectionMethod $ctor): ?string
    {
        foreach ($param->getAttributes(ArrayOf::class) as $attr) {
            /** @var ArrayOf $instance */
            $instance = $attr->newInstance();
            return $instance->class;
        }

        $doc = $ctor->getDocComment();
        if ($doc === false) {
            return null;
        }

        $name = preg_quote($param->getName(), '/');
        if (preg_match('/@param\s+([A-Za-z0-9_\\\\]+)\[\]\s+\$' . $name . '\b/', $doc, $m) !== 1) {
            return null;
        }

        $candidate = ltrim($m[1], '\\');
        if (class_exists($candidate)) {
            return $candidate;
        }

        $namespaced = $ctor->getDeclaringClass()->getNamespaceName() . '\\' . $candidate;
        if (class_exists($namespaced)) {
            return $namespaced;
        }

        return null; // couldn't resolve — treat as raw array, don't guess wrong
    }

    /**
     * @param array<int, int|string> $path
     * @param array<int, array{line: ?int, field: string, message: string}> $errors
     */
    private static function addError(array &$errors, array $path, string $message): void
    {
        [$line, $field] = self::toLineField($path);
        $errors[] = ['line' => $line, 'field' => $field, 'message' => $message];
    }

    /**
     * @param array<int, int|string> $path
     * @return array{0: ?int, 1: string}
     */
    private static function toLineField(array $path): array
    {
        $line = null;
        $fieldParts = [];

        foreach ($path as $segment) {
            if (is_int($segment)) {
                $line = $segment + 1;
                continue;
            }
            $fieldParts[] = $segment;
        }

        if ($fieldParts === []) {
            return [$line, ''];
        }

        $field = (string) end($fieldParts);
        return [$line, $field];
    }

    private static function shortName(string $class): string
    {
        $pos = strrpos($class, '\\');
        return $pos === false ? $class : substr($class, $pos + 1);
    }
}
