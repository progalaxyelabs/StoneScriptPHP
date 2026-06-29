<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Routes;

use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthServiceClient;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthConfig;

/**
 * GET {prefix}/me (PROTECTED)
 *
 * Returns the session context for the currently authenticated card token.
 *
 * ## Card model response (framework-spec.md §6)
 *
 * When the platform has configured `tenants_resolver` + `roles_resolver`, this
 * endpoint returns the full session contract:
 *
 *   {
 *     "identity":          { "id": "...", "email": "...", "display_name": "..." },
 *     "active_tenant":     { "id": "...", "name": "...", ... },
 *     "available_tenants": [ ... ],
 *     "active_role":       "owner",
 *     "available_roles":   ["owner", "manager"],
 *     "display_name":      "...",
 *     "token_type":        "card"
 *   }
 *
 * The UI uses this to render tenant/role switchers:
 *   - "Switch business" when available_tenants.length > 1
 *   - "Switch role" when available_roles.length > 1
 *
 * ## Fallback (proxy to auth service)
 *
 * When resolvers are NOT configured (e.g. T1 platforms), this falls back to
 * proxying the card token to the auth service's /me endpoint.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Routes
 */
class ProfileRoute extends BaseExternalAuthRoute
{
    /** @var callable|null fn(array $passportClaims): array[] — platform tenants resolver */
    protected $tenantsResolver;

    /** @var callable|null fn(array $claimsWithTenant): string[] — platform roles resolver */
    protected $rolesResolver;

    public function __construct(
        ExternalAuthServiceClient $client,
        array $hooks,
        ExternalAuthConfig $config,
        ?callable $rolesResolver = null,
        ?callable $tenantsResolver = null
    ) {
        parent::__construct($client, $hooks, $config);
        $this->rolesResolver   = $rolesResolver;
        $this->tenantsResolver = $tenantsResolver;
    }

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
        $user = auth();
        if (!$user) {
            return new ApiResponse('error', 'Unauthorized', ['error' => 'unauthorized'], 401);
        }

        // Card model response when resolvers are available.
        if ($this->tenantsResolver !== null && $this->rolesResolver !== null && $user->tenant_id) {
            return $this->cardModelResponse($user);
        }

        // Fallback: proxy to auth service (T1 platforms or unconfigured).
        return $this->proxyCall(fn() => $this->client->getProfile($this->getBearerToken()));
    }

    /**
     * Build the §6 session contract response from card claims + resolvers.
     */
    private function cardModelResponse(\StoneScriptPHP\Auth\AuthenticatedUser $user): ApiResponse
    {
        // Reconstruct passport-like claims from the authenticated user for resolver calls.
        $claims = [
            'identity_id'  => $user->user_id,
            'sub'          => $user->user_id,
            'email'        => $user->email,
            'display_name' => $user->display_name,
            'platform_code' => $user->platform_code,
        ];

        // Resolve available tenants.
        $availableTenants = [];
        $activeTenant     = null;
        try {
            $availableTenants = ($this->tenantsResolver)($claims);
            foreach ($availableTenants as $t) {
                if (($t['id'] ?? null) === $user->tenant_id) {
                    $activeTenant = $t;
                    break;
                }
            }
        } catch (\Throwable $e) {
            log_error('ProfileRoute: tenants_resolver failed: ' . $e->getMessage());
        }

        if ($activeTenant === null) {
            $activeTenant = ['id' => $user->tenant_id];
        }

        // Resolve available roles in the active tenant.
        $availableRoles = [];
        $activeRole     = $user->role_id ?? $user->user_role;
        try {
            $claimsWithTenant = array_merge($claims, ['tenant_id' => $user->tenant_id]);
            $availableRoles   = array_values(
                array_map('strval', ($this->rolesResolver)($claimsWithTenant))
            );
        } catch (\Throwable $e) {
            log_error('ProfileRoute: roles_resolver failed: ' . $e->getMessage());
            if ($activeRole) {
                $availableRoles = [$activeRole];
            }
        }

        return new ApiResponse('ok', 'Session context', [
            'identity'          => [
                'id'           => $user->user_id,
                'email'        => $user->email,
                'display_name' => $user->display_name,
            ],
            'active_tenant'     => $activeTenant,
            'available_tenants' => $availableTenants,
            'active_role'       => $activeRole,
            'available_roles'   => $availableRoles,
            'display_name'      => $user->display_name,
            'token_type'        => 'card',
        ]);
    }
}
