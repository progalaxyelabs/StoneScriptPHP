<?php

// NOTE: deliberately NOT declare(strict_types=1). Legacy mode relies on PHP's weak-mode scalar
// coercion when assigning wire values to typed properties (e.g. NUMERIC '1200.00' -> float),
// exactly as Database::array_to_class_object() always did (Database.php is not strict either).
// Strict mode converts explicitly and does not depend on this.

namespace StoneScriptPHP\Database;

use BackedEnum;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use StoneScriptPHP\Env;

/**
 * Maps one database result row onto a model class (public typed properties).
 *
 * Two modes, selected by {@see self::mode()}:
 *
 *  - `legacy` (the 12.x default): the historical behaviour. A SQL NULL for a
 *    NON-nullable property is replaced by '' / 0 / false (a lossy invention) and
 *    a deprecation notice names the offending class::property (once per process
 *    per property). NULL for a nullable property is already null.
 *
 *  - `strict`: NULL stays NULL. A NULL for a non-nullable property throws
 *    {@see HydrationException} naming the function and property, because
 *    there is no honest value to put there; declare the property nullable
 *    (`?string`) instead. Non-null wire values are converted explicitly to the
 *    declared type and a value that does not fit throws, instead of relying on
 *    PHP's loose scalar coercion.
 *
 * Mode precedence: {@see self::setMode()} (explicit) > `DB_HYDRATION_MODE`
 * (read through Env) > `legacy`. The strict mode becomes the default in the
 * next major version.
 *
 * Wire value conventions handled in both modes:
 *   bool     true|false (gateway JSON) or 't'|'f' (libpq text)
 *   DateTime ISO-ish string
 *   int      JSON int, or integral numeric string
 *
 * Money / NUMERIC exactness: a PHP `float` cannot represent every decimal
 * exactly. Declare monetary NUMERIC columns as `string` (the exact decimal
 * text, e.g. "1200.50") and do arithmetic with bcmath/an integer-minor-unit
 * type. That is only exact when the data layer delivers NUMERIC as text
 * (the gateway's NUMERIC-as-string option); with plain JSON numbers the
 * value has already been through a float before this class sees it. Generated
 * models can pin a column's type with `-- @out <column> <type>` in the SQL file.
 */
final class RowHydrator
{
    public const MODE_LEGACY = 'legacy';
    public const MODE_STRICT = 'strict';

    private static ?string $explicitMode = null;
    private static ?string $resolvedMode = null;

    /** @var array<string, array<int, array{name: string, type: string, builtin: bool, nullable: bool}>> */
    private static array $shape = [];

    public static function setMode(?string $mode): void
    {
        if ($mode !== null && $mode !== self::MODE_LEGACY && $mode !== self::MODE_STRICT) {
            throw new \InvalidArgumentException("Invalid hydration mode '$mode' (expected legacy|strict).");
        }
        self::$explicitMode = $mode;
        self::$resolvedMode = null;
    }

    public static function mode(): string
    {
        if (self::$explicitMode !== null) {
            return self::$explicitMode;
        }
        if (self::$resolvedMode !== null) {
            return self::$resolvedMode;
        }

        // Not bootstrapped (CLI tools, isolated tests): read the raw process env only.
        // Deliberately NOT Env::get_instance() - constructing Env here has side effects
        // (required-secret validation) that a row mapper must never trigger.
        $raw = getenv('DB_HYDRATION_MODE');
        if ($raw === false && isset($_ENV['DB_HYDRATION_MODE'])) {
            $raw = $_ENV['DB_HYDRATION_MODE'];
        }
        return self::$resolvedMode = self::normalise($raw === false ? null : (string) $raw);
    }

    /**
     * Resolve the mode once at boot (called by Application::run()).
     * Precedence: config `db.hydration_mode` > `DB_HYDRATION_MODE` (via Env, so `.env`,
     * `_FILE` and docker secrets work) > `legacy`.
     *
     * @param array<string, mixed> $config
     */
    public static function bootstrap(array $config = []): void
    {
        $value = $config['db']['hydration_mode'] ?? null;
        if ($value === null) {
            try {
                $value = Env::secret('DB_HYDRATION_MODE');
            } catch (\Throwable $e) {
                $value = null;
            }
        }
        self::$resolvedMode = self::normalise($value === null ? null : (string) $value);
    }

