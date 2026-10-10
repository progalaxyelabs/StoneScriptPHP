<?php

declare(strict_types=1);

namespace StoneScriptPHP\Persistence;

/**
 * Per-request record of write failures raised by {@see Mutation::run()}.
 *
 * It exists for one question the response layer must answer: "did a write fail in this
 * request, and did the handler still answer 2xx?" A handler that catches a
 * PersistenceException and carries on answering success has lied to its client. The
 * {@see ResponseGuard} asks this ledger; a failure is excused only when the handler
 * explicitly says so with {@see self::handled()} (a deliberate fallback or best-effort
 * write), never implicitly.
 *
 * Process-global state, reset at the start AND end of every Router::dispatch(). In a long-lived worker
 * (Swoole, RoadRunner, a queue consumer) that calls mutate() outside dispatch(), call
 * {@see reset()} between jobs; the ledger holds at most {@see MAX_ENTRIES} failures so it cannot grow unbounded.
 */
final class PersistenceLedger
{
    /** @var array<int, array{exception: PersistenceException, handled: bool}> */
    private static array $failures = [];

    public static function reset(): void
    {
        self::$failures = [];
    }

    /** Bound on retained failures (long-lived workers / CLI jobs that call mutate() outside a request). */
    public const MAX_ENTRIES = 50;

    public static function record(PersistenceException $e): void
    {
        if (count(self::$failures) >= self::MAX_ENTRIES) {
            // Prefer dropping an acknowledged failure; if every entry is unhandled the guard already has
            // its signal, so the newcomer is not retained.
            foreach (self::$failures as $id => $f) {
                if ($f['handled']) {
                    unset(self::$failures[$id]);
                    break;
                }
            }
            if (count(self::$failures) >= self::MAX_ENTRIES) {
                return;
            }
        }
        self::$failures[spl_object_id($e)] = ['exception' => $e, 'handled' => false];
    }

    /**
     * Declare that the handler deliberately recovered from (or does not care about) this
     * failure, e.g. an optional audit write, or insert-then-fall-back-to-update. Call it from
     * the catch block. A 2xx is then no longer treated as a swallowed failure.
     */
    public static function handled(PersistenceException $e): void
    {
        if (isset(self::$failures[spl_object_id($e)])) {
            self::$failures[spl_object_id($e)]['handled'] = true;
        }
    }

    /** Failures nobody acknowledged. @return list<PersistenceException> */
    public static function unhandled(): array
    {
        $out = [];
        foreach (self::$failures as $f) {
            if (!$f['handled']) {
                $out[] = $f['exception'];
            }
        }
        return $out;
    }
}
