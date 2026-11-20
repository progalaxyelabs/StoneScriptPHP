<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Routes;

use StoneScriptPHP\ApiResponse;

/**
 * GET {prefix}/memberships (PROTECTED)
 *
 * Proxies membership listing to the auth service.
 * Platform code is automatically injected by the client.
 * Requires Authorization header forwarding.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Routes
 */
class MembershipsRoute extends BaseExternalAuthRoute
{
    /**
     * {@inheritdoc}
     */
    public function validation_rules(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function process(): ApiResponse
    {
        // SECURITY: see PlatformCodeGuard's class docblock — the Bearer token
        // was already signature/exp/purpose-verified by AccessTokenMiddleware,
        // but never checked against this platform's own configured code.
        if ($denial = $this->guardPlatformCode(auth()?->platform_code)) {
            return $denial;
        }

        // Current tenancy flow (LEGACY_TENANCY=false): the authority's list (auth or the local main database):
        // active memberships of provisioned tenants of THIS platform; slug may be null.
        if (!$this->legacyTenancy()) {
            $identityId = (string) (auth()?->user_id ?? '');
            try {
                $rows = $this->membershipAuthority()->listMemberships($identityId, $this->getBearerToken());
            } catch (\Throwable $e) {
                log_error('MembershipsRoute: membership list failed: ' . \StoneScriptPHP\Persistence\LogSanitizer::describe($e));
                return res_error('Authentication service unavailable', 502);
            }
            $memberships = array_map(static fn (array $r): array => [
                'id' => $r['membership_id'],
                'tenant_id' => $r['tenant_id'],
                'tenant_name' => $r['tenant_name'],
                'tenant_slug' => $r['tenant_slug'],
                'tenant_db_name' => $r['tenant_db_name'],
                'is_tenant_owner' => $r['is_tenant_owner'],
                'status' => 'active',
            ], $rows);
            return res_ok(['success' => true, 'memberships' => $memberships]);
        }

        return $this->proxyCall(
            fn() => $this->client->getMemberships($this->getBearerToken())
        );
    }
}
