<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Dto;

/**
 * Response contract for GET {prefix}/memberships — shape of the external
 * auth service's memberships-list payload (AUTH-SPEC §5d:
 * `{ "success": true, "memberships": [...] }`).
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Dto
 */
class MembershipsResponseDto
{
    public bool $success = false;

    /** @var \StoneScriptPHP\Auth\ExternalAuth\Dto\MembershipListItemDto[] */
    public array $memberships = [];
}
