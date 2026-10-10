<?php

declare(strict_types=1);

namespace StoneScriptPHP\Persistence;

/**
 * Scrubs database error text before it reaches a LOG line. PostgreSQL error text routinely embeds
 * data values: `Key (email)=(a@b.c) already exists`, `invalid input syntax for type uuid: "x"`,
 * `DETAIL: Failing row contains (...)`. Those are customer data and must not land in log files.
 *
 * Kept: SQLSTATE / wording / identifiers (constraint, relation, table, column, function, type, schema,
 * index, trigger, sequence names) - everything an operator needs to diagnose. Removed: `Key (..)=(..)`
 * values, `DETAIL`/`CONTEXT`/`Failing row` text, and every other quoted literal.
 */
final class LogSanitizer
{
    private const IDENT_KEYWORDS = 'constraint|relation|table|column|function|type|schema|index|trigger|sequence';

    /** Markers of text that came from the database layer (only such text has quoted VALUES worth masking). */
    private const DB_MARKERS = '/SQLSTATE|\bDETAIL:|\bKey\s*\(|duplicate key|violates|invalid input syntax|out of range for type|value too long|null value in column|Failing row|\bconstraint\b/i';

    /**
     * @param bool $forLog true for free-form log text (message/context written by the Logger): quoted literals are
     *   then masked ONLY when the text is database-error-shaped, so JSON debug lines (`{"key":"value"}`) and ordinary
     *   prose (`Route 'x'`, `don't`) keep their structure. `Key (col)=(value)`, `DETAIL:` and emails are always masked.
     */
    public static function sanitize(string $text, bool $forLog = false): string
    {
        // 1. Key (col)=(value) detail, with nested parentheses in the value.
        $text = preg_replace('/Key\s*\([^)]*\)\s*=\s*\((?:[^()]|\([^()]*\))*\)/i', 'Key (<redacted>)', $text) ?? $text;
        // 2. PostgreSQL `DETAIL:` / `CONTEXT:` lines (exactly that form, upper case + colon) up to the next ` | ` separator or end.
        $text = preg_replace('/\b(DETAIL|CONTEXT):[^|]*/', '$1: <redacted>', $text) ?? $text;
        $text = preg_replace('/Failing row contains\s*\((?:[^()]|\([^()]*\))*\)/i', 'Failing row contains (<redacted>)', $text) ?? $text;
        // 3. Any remaining quoted literal, unless it follows an identifier keyword (constraint "x_key").
        if ($forLog && preg_match(self::DB_MARKERS, $text) !== 1) {
            return $text;
        }
        $text = preg_replace_callback(
            $forLog
                // Single quotes count as literals only when they are not apostrophes (don't, users').
                ? '/(\b(?:' . self::IDENT_KEYWORDS . ')\s+)?("[^"\n]*"|(?<![\w])\'[^\'\n]*\'(?![\w]))/i'
                : '/(\b(?:' . self::IDENT_KEYWORDS . ')\s+)?("[^"]*"|\'[^\']*\')/i',
            static fn (array $m): string => $m[1] !== '' ? $m[0] : '"?"',
            $text
        ) ?? $text;
        return $text;
    }

    /** A short, stable, non-reversible reference for an identifier (tenant, payment): correlate in logs without printing it. */
    public static function ref(string|int|null $id): string
    {
        return $id === null || $id === '' ? 'ref:none' : 'ref:' . substr(hash('sha256', (string) $id), 0, 8);
    }

    /** `ann@example.com` -> `a***@example.com` (first character + domain). */
    public static function maskEmails(string $text): string
    {
        return preg_replace_callback(
            '/[A-Za-z0-9._%+\-]+@([A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,})/',
            static fn (array $m): string => $m[0][0] . '***@' . $m[1],
            $text
        ) ?? $text;
    }

    /**
     * Everything that may be written to a log line: {@see sanitize()} (database detail and quoted literals) plus
     * email masking. Applied centrally by the Logger to the message and every context string.
     */
    public static function forLog(string $text): string
    {
        return self::maskEmails(self::sanitize($text, true));
    }

    /** Sanitised one-line description of a throwable chain, safe for logs. */
    public static function describe(\Throwable $e): string
    {
        $parts = [];
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            $line = (new \ReflectionClass($cur))->getShortName() . ': ' . self::sanitize($cur->getMessage());
            if ($cur instanceof \StoneScriptDB\GatewayException && is_string($cur->getCause()) && $cur->getCause() !== '') {
                $line .= ' [gateway cause: ' . self::sanitize($cur->getCause()) . ']';
            }
            $parts[] = $line;
        }
        return implode(' | caused by | ', $parts);
    }
}
