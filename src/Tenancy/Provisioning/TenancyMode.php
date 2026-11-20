<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tenancy\Provisioning;

use StoneScriptPHP\Env;

/**
 * Compatibility switch and authority selection for tenancy.
 *
 * LEGACY_TENANCY (default TRUE) keeps the legacy (14.x) provisioning flow; set it to false to switch a platform to the
 * current flow (the tenancy provisioning sequence, authority membership, tenant-DB roles). Platforms switch one at a
 * time. In the current flow AUTH_MODE picks the MembershipAuthority: external|hybrid use the auth service tenancy
 * endpoints, builtin uses the platform main database.
 */
final class TenancyMode
{
    private function __construct()
    {
    }

    /** True while the platform still runs the legacy (14.x) flow. This is the default. */
    public static function legacy(?Env $env = null): bool
    {
        return ($env ?? Env::get_instance())->LEGACY_TENANCY === true;
    }

    /**
     * @param callable|null $transport AuthTenancyClient transport override (tests).
     * @throws \RuntimeException when the selected mode is missing configuration (never guesses a default)
     */
    public static function authority(Env $env, DatabaseMainTenantStore $store, ?callable $transport = null): MembershipAuthority
    {
        $mode = strtolower($env->AUTH_MODE);
        if ($mode === 'external' || $mode === 'hybrid') {
            if ($env->AUTH_SERVICE_URL === '') {
                throw new \RuntimeException('AUTH_SERVICE_URL is required for tenancy with AUTH_MODE=' . $mode);
            }
            return new ExternalMembershipAuthority(new AuthTenancyClient(
                $env->AUTH_SERVICE_URL,
                $env->PLATFORM_CLIENT_ID,
                $env->PLATFORM_CLIENT_SECRET,
                $transport,
            ), $store);
        }
        if ($mode === 'builtin') {
            $slug = $env->DB_GATEWAY_PLATFORM ?? '';
            if ($slug === '') {
                throw new \RuntimeException('DB_GATEWAY_PLATFORM (the platform slug) is required for tenancy with AUTH_MODE=builtin');
            }
            return new BuiltinMembershipAuthority($slug, $store, $store, null, $env->DB_GATEWAY_TENANT_SCHEMA_NAME ?? 'tenant');
        }
        throw new \RuntimeException("Unknown AUTH_MODE '$mode' for tenancy");
    }
}
