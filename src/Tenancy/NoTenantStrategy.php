<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tenancy;

use StoneScriptPHP\Auth\AuthenticatedUser;

/**
 * NoTenantStrategy
 *
 * The framework's default `TenancyStrategyInterface` implementation. It injects
 * NO additional tenant-resolution logic — it only forwards whatever `tenant_id`
 * is already present on the authenticated API token (a claim JwtAuthMiddleware
 * already decoded from the JWT). It never derives tenant_id from a URL param,
 * subdomain, header, or any other out-of-band source.
 *
 * This is a deliberate, byte-for-byte reproduction of `GatewayTenantMiddleware`'s
 * pre-Phase-1 behavior (`$user->tenant_id ?? null` read directly in the
 * middleware). Both single-tenant (T1) platforms — where tenant_id is simply
 * never present — and today's API-token-model multi-tenant (T2) platforms — where
 * tenant_id already arrives on the API token itself — continue to work exactly as
 * before with this strategy. A future multi-tenancy plugin can register a
 * different strategy (see `PluginInterface::tenancyStrategy()`) to add
 * resolution from other sources without touching `GatewayTenantMiddleware`.
 *
 * @package StoneScriptPHP\Tenancy
 */
class NoTenantStrategy implements TenancyStrategyInterface
{
    public function resolveTenantId(array $request, ?AuthenticatedUser $user): ?string
    {
        return $user?->tenant_id;
    }
}
