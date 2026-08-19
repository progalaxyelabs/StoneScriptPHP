<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Dto;

/**
 * Response contract for POST {prefix}/login and POST {prefix}/oauth/callback
 * (both proxy to the external auth service's login-response payload, which is
 * an untagged union on the wire — one of several possible shapes depending on
 * what happened). Also reused for POST {prefix}/select-tenant (success-variant
 * subset only) since the generator does not yet support union response types
 * (see CHANGELOG for v9.6.0 — request-DTO reflection landed, response-union
 * reflection did not).
 *
 * Every field is optional — this is a FLATTENED union of five variants; only
 * the fields for whichever variant actually fired will be present at
 * runtime. Discriminate the same way the raw JSON always required:
 *
 *  - `access_token` set, `identity_was_created` present → success variant
 *    (fresh login, or a brand-new identity with tokens already issued)
 *  - `requires_tenant_selection === true` → tenant-selection-required variant;
 *    call {@see \StoneScriptPHP\Auth\ExternalAuth\Routes\SelectTenantRoute}
 *    next with `selection_token`.
 *  - `exists === false` (and nothing else set) → identity-not-found variant
 *    (`/api/identity/login` only, not `/api/auth/login`)
 *  - `success === false` + `confirm_handle` set → OAuth-no-existing-account
 *    variant (OAuth signin, no existing account — AUTH-SPEC §3b P2)
 *  - `oauth_pending === true` → the OAuth connection needs
 *    POST /api/oauth/promote (`oauth_state`) to commit, or
 *    DELETE /api/oauth/abandon to discard.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Dto
 */
class LoginResponseDto
{
    // success variant (fresh login / new-identity-with-tokens variant)
    public ?string $access_token = null;
    public ?string $refresh_token = null;
    public ?string $token_type = null;
    public ?int $expires_in = null;
    public ?IdentityInfoDto $identity = null;
    public ?string $auth_method = null;
    public ?string $oauth_provider = null;
    public ?bool $identity_was_created = null;
    public ?bool $oauth_pending = null;
    public ?string $oauth_state = null;

    // tenant-selection-required variant
    public ?bool $requires_tenant_selection = null;
    public ?string $selection_token = null;

    // identity-not-found variant (POST /api/identity/login only)
    public ?bool $exists = null;

    // OAuth-no-existing-account variant
    public ?bool $success = null;
    public ?string $error = null;
    public ?string $message = null;
    public ?OAuthVerifiedProfileDto $verified_profile = null;
    public ?string $confirm_handle = null;
}
