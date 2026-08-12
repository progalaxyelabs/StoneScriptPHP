<?php

declare(strict_types=1);

namespace StoneScriptPHP\Routing;

/**
 * RouteAccess — the route-level access model that supersedes the `is_public` boolean.
 *
 * A route declares two orthogonal dimensions:
 *
 *   - `access` ∈ {public, authentication, authorization} — what a request needs.
 *       * public         → no token required.
 *       * authentication → an identity/auth token (`purpose=authentication`).
 *       * authorization  → an API token (`purpose=authorization`).
 *     The access value equals the token `purpose` the framework asserts.
 *
 *   - `token_type` ∈ {access, refresh} — WHICH credential the route consumes.
 *       * access  → a Bearer access token (stateless, {@see \StoneScriptPHP\Auth\Middleware\AccessTokenMiddleware}).
 *       * refresh → a body refresh token (stateful, {@see \StoneScriptPHP\Auth\Middleware\RefreshTokenMiddleware}).
 *
 * These are orthogonal and BOTH must be expressed: the old `is_public=true` bucket
 * conflated "no Authorization header" with "no credential at all" — but logout,
 * refresh and identity-refresh carry a refresh token in the BODY. They are not
 * public; they are `authentication`/`authorization` + `token_type=refresh`.
 *
 * @package StoneScriptPHP\Routing
 * @since   6.2.0
 */
final class RouteAccess
{
    // access values
    public const PUBLIC         = 'public';
    public const AUTHENTICATION = 'authentication';
    public const AUTHORIZATION  = 'authorization';

    // token_type values
    public const TOKEN_ACCESS  = 'access';
    public const TOKEN_REFRESH = 'refresh';

    public const ACCESS_VALUES = [
        self::PUBLIC,
        self::AUTHENTICATION,
        self::AUTHORIZATION,
    ];

    public const TOKEN_TYPE_VALUES = [
        self::TOKEN_ACCESS,
        self::TOKEN_REFRESH,
    ];

    public static function isValidAccess(?string $access): bool
    {
        return $access !== null && in_array($access, self::ACCESS_VALUES, true);
    }

    public static function isValidTokenType(?string $tokenType): bool
    {
        return $tokenType !== null && in_array($tokenType, self::TOKEN_TYPE_VALUES, true);
    }
}
