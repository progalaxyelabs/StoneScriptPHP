<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tenancy\Provisioning;

use StoneScriptPHP\Env;

/**
 * Compatibility switch and authority selection for tenancy v2.
 *
 * TENANCY_V2 (default false) keeps the 14.x provisioning flow on a platform that upgraded to 15.0.0
 * until it is switched over; platforms switch one at a time. When enabled, AUTH_MODE picks the
 * MembershipAuthority: external|hybrid use the auth service v2 endpoints, builtin uses the platform
 * main database.
 */
final class TenancyMode
{
    private function __construct()
    {
    }

    public static function v2Enabled(?Env $env = null): bool
    {
        return ($env ?? Env::get_instance())->TENANCY_V2 === true;
    }

    /**
     * @param callable|null $transport AuthV2Client transport override (tests).
     * @throws \RuntimeException when the selected mode is missing configuration (never guesses a default)
     */
    public static function authority(Env $env, DatabaseMainTenantStore $store, ?callable $transport = null): MembershipAuthority
    {
        $mode = strtolower($env->AUTH_MODE);
        if ($mode === 'external' || $mode === 'hybrid') {
            if ($env->AUTH_SERVICE_URL === '') {
                throw new \RuntimeException('AUTH_SERVICE_URL is required for tenancy v2 with AUTH_MODE=' . $mode);
            }
            return new ExternalMembershipAuthority(new AuthV2Client(
                $env->AUTH_SERVICE_URL,
                $env->AUTH_V2_CLIENT_ID,
                $env->AUTH_V2_CLIENT_SECRET,
                $transport,
            ), $store);
        }
        if ($mode === 'builtin') {
            $slug = $env->DB_GATEWAY_PLATFORM ?? '';
            if ($slug === '') {
                throw new \RuntimeException('DB_GATEWAY_PLATFORM (the platform slug) is required for tenancy v2 with AUTH_MODE=builtin');
            }
            return new BuiltinMembershipAuthority($slug, $store, $store, null, $env->DB_GATEWAY_TENANT_SCHEMA_NAME ?? 'tenant');
        }
        throw new \RuntimeException("Unknown AUTH_MODE '$mode' for tenancy v2");
    }
}