    private static function normalise(?string $value): string
    {
        $value = strtolower(trim((string) $value));
        if ($value === '' || $value === self::MODE_LEGACY) {
            return self::MODE_LEGACY;
        }
        if ($value === self::MODE_STRICT) {
            return self::MODE_STRICT;
        }
        error_log("[StoneScriptPHP] Unknown DB_HYDRATION_MODE '$value' - using 'legacy'. Expected legacy|strict.");
        return self::MODE_LEGACY;
    }

    /** Test seam: forget memoised mode, deprecation de-dup state and reflection cache. */
    public static function reset(): void
    {
        self::$explicitMode = null;
        self::$resolvedMode = null;
        \StoneScriptPHP\Support\DeprecationNotice::reset();
        self::$shape = [];
    }

    /**
     * @template T of object
     * @param array<string, mixed> $row
     * @param class-string<T> $class
     * @return T
     */
    public static function hydrate(string $function_name, array $row, string $class): object
    {
        $strict = self::mode() === self::MODE_STRICT;
        $instance = new $class();
        $missing = [];

        foreach (self::shapeOf($class, $instance) as $prop) {
            $name = $prop['name'];
            $key = null;
            if (array_key_exists($name, $row)) {
                $key = $name;
            } elseif (array_key_exists('o_' . $name, $row)) {
                // Model properties are canonically unprefixed; SQL functions emit o_-prefixed columns.
                $key = 'o_' . $name;
            }
            if ($key === null) {
                $missing[] = $name;
                continue;
            }

            $value = $row[$key];

            if ($value === null) {
                if ($prop['nullable']) {
                    $instance->$name = null;
                    continue;
                }
                if ($strict) {
                    throw new HydrationException(sprintf(
                        "Function '%s' returned NULL for %s::\$%s, which is declared non-nullable (%s). "
                        . "Declare it ?%s (generated models: add `-- @out %s ?%s` to the SQL file).",
                        $function_name,
                        $class,
                        $name,
                        $prop['type'],
                        $prop['type'],
                        $name,
                        $prop['type']
                    ));
                }
                self::noticeLegacyCoercion($function_name, $class, $name, $prop['type']);
                $instance->$name = match ($prop['type']) {
                    'int' => 0,
                    'bool' => false,
                    default => '',
                };
                continue;
            }

            $instance->$name = $strict
                ? self::convertStrict($function_name, $class, $name, $prop, $value)
                : self::convertLegacy($prop, $value);
        }

        if ($missing !== []) {
            throw new \Exception('mismatch in function result fields and class properties');
        }

        return $instance;
    }

    /**
     * @return array<int, array{name: string, type: string, builtin: bool, nullable: bool}>
     */
    private static function shapeOf(string $class, object $instance): array
    {
        if (isset(self::$shape[$class])) {
            return self::$shape[$class];
        }
        $shape = [];
        foreach ((new ReflectionClass($instance))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $type = $property->getType();
            if (!($type instanceof ReflectionNamedType)) {
                throw new HydrationException(sprintf(
                    'Unsupported type for property [%s::$%s]: model properties must be a single named type (optionally nullable).',
                    $class,
                    $property->getName()
                ));
            }
            $shape[] = [
                'name' => $property->getName(),
                'type' => $type->getName(),
                'builtin' => $type->isBuiltin(),
                'nullable' => $type->allowsNull(),
            ];
        }
        return self::$shape[$class] = $shape;
    }

    private static function noticeLegacyCoercion(string $fn, string $class, string $prop, string $type): void
    {
        // Rate-limited across requests (once per class::property per hour under FPM) and never throws.
        \StoneScriptPHP\Support\DeprecationNotice::emit('hydrate:' . $class . '::' . $prop, sprintf(
            "StoneScriptPHP: function '%s' returned NULL for %s::\$%s (non-nullable %s); the legacy hydrator replaced it with %s. "
            . 'This lossy coercion is DEPRECATED: declare the property ?%s and set DB_HYDRATION_MODE=strict '
            . '(strict becomes the default in the next major version).',
            $fn,
            $class,
            $prop,
            $type,
            $type === 'int' ? '0' : ($type === 'bool' ? 'false' : "''"),
            $type
        ));
    }

