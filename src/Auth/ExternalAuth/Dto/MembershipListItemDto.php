<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Dto;

/**
 * One entry of {@see MembershipsResponseDto} — shape of a single membership
 * as returned by the external auth service's memberships-list payload.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Dto
 */
class MembershipListItemDto
{
    public string $id = '';
    public string $platform_code = '';
    public string $tenant_id = '';
    public string $tenant_slug = '';
    public string $tenant_name = '';
    public string $status = '';
    public string $joined_at = '';
}
