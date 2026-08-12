<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth;

/**
 * TokenClaims — the typed auth-token vocabulary.
 *
 * Two orthogonal dimensions are stamped on every auth token this framework mints,
 * and asserted by the access/refresh middleware:
 *
 *   - `type`    ∈ {access, refresh} — what the credential IS. Strict equality.
 *   - `purpose` ∈ {authentication, authorization} — what the credential is FOR.
 *                 `authentication` = identity/auth-token (proves who you are);
 *                 `authorization`  = API token (authorises tenant-scoped requests).
 *                 Strict equality.
 *
 * These are DISTINCT from the pre-existing `token_type` claim (values `card` /
 * `platform`), which denotes the token CLASS. `type`/`purpose` do not overload
 * `token_type` — a card carries `token_type=card`, `purpose=authorization`, and
 * `type=access` or `type=refresh`.
 *
 * @package StoneScriptPHP\Auth
 * @since   6.2.0
 */
final class TokenClaims
{
    /** Claim key stamped with the token TYPE (access|refresh). */
    public const CLAIM_TYPE = 'type';

    /** Claim key stamped with the token PURPOSE (authentication|authorization). */
    public const CLAIM_PURPOSE = 'purpose';

    // ── type values ──────────────────────────────────────────────────────────
    public const TYPE_ACCESS  = 'access';
    public const TYPE_REFRESH = 'refresh';

    // ── purpose values ───────────────────────────────────────────────────────
    /** Identity / auth tokens. */
    public const PURPOSE_AUTHENTICATION = 'authentication';
    /** API tokens. */
    public const PURPOSE_AUTHORIZATION = 'authorization';

    public const TYPES = [
        self::TYPE_ACCESS,
        self::TYPE_REFRESH,
    ];

    public const PURPOSES = [
        self::PURPOSE_AUTHENTICATION,
        self::PURPOSE_AUTHORIZATION,
    ];

    public static function isValidType(?string $type): bool
    {
        return $type !== null && in_array($type, self::TYPES, true);
    }

    public static function isValidPurpose(?string $purpose): bool
    {
        return $purpose !== null && in_array($purpose, self::PURPOSES, true);
    }
}
