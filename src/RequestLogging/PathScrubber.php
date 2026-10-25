<?php

declare(strict_types=1);

namespace StoneScriptPHP\RequestLogging;

/**
 * Masks bearer-like secrets that applications put in URL PATHS (invitation tokens, booking-cancel tokens,
 * password-reset links ...) before a path reaches request_logs.path, the referer column or any log line.
 * Applied by {@see FailureText::path()}, which every framework path logger uses.
 *
 * A path segment is replaced by MARKER when it is opaque:
 *   - 20+ characters of [A-Za-z0-9_-], not purely digits, and not a UUID, and not a readable kebab/snake word
 *     list (all lowercase letters, separated by - or _, each word <= 16 letters: "workspace-subscriptions-list"); or
 *   - it contains a hex run of 32+ characters (the run is replaced, so "<hex>.json" keeps its extension).
 * Kept intact: route words, short ids, numeric ids, UUIDs (RFC 4122 shape).
 *
 * Applications can add their own patterns: PathScrubber::addPattern('~/promo/[^/]+~') (the whole match becomes the
 * marker), or config `request_logging.path_scrub_patterns` => [...]. An invalid pattern is ignored, never fatal.
 */
final class PathScrubber
{
    public const MARKER = '{redacted}';

    /** @var string[] */
    private static array $extra = [];

    public static function addPattern(string $regex): void
    {
        if (@preg_match($regex, '') === false) {
            return; // invalid pattern: ignore
        }
        if (!in_array($regex, self::$extra, true)) {
            self::$extra[] = $regex;
        }
    }

    /** @param string[] $patterns replaces the configured set */
    public static function setPatterns(array $patterns): void
    {
        self::$extra = [];
        foreach ($patterns as $p) {
            if (is_string($p)) {
                self::addPattern($p);
            }
        }
    }

    public static function reset(): void
    {
        self::$extra = [];
    }

    /** Scrub a path (no query string expected; a '?' tail is left to the caller to drop first). */
    public static function scrub(string $path): string
    {
        foreach (self::$extra as $rx) {
            $out = @preg_replace($rx, self::MARKER, $path);
            if (is_string($out)) {
                $path = $out;
            }
        }
        if (strlen($path) < 20) {
            return $path; // no segment can qualify
        }
        $segments = explode('/', $path);
        foreach ($segments as $i => $seg) {
            if (strlen($seg) >= 20) {
                $segments[$i] = self::scrubSegment($seg);
            }
        }
        return implode('/', $segments);
    }

    private static function scrubSegment(string $seg): string
    {
        $seg = (string) preg_replace('/[0-9a-fA-F]{32,}/', self::MARKER, $seg);
        if (!preg_match('/^[A-Za-z0-9_-]{20,}$/', $seg) || ctype_digit($seg)) {
            return $seg;
        }
        if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $seg)) {
            return $seg; // UUID
        }
        if (preg_match('/^[a-z]{1,16}(?:[-_][a-z]{1,16})+$/', $seg)) {
            return $seg; // readable route words
        }
        return self::MARKER;
    }
}