    /** Byte-for-byte the historical non-null behaviour. */
    private static function convertLegacy(array $prop, mixed $value): mixed
    {
        if ($prop['type'] === 'DateTime') {
            return new DateTime($value);
        }
        if ($prop['type'] === 'bool') {
            return $value === true || $value === 't';
        }
        return $value;
    }

    /** @param array{name: string, type: string, builtin: bool, nullable: bool} $prop */
    private static function convertStrict(string $fn, string $class, string $name, array $prop, mixed $value): mixed
    {
        $type = $prop['type'];
        $fail = static fn (string $why): HydrationException => new HydrationException(sprintf(
            "Function '%s' returned a value for %s::\$%s that cannot be converted to %s: %s",
            $fn,
            $class,
            $name,
            $type,
            $why
        ));

        switch ($type) {
            case 'mixed':
                return $value;

            case 'int':
                if (is_int($value)) {
                    return $value;
                }
                if (is_string($value) && ($int = filter_var($value, FILTER_VALIDATE_INT)) !== false) {
                    return $int;
                }
                if (is_float($value) && floor($value) === $value && abs($value) < 9.007199254740992E15) {
                    return (int) $value;
                }
                throw $fail(get_debug_type($value) . ' is not an integer');

            case 'float':
                if (is_float($value)) {
                    return $value;
                }
                if (is_int($value)) {
                    return (float) $value;
                }
                if (is_string($value) && is_numeric($value)) {
                    return (float) $value;
                }
                throw $fail(get_debug_type($value) . ' is not numeric');

            case 'string':
                if (is_string($value)) {
                    return $value;
                }
                if (is_int($value)) {
                    return (string) $value;
                }
                if (is_float($value)) {
                    // A JSON float reaching a string property has already lost its exact decimal text
                    // (1200.50 -> 1200.5, scale gone, large values rounded). Silently stringifying it would
                    // let money drift; fail so the data layer is switched to NUMERIC-as-string instead.
                    throw $fail('a JSON float cannot be stored exactly in a string property; request NUMERIC as text from the data layer (gateway numeric_format=string) or declare the property float');
                }
                throw $fail(get_debug_type($value) . ' is not a scalar string value');

            case 'bool':
                if (is_bool($value)) {
                    return $value;
                }
                if ($value === 't' || $value === 'true' || $value === '1' || $value === 1) {
                    return true;
                }
                if ($value === 'f' || $value === 'false' || $value === '0' || $value === 0) {
                    return false;
                }
                throw $fail('not a boolean');

            case 'array':
                if (is_array($value)) {
                    return $value;
                }
                if (is_string($value)) {
                    $decoded = json_decode($value, true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
                throw $fail('not a JSON array/object');
        }

        if (is_a($type, DateTimeInterface::class, true) && is_string($value)) {
            try {
                // `timestamp` (no zone) arrives without an offset: interpret it as UTC explicitly so the result
                // never depends on date_default_timezone_set(). Values with an offset / Z keep their own.
                $zone = preg_match('/(?:Z|[+-]\d{2}(?::?\d{2})?)\s*$/i', $value) === 1 ? null : new \DateTimeZone('UTC');
                return $type === DateTimeInterface::class || $type === DateTimeImmutable::class
                    ? new DateTimeImmutable($value, $zone)
                    : new $type($value, $zone);
            } catch (\Exception $e) {
                throw $fail('unparseable date/time');
            }
        }

        if (is_a($type, BackedEnum::class, true)) {
            try {
                $case = $type::tryFrom($value);
            } catch (\TypeError | \ValueError $e) {
                throw $fail('not a valid case for this enum (wrong value type)');
            }
            if ($case === null) {
                throw $fail('not a valid case');
            }
            return $case;
        }

        if ($value instanceof $type) {
            return $value;
        }

        throw $fail('unsupported property type');
    }
}
