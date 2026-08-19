<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Dto;

/**
 * Response contract for POST {prefix}/provision-tenant (framework-spec.md
 * §6, AUTH-SPEC §5a). See
 * {@see \StoneScriptPHP\Auth\ExternalAuth\Routes\ProvisionTenantRoute}.
 *
 * A platform-specific subclass of ProvisionTenantRoute (registered via
 * `ExternalAuthConfig::$provisionTenantRouteClass`) may add fields to its
 * response — if so, declare a matching subclass of this DTO and wire it via
 * `'response' => YourDto::class` on that platform's own route registration.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Dto
 */
class ProvisionTenantResponseDto
{
    public ?string $access_token = null;
    public string $token_type = 'Bearer';
    public int $expires_in = 0;
    public ?TenantSummaryDto $active_tenant = null;

    /** @var \StoneScriptPHP\Auth\ExternalAuth\Dto\TenantSummaryDto[] */
    public array $available_tenants = [];

    public string $active_role = '';

    /** @var string[] */
    public array $available_roles = [];

    public ?IdentitySummaryDto $identity = null;
    public ?MembershipSummaryDto $membership = null;
}
