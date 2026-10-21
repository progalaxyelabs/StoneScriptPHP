<?php

declare(strict_types=1);

namespace StoneScriptPHP\Persistence;

use StoneScriptDB\GatewayException;

/**
 * Allow-list extraction of diagnosable, VALUE-FREE facts from database error text.
 *
 * PostgreSQL error text (message, DETAIL, HINT, CONTEXT, custom `RAISE EXCEPTION` text) routinely echoes
 * customer data. Everything printed or logged is rebuilt from a closed set of identifier-shaped fields:
 *
 *   sqlstate, schema, table, column, constraint, function, app_code
 *
 * Rules (shared with {@see GatewayException::parsePrimary()}):
 *  - only the PostgreSQL PRIMARY message line is read, never DETAIL / HINT / CONTEXT;
 *  - table / column / constraint come only from PostgreSQL's own fixed phrasings, matched against the whole line;
 *  - `app_code` is only an anchored code-shaped token at the start of the line (see the application error code
 *    contract on GatewayException); a free-text sentence yields nothing;
 *  - every captured value must look like an identifier, and must not look like a secret.
 */
final class SafeDbContext
{
    public const IDENT = '/^[A-Za-z_][A-Za-z0-9_$]{0,62}$/';
    public const SQLSTATE = '/^[0-9A-Z]{5}$/';

    /**
     * The PostgreSQL primary message inside driver-wrapped text:
     *   `SQLSTATE[23505]: Unique violation: 7 ERROR:  msg\nDETAIL: ...`  (PDO)
     *   `ERROR:  msg\nDETAIL: ...`                                      (pg_last_error)
     *   `SQLSTATE[23505]: msg`                                          (bridge contract)
     *   `msg | DETAIL: ...`                                             (gateway cause)
     */
    public static function primaryOf(string $raw): string
    {
        if (preg_match('/\bERROR:\s+(.*)/', $raw, $m) === 1) {
            $raw = $m[1];
        } elseif (preg_match('/^SQLSTATE\[[0-9A-Za-z]{5}\]:\s*(.*)/s', $raw, $m) === 1) {
            $raw = $m[1];
        }
        return GatewayException::primaryLine($raw);
    }

    /**
     * @return array<string,string> subset of sqlstate, constraint, column, table, function, app_code
     */
    public static function fromText(string $raw, ?string $sqlstate = null): array
    {
        $out = [];
        if ($sqlstate !== null && preg_match(self::SQLSTATE, $sqlstate) === 1) {
            $out['sqlstate'] = $sqlstate;
        } elseif (preg_match('/^SQLSTATE\[([0-9A-Z]{5})\]/', $raw, $m) === 1) {
            $out['sqlstate'] = $m[1];
        }
        foreach (GatewayException::parsePrimary(self::primaryOf($raw), $out['sqlstate'] ?? null) as $k => $v) {
            if (!isset($out[$k])) {
                $out[$k] = $v;
            }
        }
        // The gateway's trailing SCHEMA/TABLE/COLUMN segments, only for PostgreSQL-raised errors (see GatewayException).
        $state = $out['sqlstate'] ?? null;
        if ($state !== null && !isset($out['app_code']) && in_array(substr($state, 0, 2), ['22', '23', '42', '40', '53', '55', '57', '25', '2B', '0A'], true)) {
            foreach (GatewayException::parseTrailing($raw) as $k => $v) {
                $out[$k] ??= $v;
            }
        }
        return $out;
    }

    /** ` [sqlstate=23505 constraint=users_email_key]` or '' */
    public static function suffix(array $ctx): string
    {
        return GatewayException::formatSafeSuffix($ctx);
    }

    /** Value-free one-liner for raw database error text, e.g. from pg_last_error(). */
    public static function summarize(string $prefix, string $raw, ?string $sqlstate = null): string
    {
        $suffix = self::suffix(self::fromText($raw, $sqlstate));
        return $prefix . ($suffix !== '' ? $suffix : ' (details withheld)');
    }

    /** Value-free description of a PDOException (SQLSTATE from errorInfo, identifiers from the primary line). */
    public static function summarizePdo(string $prefix, \PDOException $e): string
    {
        $state = is_array($e->errorInfo) && isset($e->errorInfo[0]) ? (string) $e->errorInfo[0] : null;
        return self::summarize($prefix, $e->getMessage(), $state);
    }

    /**
     * Message that is safe to PRINT for any throwable (CLI, logs). Database-origin throwables are rebuilt from
     * allow-listed identifiers; anything else keeps its (framework-authored) message through the best-effort
     * sanitiser.
     */
    public static function message(\Throwable $e): string
    {
        if ($e instanceof \PDOException) {
            return self::summarizePdo('Database error', $e);
        }
        return LogSanitizer::forLog($e->getMessage());
    }

    /** Raw PostgreSQL cause carried by a gateway/transport exception, if any. In-process use ONLY, never output. */
    public static function rawCauseOf(\Throwable $e): ?string
    {
        if ($e instanceof GatewayException) {
            return $e->getRawCauseForDebug();
        }
        if ($e instanceof \StoneScriptPHP\Db\DbTransportException) {
            return $e->getRawCauseForDebug();
        }
        return null;
    }

    /**
     * Run a pg_* call without letting PHP emit its E_WARNING (which carries the full PostgreSQL message, DETAIL
     * included, to stdout/stderr/logs) and return the failure as a value-free summary instead.
     *
     * @param callable():mixed $call
     * @param string|null $error set to a safe summary on failure, else null
     */
    public static function quiet(callable $call, ?string &$error = null, string $prefix = 'Database call failed'): mixed
    {
        $warning = null;
        set_error_handler(static function (int $no, string $str) use (&$warning): bool {
            $warning = $str;
            return true;
        });
        try {
            $result = $call();
        } finally {
            restore_error_handler();
        }
        $error = ($result === false && $warning !== null) ? self::summarize($prefix, $warning) : null;
        return $result;
    }
}
