<?php

namespace StoneScriptPHP\Persistence;

use StoneScriptPHP\Env;

/**
 * Selects how hard the framework enforces "a failed write is never a 2xx".
 *
 *  - `lenient` (**default in 12.x**): historical behaviour. Wherever `enforced` WOULD change a
 *    response, the response is left alone and an E_USER_DEPRECATED notice (once per kind per
 *    process) says what would have happened.
 *  - `enforced`: (1) an error-status body with no HTTP status becomes 4xx/5xx instead of
 *    shipping as 200; (2) a 2xx that follows an unacknowledged {@see PersistenceException}
 *    becomes a 500; (3) raw database exceptions escaping a handler are mapped by
 *    {@see DbErrorMapper} (duplicate key -> 409, deadlock -> 503, ...) instead of an opaque 500.
 *
 * `enforced` becomes the default in the next major version.
 *
 * Precedence: {@see self::setMode()} > config `persistence.contract` (bootstrap) >
 * `PERSISTENCE_CONTRACT` > `lenient`.
 *
 * `Database::mutate()` and `PersistenceException` are NOT governed by this switch: calling
 * mutate() is itself the opt-in and always throws on a failed write.
 */
final class PersistenceContract
{
    public const LENIENT = 'lenient';
    public const ENFORCED = 'enforced';

    private static ?string $explicit = null;
    private static ?string $resolved = null;

    public static function setMode(?string $mode): void
    {
        if ($mode !== null && $mode !== self::LENIENT && $mode !== self::ENFORCED) {
            throw new \InvalidArgumentException("Invalid persistence contract '$mode' (expected lenient|enforced).");
        }
        self::$explicit = $mode;
        self::$resolved = null;
    }

    public static function mode(): string
    {
        if (self::$explicit !== null) {
            return self::$explicit;
        }
        if (self::$resolved !== null) {
            return self::$resolved;
        }
        // Not bootstrapped: raw process env only (never construct Env from a response path).
        $raw = getenv('PERSISTENCE_CONTRACT');
        if ($raw === false && isset($_ENV['PERSISTENCE_CONTRACT'])) {
            $raw = $_ENV['PERSISTENCE_CONTRACT'];
        }
        return self::$resolved = self::normalise($raw === false ? null : (string) $raw);
    }

    public static function enforced(): bool
    {
        return self::mode() === self::ENFORCED;
    }

    /** @param array<string, mixed> $config Application::run() config */
    public static function bootstrap(array $config = []): void
    {
        $value = $config['persistence']['contract'] ?? null;
        if ($value === null) {
            try {
                $value = Env::secret('PERSISTENCE_CONTRACT');
            } catch (\Throwable $e) {
                $value = null;
            }
        }
        self::$resolved = self::normalise($value === null ? null : (string) $value);
    }

    /** In lenient mode: tell the operator what enforced mode would have done. Rate-limited, never throws. */
    public static function wouldEnforce(string $kind, string $detail): void
    {
        \StoneScriptPHP\Support\DeprecationNotice::emit(
            'persistence:' . $kind,
            "StoneScriptPHP persistence contract ($kind): $detail "
            . 'Lenient mode left the response unchanged; this is DEPRECATED. Set PERSISTENCE_CONTRACT=enforced '
            . '(enforced becomes the default in the next major version).'
        );
    }

    public static function reset(): void
    {
        self::$explicit = null;
        self::$resolved = null;
        \StoneScriptPHP\Support\DeprecationNotice::reset();
    }

    private static function normalise(?string $value): string
    {
        $value = strtolower(trim((string) $value));
        if ($value === '' || $value === self::LENIENT) {
            return self::LENIENT;
        }
        if ($value === self::ENFORCED) {
            return self::ENFORCED;
        }
        error_log("[StoneScriptPHP] Unknown PERSISTENCE_CONTRACT '$value' - using 'lenient'. Expected lenient|enforced.");
        return self::LENIENT;
    }
}
