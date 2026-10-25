<?php

declare(strict_types=1);

namespace StoneScriptPHP\RequestLogging;

/**
 * Turns a failure's text into something safe to PERSIST (request_logs.error_message, log files).
 *
 * The rule: a recorded failure is "class + safe message", never request values or personal data. Exception
 * messages routinely embed the very data that failed (an e-mail in a duplicate-key error, a store name inside a
 * quoted literal, a phone number, a bearer token). This is a DENY-BY-DEFAULT, PATTERN-BASED scrubber. It removes:
 * quoted literals; e-mail addresses; phone-like digit runs; JWTs and bearer credentials; vendor secret keys
 * (`sk_live_...`, `rzp_test_...`); long hex runs (32+) and long opaque base64url runs (40+); Indian GSTIN and PAN
 * numbers; URL query strings (absolute or path-only); PostgreSQL `Key (col)=(value)` and DETAIL/HINT/CONTEXT tails;
 * and `key=value` pairs whose key names personal or secret data. UUIDs, ISO dates and correlation ids are kept (they
 * are identifiers, not personal data). Wording that carries the cause ("Signature verification failed", "Call to
 * undefined method X::y()") survives.
 *
 * LIMIT (pattern based, by nature): it cannot recognise an UNQUOTED free-text name or an arbitrary id that
 * application code interpolates into a message. Application code must not put such values in exception or log text.
 *
 * Output is always valid UTF-8, a single line, and never empty when the input was not.
 *
 * Self-contained on purpose (no dependency on other framework classes).
 */
final class FailureText
{
    public const MAX_LENGTH = 300;

    /** Returned instead of an empty string when a non-empty message could not be scrubbed with certainty. */
    public const WITHHELD = '[message withheld]';

    /** Message safe to store and show to operators. Never returns request values. */
    public static function safe(string $text): string
    {
        if (trim($text) === '') {
            return '';
        }
        try {
            $text = self::oneLine(self::utf8($text));
            $text = self::maskPii($text);

            // Quoted literals (single, double, backtick): the classic carrier of user values.
            $text = self::rx("/'[^']*'/u", "'?'", $text);
            $text = self::rx('/"[^"]*"/u', '"?"', $text);
            $text = self::rx('/`[^`]*`/u', '`?`', $text);
            // PostgreSQL "Key (col)=(value)" and DETAIL / HINT / CONTEXT tails can hold row values.
            $text = self::rx('/\bKey\s*\([^)]*\)\s*=\s*\([^)]*\)/iu', 'Key (?)=(?)', $text);
            $text = self::rx('/\b(DETAIL|HINT|CONTEXT)\s*:.*$/isu', '$1: (withheld)', $text);
            // key=value / key: value pairs where the key names personal or secret data.
            $text = self::rx(
                '/\b(email|e-mail|phone|mobile|name|store_name|tenant_name|address|gstin|pan|pincode|identity_id|user_id|password|token|refresh_token|access_token|api_key|secret)\s*[=:]\s*\S+/iu',
                '$1=?',
                $text
            );
        } catch (\Throwable) {
            return self::WITHHELD;
        }

        $text = trim(self::rx('/\s+/u', ' ', $text));
        $text = self::cut($text, self::MAX_LENGTH);
        return $text === '' ? self::WITHHELD : $text;
    }

    /**
     * Mask personal / secret shapes in free text WITHOUT altering anything else. For log MESSAGES, where a full
     * `safe()` would over-redact. Invalid UTF-8 is repaired first; control characters are not touched (see oneLine()).
     */
    public static function maskPii(string $text): string
    {
        try {
            return self::doMaskPii($text);
        } catch (\Throwable) {
            return self::WITHHELD; // never let the scrubber fail open or throw out of a logger
        }
    }

