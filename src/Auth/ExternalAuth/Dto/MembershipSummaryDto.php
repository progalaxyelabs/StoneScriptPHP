<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Dto;

/**
 * ProvisionTenantRoute's legacy/supplementary `membership` field
 * (framework-spec.md §6, "Legacy / supplementary fields retained for
 * backward compatibility"). `id` is nullable — it comes from the auth
 * service's `membership_id`, which is only populated on a fresh (non-replay)
 * create-membership call.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Dto
 */
class MembershipSummaryDto
{
    public ?string $id = null;
    public string $tenant_id = '';
    public string $role = '';
}
