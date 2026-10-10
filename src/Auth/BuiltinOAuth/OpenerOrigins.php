<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\BuiltinOAuth;

use StoneScriptPHP\Env;

/**
 * Which window origins may receive the popup's `postMessage` (the sign-in result with tokens).
 *
 * The callback page posts to `window.opener`. With targetOrigin `'*'` ANY page that opens the initiate URL in a
 * popup would receive a victim's tokens. Instead the page posts ONLY to explicitly allowed origins (the
 * platform's ALLOWED_ORIGINS, or `allowed_origins` passed to `GoogleOAuthRoutes::register`): the browser
 * delivers a message only when the opener's real origin equals the target, so every other opener gets nothing.
 * Additionally the initiate route binds the OAuth `state` to the origin that opened the popup (from `Referer`)
 * and refuses origins that are not allowed.
 *
 * The `Referer` is a HINT only (a client may strip or, for its own requests, forge it): it is used to give an
 * early, clear refusal and to narrow the target. The lock itself rests on the allowed list and on the browser,
 * which delivers a `postMessage` only to a window whose real origin equals the target origin.
 */
final class OpenerOrigins
{
    /** Canonical `scheme://host[:port]` (lower-case, default ports dropped) or null when unusable / a wildcard. */
    public static function normalize(string $origin): ?string
    {
        $origin = trim($origin);
        if ($origin === '' || $origin === '*' || $origin === 'null') {
            return null;
        }
        $p = parse_url($origin);
        if (!is_array($p) || !isset($p['scheme'], $p['host']) || !in_array(strtolower($p['scheme']), ['http', 'https'], true)) {
            return null;
        }
        if (isset($p['path']) && $p['path'] !== '' && $p['path'] !== '/') {
            return null; // an origin has no path
        }
        $scheme = strtolower($p['scheme']);
        $host = strtolower($p['host']);
        $port = $p['port'] ?? null;
        if ($port !== null && (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
            $port = null;
        }
        return $scheme . '://' . (str_contains($host, ':') ? "[$host]" : $host) . ($port !== null ? ':' . $port : '');
    }

    /**
     * The explicitly configured origins only (no implicit same-origin entry): `allowed_origins` or Env ALLOWED_ORIGINS.
     *
     * @param array<int, string>|null $configured explicit list, or null to use Env ALLOWED_ORIGINS
     * @return list<string> canonical, de-duplicated, wildcard-free
     */
    public static function configured(?array $configured = null): array
    {
        if ($configured === null) {
            try {
                $configured = array_map('trim', explode(',', (string) Env::get_instance()->ALLOWED_ORIGINS));
            } catch (\Throwable) {
                $configured = [];
            }
        }
        $out = [];
        foreach ($configured as $o) {
            $n = self::normalize((string) $o);
            if ($n !== null) {
                $out[$n] = $n;
            }
        }
        return array_values($out);
    }

    /**
     * The origins that may receive the sign-in result: the configured list PLUS the request's own origin, so a
     * deployment where the sign-in page is served from the same origin as the API works with no configuration.
     * (Safe: an opener on the API's own origin is the platform's own page; a foreign page's origin is never equal
     * to the Host the victim's browser sends to this server.)
     *
     * @param array<int, string>|null $configured explicit list, or null to use Env ALLOWED_ORIGINS
     * @param string|null $selfOrigin override for the request's own origin (tests); null = derive from the request
     * @return list<string>
     */
    public static function allowed(?array $configured = null, ?string $selfOrigin = null): array
    {
        $out = self::configured($configured);
        $self = $selfOrigin !== null ? self::normalize($selfOrigin) : self::ownOrigin();
        if ($self !== null && !in_array($self, $out, true)) {
            $out[] = $self;
        }
        return $out;
    }

    /** `scheme://host[:port]` of the current request (Host header + HTTPS / X-Forwarded-Proto), or null off-request. */
    public static function ownOrigin(): ?string
    {
        $host = $_SERVER['HTTP_HOST'] ?? null;
        if (!is_string($host) || $host === '') {
            return null;
        }
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        return self::normalize(($https ? 'https' : 'http') . '://' . $host);
    }

    /**
     * Pre-flight summary for `php stone auth:check-origins`.
     *
     * @param array<int, string>|null $configured
     * @return array{origins: list<string>, ok: bool, message: string}
     */
    public static function report(?array $configured = null): array
    {
        $origins = self::configured($configured);
        if ($origins === []) {
            return [
                'origins' => [],
                'ok' => false,
                'message' => 'ALLOWED_ORIGINS is empty. The Google sign-in popup only works when the sign-in page is served from the API\'s own origin; '
                    . 'any other site (an Angular app on its own host) gets no sign-in result. Set ALLOWED_ORIGINS to every origin that hosts a sign-in page.',
            ];
        }
        return ['origins' => $origins, 'ok' => true, 'message' => count($origins) . ' allowed origin(s).'];
    }

    /** The origin of a Referer header value, or null when absent/unusable. */
    public static function fromReferer(?string $referer): ?string
    {
        if ($referer === null || $referer === '') {
            return null;
        }
        $p = parse_url($referer);
        if (!is_array($p) || !isset($p['scheme'], $p['host'])) {
            return null;
        }
        return self::normalize($p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : ''));
    }
}
