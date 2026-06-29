<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Routes;

use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthServiceClient;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthConfig;
use StoneScriptPHP\Auth\TokenExchangeService;
use StoneScriptPHP\Auth\TokenExchangeException;

/**
 * POST {prefix}/exchange  (PUBLIC — see note on auth)
 *
 * Exchanges a passport (identity JWT, issued by the central auth service) plus a
 * chosen tenant for a **card token** (platform JWT carrying identity_id, tenant_id,
 * and a single active role_id). Implements §4 of the Tenancy & Identity Model.
 *
 * ## Passport / Card model (TENANCY-IDENTITY-MODEL §1-§4)
 *
 * - **Passport** = identity JWT issued by the auth service. Tenant-less. Proves *who
 *   you are* across all platforms.
 * - **Card** = platform JWT issued HERE. Carries identity_id + tenant_id + single
 *   active role_id. Authorises *all tenant-scoped transactions* on this platform.
 *
 * ## Why this route is PUBLIC (excluded from JwtAuthMiddleware)
 * The inbound Authorization token is a *passport* signed by the auth service — NOT a
 * platform card. JwtAuthMiddleware validates platform cards (different issuer/key) and
 * would reject it. This route validates the passport itself via JWKS.
 *
 * ## Flow
 *   1. Extract passport from Authorization: Bearer header
 *   2. Validate against auth service JWKS (TokenExchangeService::validateIdentityToken)
 *   3. Read tenant_id (and optional role_id) from the request **body** — the passport is
 *      always tenant-less; the tenant is the user's choice at entry time.
 *   4. Resolve available_tenants via the injected tenants_resolver (platform-specific).
 *      Verify the requested tenant_id is in the available set.
 *   5. Resolve available_roles in that tenant via the injected roles_resolver.
 *      Verify access (non-empty roles list = membership exists).
 *   6. Pick active_role_id: use role_id from body if valid, else first in available_roles.
 *   7. Sign a card (TokenExchangeService::exchangeCard) using the platform's JWT keypair.
 *   8. Return the card envelope (§6 session contract).
 *
 * ## Switching tenant or role
 * Re-call this endpoint with the new tenant_id / role_id — that is the canonical
 * "switch" operation (TENANCY-IDENTITY-MODEL §4.5, §4.6).
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Routes
 */
class ExchangeRoute extends BaseExternalAuthRoute
{
    // ── Request body fields (auto-populated by the framework) ────────────────

    /**
     * The tenant the identity wants to enter (required).
     * Verified against available_tenants before minting the card.
     */
    public string $tenant_id = '';

    /**
     * Optional active role hint. When supplied and the identity holds that role
     * in the requested tenant, it becomes the card's active role. Otherwise the
     * first role returned by roles_resolver is used.
     */
    public string $role_id = '';

    // ── Injected callables ────────────────────────────────────────────────────

    /**
     * Platform-specific tenants resolver.
     *
     * Signature: fn(array $passportClaims): array
     *   Returns a list of tenant objects the identity has access to on this platform.
     *   Each element: [ 'id' => string, 'name' => string, ... ]
     *
     * When null, the tenant_id from the request body is trusted as-is (no verification
     * that the identity actually belongs to that tenant). Use only on T1 platforms where
     * the concept of tenant selection does not apply.
     *
     * @var callable|null
     */
    protected $tenantsResolver;

    /**
     * Platform-specific roles resolver.
     *
     * Signature: fn(array $claimsWithTenant): array
     *   $claimsWithTenant includes all passport claims + 'tenant_id' from the request body.
     *   Returns a list of role strings the identity holds in that tenant (e.g. ['owner']).
     *
     * A missing resolver is a configuration error — never guess roles.
     *
     * @var callable|null
     */
    protected $rolesResolver;

    public function __construct(
        ExternalAuthServiceClient $client,
        array $hooks,
        ExternalAuthConfig $config,
        ?callable $rolesResolver = null,
        ?callable $tenantsResolver = null
    ) {
        parent::__construct($client, $hooks, $config);
        $this->rolesResolver = $rolesResolver;
        $this->tenantsResolver = $tenantsResolver;
    }

