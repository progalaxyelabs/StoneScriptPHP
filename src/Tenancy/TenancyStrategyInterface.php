<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tenancy;

use StoneScriptPHP\Auth\AuthenticatedUser;

/**
 * TenancyStrategyInterface
 *
 * Phase 1 extensibility seam: decouples `GatewayTenantMiddleware` from directly
 * reading `tenant_id` off the authenticated user, so that a future multi-tenancy
 * plugin can supply an alternate resolution strategy (e.g. resolving tenant_id
 * from a URL `{tenantId}` param for T3 platforms, a subdomain, or a header)
 * without modifying `GatewayTenantMiddleware` itself.
 *
 * `resolveTenantId()` is called once per request, AFTER JwtAuthMiddleware has
 * populated `auth()` (if the request carries a valid token), and BEFORE the
 * route handler runs.
 *
 * @package StoneScriptPHP\Tenancy
 */
interface TenancyStrategyInterface
{
    /**
     * Resolve the tenant_id to scope this request's `Database::fn()` calls to.
     *
     * @param array $request The current request array (input, headers, route params).
     * @param AuthenticatedUser|null $user The authenticated user for this request, or
     *   null if the route is public / unauthenticated.
     * @return string|null The tenant_id to set on the gateway client, or null to leave
     *   the gateway client's tenant context untouched (e.g. main/shared database).
     */
    public function resolveTenantId(array $request, ?AuthenticatedUser $user): ?string;
}
