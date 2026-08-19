<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Dto;

/**
 * The framework-built §6 session-contract "tenant" shape returned by
 * ExchangeRoute / ProvisionTenantRoute / ProfileRoute's api-token-model
 * branch (framework-spec.md §6, `active_tenant` / `available_tenants[]`).
 *
 * Unlike {@see TenantInfoDto} (the auth service's own fixed-shape struct),
 * this object is assembled locally from a PLATFORM-SUPPLIED
 * `tenants_resolver` callable — the platform controls what a tenant object
 * looks like beyond `id`. Only `id` is guaranteed; the rest are typed
 * nullable/optional because ExchangeRoute's minimal no-resolver fallback
 * returns `['id' => $tenantId]` alone. A platform whose resolver returns
 * additional fields consumed by its own Angular code should extend this DTO
 * (or declare its own) — this is the framework's documented baseline, not an
 * enforced runtime schema (StoneScriptPHP does not (de)serialize against
 * `response:` DTOs; they are a client-generation contract only).
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Dto
 */
class TenantSummaryDto
{
    public string $id = '';
    public ?string $name = null;
    public ?string $slug = null;
    public ?string $db_schema = null;
}
