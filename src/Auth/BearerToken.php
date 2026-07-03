<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth;

/**
 * BearerToken — single canonical utility for normalizing "Bearer "-prefixed
 * Authorization header values across the framework.
 *
 * Both ends of the header round-trip need the SAME strip logic:
 *   - Inbound: `BaseExternalAuthRoute::getBearerToken()` strips "Bearer " from
 *     the current request's Authorization header to obtain the bare token.
 *   - Outbound: `AuthServiceClient::buildAuthHeader()` must not double the
 *     prefix when re-adding it before forwarding the token to the auth
 *     service.
 *
 * Before this consolidation (v5.5.5), both call sites carried their own copy
 * of an equivalent-but-independently-maintained regex/`str_starts_with()`
 * check, and any code that runs OUTSIDE a route class — e.g. a platform's
 * `config/auth.php` `tenants_resolver` closure, which reads
 * `$_SERVER['HTTP_AUTHORIZATION']` directly and has no access to
 * `BaseExternalAuthRoute` — had no framework utility to call and was forced
 * to hand-roll a THIRD copy. At least one downstream platform did exactly
 * that (a local `App\Lib\BearerToken::strip()` added as a same-day fast fix
 * for a double-"Bearer " exchange-403) before this class existed. That
 * platform-local copy is now deleted — its `$tenantsResolver` calls this
 * framework utility directly, and
 * `ExternalAuthServiceClient::getMemberships()` itself is *also* immune to
 * the double-prefix bug on its own (v5.5.3, `buildAuthHeader()` normalizes
 * unconditionally) — this class exists purely to give non-route callers a
 * public, single-source-of-truth entry point instead of leaving them to
 * duplicate the regex.
 *
 * @package StoneScriptPHP\Auth
 */
final class BearerToken
{
    private const PATTERN = '/^\s*Bearer\s+/i';

    /**
     * Strip a leading "Bearer " prefix (case-insensitive, tolerant of extra
     * whitespace, per RFC 6750's `Authorization: Bearer <token>` scheme)
     * from a raw Authorization header value.
     *
     * Idempotent: safe to call on an already-bare token (no prefix present)
     * — returned unchanged. Safe to call on `null`/`''` — returned unchanged,
     * so callers don't need a separate null-check before calling this.
     *
     * @param string|null $value Raw header value (e.g.
     *   `$_SERVER['HTTP_AUTHORIZATION']`, which includes "Bearer ") OR an
     *   already-bare token.
     * @return string|null The bare token, or the original value if it was
     *   null/empty.
     */
    public static function strip(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $stripped = preg_replace(self::PATTERN, '', $value);

        // preg_replace() only returns null on a regex engine error (e.g. a
        // backtrack limit) — fall back to the original value rather than
        // silently losing the token.
        return $stripped ?? $value;
    }
}