    /**
     * {@inheritdoc}
     *
     * tenant_id is required in the body — the passport is always tenant-less.
     * role_id is optional; defaults to the first role in the identity's membership.
     */
    public function validation_rules(): array
    {
        return [
            'tenant_id' => 'required|string|max:64',
            'role_id'   => 'optional|string|max:100',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function process(): ApiResponse
    {
        // 1. Extract passport from Authorization header.
        $passportToken = $this->extractIdentityToken();
        if ($passportToken === null || $passportToken === '') {
            return new ApiResponse(
                'error',
                'Authorization header with Bearer passport token required',
                ['error' => 'invalid_identity_token'],
                401
            );
        }

        // 2. Validate the passport against the auth service JWKS.
        try {
            $passportClaims = $this->validateIdentity($passportToken);
        } catch (TokenExchangeException $e) {
            log_error('ExchangeRoute: passport validation failed: ' . $e->getMessage());
            return new ApiResponse(
                'error',
                'Invalid or expired passport',
                ['error' => 'invalid_identity_token'],
                401
            );
        }

        $identityId = $passportClaims['identity_id'] ?? $passportClaims['sub'] ?? null;
        if (!$identityId) {
            return new ApiResponse(
                'error',
                'Passport missing identity_id claim',
                ['error' => 'invalid_identity_token'],
                401
            );
        }

        // 3. Read tenant_id from the request body (not from the token — passports are tenant-less).
        $requestedTenantId = $this->tenant_id;
        $requestedRoleId   = $this->role_id !== '' ? $this->role_id : null;

        // 4. Resolve and verify available tenants.
        $availableTenants = [];
        $activeTenant     = null;

        if ($this->tenantsResolver !== null) {
            try {
                $availableTenants = ($this->tenantsResolver)($passportClaims);
            } catch (\Throwable $e) {
                log_error('ExchangeRoute: tenants_resolver threw: ' . $e->getMessage());
                return res_error('Failed to resolve tenant memberships', 500);
            }

            if (!is_array($availableTenants)) {
                log_error('ExchangeRoute: tenants_resolver must return an array, got ' . gettype($availableTenants));
                return res_error('Failed to resolve tenant memberships', 500);
            }

            // Verify the requested tenant is in the available set.
            $tenantIds = array_column($availableTenants, 'id');
            if (!in_array($requestedTenantId, $tenantIds, true)) {
                return new ApiResponse(
                    'error',
                    'Identity does not have access to the requested tenant',
                    ['error' => 'tenant_access_denied'],
                    403
                );
            }

            // Find the active tenant object.
            foreach ($availableTenants as $t) {
                if (($t['id'] ?? null) === $requestedTenantId) {
                    $activeTenant = $t;
                    break;
                }
            }
        } else {
            // No tenants_resolver — trust the requested tenant_id (T1 or unconfigured).
            $activeTenant = ['id' => $requestedTenantId];
        }

        // 5. Resolve roles. A missing resolver is a configuration error — never guess.
        if ($this->rolesResolver === null) {
            log_error('ExchangeRoute: no roles_resolver configured — cannot issue card token');
            return res_error(
                'Token exchange is not configured on this platform (missing roles_resolver)',
                501
            );
        }

        // Merge tenant_id into claims so roles_resolver can use it.
        $claimsWithTenant = array_merge($passportClaims, ['tenant_id' => $requestedTenantId]);

        try {
            $availableRoles = ($this->rolesResolver)($claimsWithTenant);
        } catch (\Throwable $e) {
            log_error('ExchangeRoute: roles_resolver threw: ' . $e->getMessage());
            return res_error('Failed to resolve roles', 500);
        }

        if (!is_array($availableRoles)) {
            log_error('ExchangeRoute: roles_resolver must return an array, got ' . gettype($availableRoles));
            return res_error('Failed to resolve roles', 500);
        }

        // Normalize to a flat list of strings.
        $availableRoles = array_values(array_map('strval', $availableRoles));

        if (empty($availableRoles)) {
            return new ApiResponse(
                'error',
                'Identity has no roles in this tenant',
                ['error' => 'no_roles_in_tenant'],
                403
            );
        }

        // 6. Determine active_role_id.
        $activeRoleId = $requestedRoleId;
        if ($activeRoleId === null || !in_array($activeRoleId, $availableRoles, true)) {
            // Default to first role (owner typically comes first).
            $activeRoleId = $availableRoles[0];
        }

        // 7. Sign the card — a platform JWT carrying identity_id + tenant_id + single role_id.
        try {
            $cardToken = $this->signCard($claimsWithTenant, $activeRoleId);
        } catch (TokenExchangeException $e) {
            log_error('ExchangeRoute: card signing failed: ' . $e->getMessage());
            return res_error('Failed to issue card token', 500);
        }

        // 8. Return the §6 session contract.
        return res_ok([
            'access_token'       => $cardToken,
            'token_type'         => 'Bearer',
            'expires_in'         => $this->config->exchangeTtl,
            'active_tenant'      => $activeTenant,
            'available_tenants'  => $availableTenants,
            'active_role'        => $activeRoleId,
            'available_roles'    => $availableRoles,
        ], 'Card issued');
    }

    // ── Seams (overridable for unit testing without real JWKS / keys) ─────────

    /**
     * Extract the raw passport JWT from the Authorization header.
     */
    protected function extractIdentityToken(): ?string
    {
        return $this->getBearerToken();
    }

    /**
     * Validate the passport against the auth service JWKS.
     *
     * @throws TokenExchangeException on any validation failure
     * @return array Decoded passport claims
     */
    protected function validateIdentity(string $passportToken): array
    {
        $service = new TokenExchangeService();
        return $service->validateIdentityToken(
            $passportToken,
            $this->config->jwksUrl,
            $this->config->authIssuer
        );
    }

    /**
     * Sign a card token from passport claims + chosen tenant + active role.
     *
     * Uses the platform's existing JWT keypair (JWT_PRIVATE_KEY_PATH / JWT_ISSUER)
     * so the card is validated by this platform's JwtAuthMiddleware — no second keypair.
     *
     * @param array  $claimsWithTenant Passport claims + tenant_id merged in
     * @param string $activeRoleId     The single active role to stamp on the card
     * @throws TokenExchangeException on signing failure
     */
    protected function signCard(array $claimsWithTenant, string $activeRoleId): string
    {
        $service = new TokenExchangeService();

        $platformCode = $claimsWithTenant['platform_code'] ?? $this->config->platformCode;

        return $service->exchangeCard($claimsWithTenant, $activeRoleId, [
            'private_key_path'       => $this->config->signingPrivateKeyPath,
            'private_key_passphrase' => $this->config->signingPrivateKeyPassphrase,
            'issuer'                 => $this->config->signingIssuer,
            'ttl'                    => $this->config->exchangeTtl,
            'custom_claims'          => [
                'platform_code' => $platformCode,
            ],
        ]);
    }

    /**
     * @deprecated Use signCard() instead. Kept for backward-compatible subclasses.
     */
    protected function signPlatformToken(array $claims, array $roles): string
    {
        // Derive role_id from the first role in the array (legacy path).
        $roleId = $roles[0] ?? 'member';
        return $this->signCard($claims, $roleId);
    }
}