    private static function doMaskPii(string $text): string
    {
        $text = self::utf8($text);

        // Protect identifiers that are NOT personal data from the numeric/hex rules below.
        $keep = [];
        $protect = static function (string $pattern) use (&$text, &$keep): void {
            $text = (string) preg_replace_callback($pattern, static function (array $m) use (&$keep): string {
                $keep[] = $m[0];
                return "\x01" . (count($keep) - 1) . "\x02";
            }, $text);
        };
        $protect('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i'); // UUID
        $protect('/\bcorrelation_id=[0-9a-f]{6,32}\b/i');
        $protect('/\b\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?)?\b/');   // ISO date / time

        // JWTs, bearer credentials, vendor secret keys.
        $text = self::rx('/\beyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]*/', '[jwt]', $text);
        $text = self::rx('/\bBearer\s+[A-Za-z0-9._~+\/=-]{8,}/i', 'Bearer [token]', $text);
        $text = self::rx('/\b(?:sk|rk|pk|rzp)_(?:live|test)_[A-Za-z0-9]{6,}/i', '[secret-key]', $text);
        // Long opaque runs: hex (32+, e.g. a refresh token / hash) and base64url (40+).
        $text = self::rx('/\b[0-9a-fA-F]{32,}\b/', '[token]', $text);
        $text = self::rx('/(?<![\w\\\\.\/])[A-Za-z0-9_-]{40,}(?![\w])/', '[token]', $text);
        // Indian tax ids: GSTIN (contains a PAN, so first) and PAN.
        $text = self::rx('/\b\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]\b/', '[gstin]', $text);
        $text = self::rx('/\b[A-Z]{5}\d{4}[A-Z]\b/', '[pan]', $text);
        // E-mail addresses (also the partially-masked a***@host form).
        $text = self::rx('/[A-Za-z0-9._%+*\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[email]', $text);
        // Phone-like numbers: optional +, 8-15 digits possibly separated by space, dash, parenthesis.
        $text = self::rx('/(?<![\w.])\+?\d[\d\s().\-]{6,}\d(?![\w])/', '[number]', $text);
        // URL query strings: absolute, and path-only (`/x?email=a&name=b`).
        $text = self::rx('/(\bhttps?:\/\/[^\s?#]+)\?[^\s#]*/i', '$1?[query]', $text);
        $text = self::rx('/((?<![\w:])\/[\w.~%\/\-]*)\?[^\s#]*=[^\s#]*/', '$1?[query]', $text);

        // Restore the protected identifiers.
        return (string) preg_replace_callback('/\x01(\d+)\x02/', static fn (array $m): string => $keep[(int) $m[1]] ?? '', $text);
    }

    /** A request URI without its query string or fragment (the query string is where personal data lives), long opaque segments masked ({@see PathScrubber}). */
    public static function path(string $uri): string
    {
        $uri = self::oneLine(self::utf8($uri));
        $cut = strcspn($uri, '?#');
        $path = substr($uri, 0, $cut);
        // bearer-like secrets in the path (invitation / cancel / reset tokens) never reach a log or a row
        return $path === '' ? '/' : self::cut(PathScrubber::scrub($path), 2048);
    }

    /** CR/LF and other control characters become a single space: a logged value must never forge a log line. */
    public static function oneLine(string $text): string
    {
        return (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text);
    }

    /** Valid UTF-8 whatever came in (invalid bytes are replaced, never allowed to void the whole message). */
    public static function utf8(string $text): string
    {
        if ($text === '' || preg_match('//u', $text) === 1) {
            return $text;
        }
        if (function_exists('mb_convert_encoding')) {
            $fixed = @mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            if (is_string($fixed) && preg_match('//u', $fixed) === 1) {
                return $fixed;
            }
        }
        if (function_exists('iconv')) {
            $fixed = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            if (is_string($fixed) && preg_match('//u', $fixed) === 1) {
                return $fixed;
            }
        }
        return (string) preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $text);
    }

    /** Cut to at most $max CHARACTERS without splitting a multi-byte character (PostgreSQL rejects invalid UTF-8). */
    public static function cut(string $text, int $max): string
    {
        $text = self::utf8($text);
        if (function_exists('mb_strlen')) {
            return mb_strlen($text, 'UTF-8') > $max ? mb_substr($text, 0, $max - 1, 'UTF-8') . '…' : $text;
        }
        if (preg_match('/^.{0,' . $max . '}$/su', $text) === 1) {
            return $text;
        }
        preg_match('/^.{0,' . ($max - 1) . '}/su', $text, $m);
        return ($m[0] ?? '') . '…';
    }

    /** Fully-qualified class names only (defence in depth for the error_class column). */
    public static function safeClass(string $class): string
    {
        $class = (string) preg_replace('/[^A-Za-z0-9_\\\\:.\-]/', '', $class);
        return $class === '' ? 'UnknownError' : substr($class, 0, 200);
    }

    /** preg_replace that never degrades to an empty string: a failing pattern aborts the scrub (caller withholds). */
    private static function rx(string $pattern, string $replacement, string $text): string
    {
        $out = preg_replace($pattern, $replacement, $text);
        if ($out === null) {
            throw new \RuntimeException('scrub pattern failed');
        }
        return $out;
    }
}
