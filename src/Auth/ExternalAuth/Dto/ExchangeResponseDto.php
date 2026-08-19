<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Dto;

/**
 * Response contract for POST {prefix}/exchange (framework-spec.md §6 API
 * token envelope). See {@see \StoneScriptPHP\Auth\ExternalAuth\Routes\ExchangeRoute}.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Dto
 */
class ExchangeResponseDto
{
    public string $access_token = '';
    public string $token_type = 'Bearer';
    public int $expires_in = 0;
    public ?TenantSummaryDto $active_tenant = null;

    /**
     * NOTE: the docblock below MUST use the fully-qualified class name (not
     * the short `TenantSummaryDto[]` form) — the client generator's
     * array-element resolver (reflectDtoElementType()) only auto-resolves a
     * bare short name against `App\Dto\{Name}` / `App\Models\{Name}`, which
     * doesn't cover a framework-namespaced DTO.
     * @var \StoneScriptPHP\Auth\ExternalAuth\Dto\TenantSummaryDto[]
     */
    public array $available_tenants = [];

    public string $active_role = '';

    /** @var string[] */
    public array $available_roles = [];
}
