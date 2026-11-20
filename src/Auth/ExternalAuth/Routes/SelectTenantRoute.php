<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Routes;

use StoneScriptPHP\ApiResponse;

/**
 * POST {prefix}/select-tenant (PROTECTED)
 *
 * 14.x flow: proxies tenant selection to the auth service (selection_token + tenant_id).
 *
 * Tenancy v2 (TENANCY_V2=true): the caller's identity token is enough. The membership is verified
 * through the MembershipAuthority (auth v2 `memberships/verify`, or the local main database), the
 * user's roles are read STRICTLY from the TENANT database (an unknown role grants nothing: no known role is a
 * 403 and no token; a failed read is a 502), and a platform API token carrying ALL those roles (roles[], no
 * single or primary role) is minted; what the roles allow is the union of their privileges.
 * selection_token is not needed.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Routes
 */
class SelectTenantRoute extends BaseExternalAuthRoute
{
    public string $selection_token = '';
    public string $tenant_id = '';

    /**
     * {@inheritdoc}
     */
    public function validation_rules(): array
    {
        return [
            'selection_token' => $this->tenancyV2Enabled() ? 'optional|string' : 'required|string',
            'tenant_id' => 'required|string',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function process(): ApiResponse
    {
        // SECURITY: AccessTokenMiddleware already verified the Authorization
        // Bearer token's signature/exp/purpose (route access=authentication)
        // and populated AuthContext — but never checked platform_code. See
        // PlatformCodeGuard's class docblock.
        if ($denial = $this->guardPlatformCode(auth()?->platform_code)) {
            return $denial;
        }

        if ($this->tenancyV2Enabled()) {
            return $this->processTenancyV2();
        }

        $input = [
            'selection_token' => $this->selection_token,
            'tenant_id' => $this->tenant_id,
        ];

        return $this->proxyCall(
            fn() => $this->client->selectTenant(
                $this->selection_token,
                $this->tenant_id,
                $this->getBearerToken()
            ),
            'after_select_tenant',
            $input
        );
    }

    private function processTenancyV2(): ApiResponse
    {
        $user = auth();
        $identityId = (string) ($user?->user_id ?? '');
        if ($identityId === '') {
            return res_error('Authorization required', 401);
        }

        try {
            $membership = $this->membershipAuthority()->verifyMembership($identityId, $this->getBearerToken(), $this->tenant_id);
        } catch (\Throwable $e) {
            log_error('SelectTenantRoute: v2 membership check failed: ' . \StoneScriptPHP\Persistence\LogSanitizer::describe($e));
            return res_error('Authentication service unavailable', 502);
        }
        if ($membership === null) {
            return new ApiResponse('error', 'No active membership for this tenant', ['error' => 'tenant_access_denied'], 403);
        }

        return $this->tenantSessionResponse(
            $identityId,
            $membership,
            ['email' => $user->email ?? null, 'display_name' => $user->display_name ?? null],
            'Tenant selected'
        );
    }
}
