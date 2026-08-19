<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Dto;

/**
 * Shape of the external auth service's "identity" payload — embedded in its
 * register/login/register-tenant responses (RegisterResponse /
 * LoginSuccessResponse / LoginNewIdentityResponse / RegisterTenantResponse
 * in the auth service's own contract).
 *
 * NOTE: unlike {@see IdentitySummaryDto} (the framework's own §6
 * session-contract shape used by ExchangeRoute/ProvisionTenantRoute/
 * ProfileRoute), this has NO `id` field — the identity id is always a
 * sibling top-level field on the containing response (`identity_id`).
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Dto
 */
class IdentityInfoDto
{
    public string $email = '';
    public string $display_name = '';
    public string $photo_url = '';
    public bool $is_email_verified = false;
}
