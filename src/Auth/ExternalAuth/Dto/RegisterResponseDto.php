<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Dto;

/**
 * Response contract for POST {prefix}/register. RegisterRoute proxies to ONE
 * of two external auth-service endpoints depending on
 * `ExternalAuthConfig::$registrationMode`, each returning a DIFFERENT payload
 * shape. This DTO is the flattened union of both — every field is optional
 * except the token envelope, and only the fields for the ACTIVE mode will
 * actually be present at runtime:
 *
 *  - mode='tenant' (default, "the correct endpoint for most platforms" per
 *    ExternalAuthServiceClient::registerTenant()) → POST /api/auth/register-tenant
 *    → tenant, user, membership_id, access_token, refresh_token, token_type,
 *    expires_in.
 *  - mode='identity' → POST /api/auth/register → identity_id, email,
 *    email_verified, verification_sent, access_token, refresh_token,
 *    token_type, expires_in, identity_was_created, identity.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Dto
 */
class RegisterResponseDto
{
    // ── mode='identity' fields ──
    public ?string $identity_id = null;
    public ?string $email = null;
    public ?bool $email_verified = null;
    public ?bool $verification_sent = null;
    public ?bool $identity_was_created = null;
    public ?IdentityInfoDto $identity = null;

    // ── mode='tenant' fields (default) ──
    public ?TenantInfoDto $tenant = null;
    public ?IdentityInfoDto $user = null;
    public ?string $membership_id = null;

    // ── shared, present in both modes ──
    public string $access_token = '';
    public string $refresh_token = '';
    public string $token_type = '';
    public int $expires_in = 0;
}
