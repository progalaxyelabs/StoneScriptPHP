<?php

declare(strict_types=1);

namespace StoneScriptPHP\Persistence;

/**
 * Allow-list extraction of diagnosable, VALUE-FREE facts from database error text.
 *
 * PostgreSQL error text (message, DETAIL, HINT, CONTEXT, custom `RAISE EXCEPTION` text) routinely echoes
 * customer data. Rather than trying to scrub it with regexes, everything that is printed or logged is
 * rebuilt from a closed set of identifier-shaped fields:
 *
 *   sqlstate, schema, table, column, constraint, function
 *
 * Each captured value must itself look like a PostgreSQL identifier (or a 5-char SQLSTATE); anything else is
 * dropped. The surrounding raw text is discarded and never forwarded.
 */
final class SafeDbContext
{
    public const IDENT = '/^[A-Za-z_][A-Za-z0-9_$]{0,62}$/';
    public const SQLSTATE = '/^[0-9A-Z]{5}$/';

    /**
     * @return array<string,string> subset of sqlstate, constraint, column, table, schema
     */
    public static function fromText(string $raw, ?string $sqlstate = null): array
    {
        $out = [];
        if ($sqlstate !== null && preg_match(self::SQLSTATE, $sqlstate) === 1) {
            $out['sqlstate'] = $sqlstate;
        } elseif (preg_match('/SQLSTATE[\[ ]\(?([0-9A-Z]{5})\b/', $raw, $m) === 1) {
            $out['sqlstate'] = $m[1];
        }
        $patterns = [
            'constraint' => '/constraint "([^"\s]+)"/',
            'column' => '/(?:null value in column|\bCOLUMN:|column) "?([A-Za-z_][A-Za-z0-9_$]*)"?/',
            'table' => '/\bTABLE: ([A-Za-z_][A-Za-z0-9_$]*)|\brelation "([^"\s]+)"/',
            'schema' => '/\bSCHEMA: ([A-Za-z_][A-Za-z0-9_$]*)/',
        ];
        foreach ($patterns as $field => $re) {
            if (preg_match($re, $raw, $m) === 1) {
                $v = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
                if (preg_match(self::IDENT, $v) === 1) {
                    $out[$field] = $v;
                }
            }
        }
        return $out;
    }

    /** ` [sqlstate=23505 constraint=users_email_key]` or '' */
    public static function suffix(array $ctx): string
    {
        $parts = [];
        foreach (['database', 'function', 'sqlstate', 'schema', 'table', 'column', 'constraint', 'correlation_id'] as $k) {
            if (isset($ctx[$k]) && is_string($ctx[$k])) {
                $parts[] = ($k === 'correlation_id' ? 'ref' : $k) . '=' . $ctx[$k];
            }
        }
        return $parts === [] ? '' : ' [' . implode(' ', $parts) . ']';
    }

    /** Value-free one-liner for raw database error text, e.g. from pg_last_error(). */
    public static function summarize(string $prefix, string $raw, ?string $sqlstate = null): string
    {
        $suffix = self::suffix(self::fromText($raw, $sqlstate));
        return $prefix . ($suffix !== '' ? $suffix : ' (details withheld)');
    }

    /** Value-free description of a PDOException (SQLSTATE from errorInfo, identifiers from the text). */
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
        if ($e instanceof \StoneScriptDB\GatewayException) {
            return $e->getRawCauseForDebug();
        }
        if ($e instanceof \StoneScriptPHP\Db\DbTransportException) {
            return $e->getRawCauseForDebug();
        }
        return null;
    }
}
