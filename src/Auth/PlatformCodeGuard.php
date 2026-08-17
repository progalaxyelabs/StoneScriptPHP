<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth;

/**
 * PlatformCodeGuard — cross-platform token rejection (SECURITY).
 *
 * ## The gap this closes
 *
 * A framework deployment where multiple platforms share ONE central auth
 * issuer has a single signing key/JWKS across the whole fleet. Signature +
 * `exp` + `iss` verification alone (see {@see TrustedIssuerVerifier},
 * {@see RsaJwtHandler}, {@see JwtHandler}) proves a token is a genuine,
 * unexpired token issued by that shared auth service — it does NOT prove the
 * token was ever meant to be usable on THIS platform. Every auth/API token
 * carries a `platform_code` claim recording which platform it was minted
 * for, but until this guard existed nothing ever compared that claim to the
 * serving platform's own configured code: `platform_code` was mere
 * provenance, not an access boundary. A token minted at platform A was
 * therefore cryptographically valid — and silently ACCEPTED — at platform
 * B's identity-token-authenticated routes (provision-tenant, exchange,
 * accept-invite, memberships, ...), letting an identity join/act on a
 * platform it never registered or logged into.
 *
 * This class is the single place that decision lives. Every
 * identity-token-authenticated entry point (see
 * {@see \StoneScriptPHP\Auth\ExternalAuth\Routes\BaseExternalAuthRoute::guardPlatformCode()}
 * and {@see \StoneScriptPHP\Auth\Invitations\InvitationCompletionService})
 * calls {@see self::check()} and converts the result into its own error
 * shape (`ApiResponse` for routes, `InvitationException` for the invitation
 * flow) — the RULE lives here once; only the response envelope differs
 * per caller.
 *
 * ## Decision table
 *
 *  - Configured platform code + matching claim  -> ADMIT (byte-identical to
 *    pre-fix behavior for same-platform traffic).
 *  - Configured platform code + mismatched claim -> REJECT ('mismatch').
 *  - Configured platform code + missing/empty claim -> REJECT
 *    ('missing_claim'). Fail CLOSED: an unprovable token is treated as
 *    inadmissible, never as "must be fine." Confirmed safe for live traffic
 *    because the auth service's Claims struct always stamps `platform_code`
 *    on every auth/API token it issues — a real token missing this claim
 *    only happens for a forged/hand-crafted token, which SHOULD be rejected.
 *  - Unconfigured platform code (`PLATFORM_CODE` never set) -> ADMIT
 *    ('unconfigured'), but every caller of this class is required to log a
 *    loud warning on that branch. This is a deliberate backward-compat
 *    choice, not an oversight: a framework consumer that has not set
 *    PLATFORM_CODE at all has not opted into platform-scoped tokens (there
 *    is no "this platform's own code" to compare against — rejecting
 *    everything would brick every such deployment outright, including
 *    single-platform / T1 setups that never needed the concept). The loud
 *    warning is what keeps this from silently re-opening the hole for a
 *    platform that DOES set PLATFORM_CODE: any accidental unset shows up in
 *    logs instead of vanishing.
 */
final class PlatformCodeGuard
{
    /** Configured platform code matches the token's claim. */
    public const OK = null;

    /** No platform code configured on this server — check skipped (fail-open, log required). */
    public const UNCONFIGURED = 'unconfigured';

    /** Platform code is configured but the token carries no (or an empty) platform_code claim. */
    public const MISSING_CLAIM = 'missing_claim';

    /** Platform code is configured and the token's claim does not match it. */
    public const MISMATCH = 'mismatch';

    private function __construct()
    {
        // Static-only utility class.
    }

    /**
     * Evaluate the guard.
     *
     * @param string|null $tokenPlatformCode The `platform_code` claim read off the
     *   verified token (auth token or API token — same claim name on both).
     * @param string|null $configuredPlatformCode This server's own configured
     *   platform code (`ExternalAuthConfig::$platformCode` / `PLATFORM_CODE`).
     * @return string|null One of self::UNCONFIGURED / self::MISSING_CLAIM /
     *   self::MISMATCH describing why the token is inadmissible, or
     *   self::OK (null) when it is admissible and the caller may proceed.
     */
    public static function check(?string $tokenPlatformCode, ?string $configuredPlatformCode): ?string
    {
        if ($configuredPlatformCode === null || $configuredPlatformCode === '') {
            return self::UNCONFIGURED;
        }

        if ($tokenPlatformCode === null || $tokenPlatformCode === '') {
            return self::MISSING_CLAIM;
        }

        if (!hash_equals($configuredPlatformCode, $tokenPlatformCode)) {
            return self::MISMATCH;
        }

        return self::OK;
    }

    /**
     * True when {@see self::check()}'s result means the caller must actually
     * reject the request (as opposed to admitting it, with or without a
     * warning).
     */
    public static function isRejection(?string $reason): bool
    {
        return $reason === self::MISSING_CLAIM || $reason === self::MISMATCH;
    }
}
