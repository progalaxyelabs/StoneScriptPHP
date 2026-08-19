<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Dto;

/**
 * Response contract for GET {prefix}/me — the §6 API-token-model branch
 * ONLY (framework-spec.md §6). See
 * {@see \StoneScriptPHP\Auth\ExternalAuth\Routes\ProfileRoute}.
 *
 * IMPORTANT: ProfileRoute has a SECOND, structurally different response
 * shape — when `tenants_resolver`/`roles_resolver` are NOT configured (T1
 * platforms, or any platform that hasn't wired resolvers), it falls back to
 * proxying the raw auth-service `/api/auth/memberships` response, which is
 * NOT this shape. A platform that always configures both resolvers gets this
 * DTO's shape on every call; a platform relying on the fallback branch should
 * NOT declare `'response' => ProfileResponseDto::class` on its `/me` route
 * registration — that would misrepresent the fallback's real payload
 * (client-generation is not runtime-enforced, but a wrong declared type is
 * worse than none). See ProfileRoute::process() for the branch condition.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Dto
 */
class ProfileResponseDto
{
    public ?IdentitySummaryDto $identity = null;
    public ?TenantSummaryDto $active_tenant = null;

    /** @var \StoneScriptPHP\Auth\ExternalAuth\Dto\TenantSummaryDto[] */
    public array $available_tenants = [];

    public ?string $active_role = null;

    /** @var string[] */
    public array $available_roles = [];

    public ?string $display_name = null;
    public string $token_type = 'card';
}
