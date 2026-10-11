<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tenancy;

/**
 * Optional companion to {@see TenancyStrategyInterface}: a strategy that routes requests to per-tenant databases
 * implements this and returns true, so the application can refuse to boot when the tenant schema
 * (DB_GATEWAY_TENANT_SCHEMA_NAME) is not configured.
 *
 * A strategy that does not implement it (including a deliberate no-op strategy) is treated as NOT routing tenants.
 * Tenancy configured through `require_api_token`, `store_access` or `tenant_url_match` is detected from those
 * options, not from the strategy.
 */
interface DeclaresTenantRouting
{
    public function routesTenants(): bool;
}
