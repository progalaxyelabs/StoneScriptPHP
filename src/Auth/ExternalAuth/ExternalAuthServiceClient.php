<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth;

use StoneScriptPHP\Auth\Client\AuthServiceClient;
use StoneScriptPHP\Auth\Client\AuthServiceException;

/**
 * HTTP client for the external ProGalaxy Auth Service (Rust/Axum)
 *
 * Extends the framework's AuthServiceClient with methods covering all
 * auth service endpoints. Automatically injects platform_code where needed.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth
 */
class ExternalAuthServiceClient extends AuthServiceClient
{
    protected string $platformCode;

    /**
     * @param string|null $authServiceUrl Auth service URL (defaults from env AUTH_SERVICE_URL)
     * @param string|null $platformCode Platform code (defaults from env PLATFORM_CODE)
     */
    public function __construct(?string $authServiceUrl = null, ?string $platformCode = null)
    {
        parent::__construct($authServiceUrl);

        $env = \StoneScriptPHP\Env::get_instance();
        $this->platformCode = $platformCode ?? ($env->PLATFORM_CODE ?? '');
    }

    // ──────────────────────────────────────────────
    // Public (unauthenticated) endpoints
    // ──────────────────────────────────────────────

    /**
     * Register identity only (no tenant provisioning)
     *
     * @param array $data Registration data (email, password, name, ...)
     * @return array Auth service response
     * @throws AuthServiceException
     */
    public function register(array $data): array
    {
        $data['platform_code'] = $data['platform_code'] ?? $this->platformCode;
        return $this->post('/api/auth/register', $data);
    }

    /**
     * Register identity + tenant + DB provisioning
     *
     * This is the correct endpoint for most platforms. Calling register()
     * instead of registerTenant() is a common bug that skips tenant creation.
     *
     * @param array $data Registration data (email, password, tenant_name, country_code, ...)
     * @return array Auth service response
     * @throws AuthServiceException
     */
    public function registerTenant(array $data): array
    {
        $data['platform_code'] = $data['platform_code'] ?? $this->platformCode;
        return $this->post('/api/auth/register-tenant', $data);
    }

    /**
     * Login with email and password
     *
     * @param string $email
     * @param string $password
     * @param string|null $tenantSlug Optional tenant slug for direct login
     * @return array Auth service response
     * @throws AuthServiceException
     */
    public function login(string $email, string $password, ?string $tenantSlug = null): array
    {
        $data = [
            'email' => $email,
            'password' => $password,
            'platform_code' => $this->platformCode,
        ];

        if ($tenantSlug !== null) {
            $data['tenant_slug'] = $tenantSlug;
        }

        return $this->post('/api/auth/login', $data);
    }

    /**
     * Select a tenant after multi-tenant login
     *
     * @param string $selectionToken Token from login response
     * @param string $tenantId Tenant ID to select
     * @param string|null $authToken Authorization header value
     * @return array Auth service response
     * @throws AuthServiceException
     */
    public function selectTenant(string $selectionToken, string $tenantId, ?string $authToken = null): array
    {
        return $this->post('/api/auth/select-tenant', [
            'selection_token' => $selectionToken,
            'tenant_id' => $tenantId,
        ], $this->buildAuthHeader($authToken));
    }

    /**
     * Refresh an access token
     *
     * @param string $refreshToken The refresh token
     * @return array Auth service response
     * @throws AuthServiceException
     */
    public function refresh(string $refreshToken): array
    {
        return $this->post('/api/auth/refresh', [
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * Logout (invalidate refresh token)
     *
     * @param string $refreshToken The refresh token to invalidate
     * @return array Auth service response
     * @throws AuthServiceException
     */
    public function logout(string $refreshToken): array
    {
        return $this->post('/api/auth/logout', [
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * Get JWKS (JSON Web Key Set) for token verification
     *
     * @return array JWKS response
     * @throws AuthServiceException
     */
    public function getJwks(): array
    {
        return $this->get('/api/auth/jwks');
    }

    /**
     * Check if a tenant slug is available
     *
     * @param string $slug Tenant slug to check
     * @return array Auth service response
     * @throws AuthServiceException
     */
    public function checkTenantSlug(string $slug): array
    {
        return $this->get('/api/auth/check-tenant-slug/' . urlencode($slug));
    }

    /**
     * Get onboarding status for an identity
     *
     * @param string $identityId Identity ID
     * @return array Auth service response
     * @throws AuthServiceException
     */
    public function getOnboardingStatus(string $identityId): array
    {
        return $this->get('/api/auth/onboarding/status?identity_id=' . urlencode($identityId));
    }

    /**
     * Request a password reset email
     *
     * @param string $email User's email
     * @return array Auth service response
     * @throws AuthServiceException
     */
    public function requestPasswordReset(string $email): array
    {
        return $this->post('/api/auth/forgot-password', [
            'email' => $email,
            'platform_code' => $this->platformCode,
        ]);
    }

    /**
     * Confirm password reset with verification code
     *
     * The external auth service expects: email + code + new_password
     *
     * @param string $email User's email
     * @param string $code Reset code from email
     * @param string $newPassword New password
     * @return array Auth service response
     * @throws AuthServiceException
     */
    public function confirmPasswordReset(string $email, string $code, string $newPassword): array
    {
        return $this->post('/api/auth/reset-password', [
            'email' => $email,
            'code' => $code,
            'new_password' => $newPassword,
        ]);
    }

    /**
     * Verify email address with code
     *
     * @param string $email User's email
     * @param string $code Verification code from email
     * @return array Auth service response
     * @throws AuthServiceException
     */
    public function verifyEmail(string $email, string $code): array
    {
        return $this->post('/api/auth/verify-email', [
            'email' => $email,
            'code' => $code,
        ]);
    }

    /**
     * Resend email verification code
     *
     * @param string $email User's email
     * @return array Auth service response
     * @throws AuthServiceException
     */
    public function resendVerificationCode(string $email): array
    {
        return $this->post('/api/auth/resend-code', [
            'email' => $email,
            'platform_code' => $this->platformCode,
        ]);
    }

    /**
     * Accept a membership invitation
     *
     * @deprecated `POST /api/auth/accept-invite` no longer
     *   exists on the auth service — the invitation system moved fully
     *   platform-side. Calling this method will always fail with a 404/connection
     *   error. Use `php stone generate invitations` +
     *   {@see \StoneScriptPHP\Auth\Invitations\InvitationCompletionService}
     *   instead. Kept (not deleted) only for SemVer compatibility with any
     *   external caller that references this method directly.
     *
     * @param string $token Invitation token
     * @param string|null $password Password (for new users)
     * @return array Auth service response
     * @throws AuthServiceException
     */
    public function acceptInvite(string $token, ?string $password = null): array
    {
        $data = ['token' => $token];
        if ($password !== null) {
            $data['password'] = $password;
        }
        return $this->post('/api/auth/accept-invite', $data);
    }

    /**
     * Initiate OAuth flow
     *
     * @param string $provider OAuth provider (google, linkedin, apple)
     * @param string $redirectUri Callback URL
     * @param string|null $tenantSlug Optional tenant slug
     * @return array Auth service response with redirect URL
     * @throws AuthServiceException
     */
    public function initiateOAuth(string $provider, string $redirectUri, ?string $tenantSlug = null): array
    {
        $data = [
            'provider' => $provider,
            'redirect_uri' => $redirectUri,
            'platform_code' => $this->platformCode,
        ];
        if ($tenantSlug !== null) {
            $data['tenant_slug'] = $tenantSlug;
        }
        return $this->post('/api/auth/oauth/initiate', $data);
    }

    /**
     * Handle OAuth callback
     *
     * @param string $provider OAuth provider
     * @param string $code Authorization code
     * @param string $state State parameter for CSRF verification
     * @return array Auth service response with tokens
     * @throws AuthServiceException
     */
    public function oauthCallback(string $provider, string $code, string $state): array
    {
        return $this->post('/api/auth/oauth/callback', [
            'provider' => $provider,
            'code' => $code,
            'state' => $state,
        ]);
    }

    // ──────────────────────────────────────────────
    // Internal (server-to-server) endpoints
    // ──────────────────────────────────────────────

    /**
     * Create a tenant membership (server-to-server, secured by X-Platform-Secret)
     *
     * Does NOT create identities or provision databases — only the membership.
     * Returns JWT tokens with tenant context.
     *
     * `$data` may include `is_tenant_owner` (bool, default false) —
     * set this `true` ONLY when this call is creating a brand-new tenant
     * (e.g. a self-service "create organization" flow), never for an invited
     * member. It cannot be changed after creation — there is no update path.
     * Never include `role`/`roles` — auth does not store them.
     *
     * @param array $data Membership data (identity_id, tenant_id, platform_code, tenant_slug, tenant_db_schema, is_tenant_owner?, ...)
     * @param string $platformSecret X-Platform-Secret header value
     * @return array Auth service response with membership + tokens
     * @throws AuthServiceException
     */
    public function createMembership(array $data, string $platformSecret): array
    {
        $data['platform_code'] = $data['platform_code'] ?? $this->platformCode;
        return $this->post('/api/internal/create-membership', $data, [
            'X-Platform-Secret: ' . $platformSecret,
        ]);
    }

    /**
     * Create a tenant membership as a result of an invitation being
     * accepted.
     *
     * Thin wrapper over {@see createMembership()} that deliberately NEVER
     * sends a `role`/`roles`/`is_tenant_owner` key, even if the caller's
     * `$data` includes one — this is intentional, not an oversight:
     *
     *   - `role`/`roles`: auth no longer stores these at all —
     *     `tenant_memberships.role`/`.roles` were dropped, not just ignored.
     *     Role authority for invite-driven memberships lives 100% in the
     *     platform's own `platform_invitations.role` column.
     *   - `is_tenant_owner`: an invite-accepted membership is never the
     *     tenant's owner by construction (the owner is whoever's
     *     `createMembership()` call *created* the tenant — a structural
     *     fact, never something an invitation confers). This flag is set
     *     once, at creation, and is never editable afterward.
     *
     * Unlike `createMembership()` (still used, unchanged, by
     * `ProvisionTenantRoute` with an explicit structural `role: 'owner'`
     * historically, now `is_tenant_owner: true` — that call is a constant,
     * not invitation data, so it is NOT affected by this stripping), this
     * entry point exists specifically so a future reviewer sees the
     * omission is deliberate.
     *
     * @param array $data Membership data — identity_id, tenant_id,
     *   platform_code, tenant_db_schema, email, etc. Any `role`/`roles`/
     *   `is_tenant_owner` key present is stripped before the call.
     * @param string $platformSecret X-Platform-Secret header value
     * @return array Auth service response with membership + tokens
     * @throws AuthServiceException
     */
    public function createMembershipForInvite(array $data, string $platformSecret): array
    {
        unset($data['role'], $data['roles'], $data['is_tenant_owner']);
        return $this->createMembership($data, $platformSecret);
    }

    /**
     * Change a membership's status (server-to-server, secured by
     * X-Platform-Secret).
     *
     * Auth-side enforcement (not this method's job to duplicate): the
     * tenant's owner-membership (`is_tenant_owner = true`) can never be
     * suspended through this endpoint — auth returns
     * `{error: "cannot_suspend_tenant_owner"}` rather than a 5xx, so callers
     * should inspect the response body, not just catch
     * {@see AuthServiceException}. Deciding WHO is allowed to change WHOSE
     * status is entirely the calling platform's own responsibility (its own
     * RBAC, before this method is ever invoked) — auth makes no
     * authorization decision here beyond the owner-protection invariant
     * above. This is deliberately
     * narrow (no `acting_identity_id` parameter, no admin/member tier).
     *
     * @param string $membershipId Membership ID (UUID)
     * @param string $status One of 'active' | 'suspended'
     * @param string $platformSecret X-Platform-Secret header value
     * @return array{id?: string, status?: string, updated_at?: string, error?: string}
     * @throws AuthServiceException
     */
    public function setMembershipStatus(string $membershipId, string $status, string $platformSecret): array
    {
        return $this->put('/api/internal/membership-status', [
            'membership_id' => $membershipId,
            'status'        => $status,
        ], [
            'X-Platform-Secret: ' . $platformSecret,
        ]);
    }

    // ──────────────────────────────────────────────
    // Protected (authenticated) endpoints
    // ──────────────────────────────────────────────

    /**
     * Get memberships for the authenticated user
     *
     * @param string|null $authToken Authorization header value
     * @return array Auth service response
     * @throws AuthServiceException
     */
    public function getMemberships(?string $authToken = null): array
    {
        $query = !empty($this->platformCode)
            ? '?platform_code=' . urlencode($this->platformCode)
            : '';
        return $this->get('/api/auth/memberships' . $query, $this->buildAuthHeader($authToken));
    }

    // inviteMember() and updateMembership() were REMOVED. Both called auth
    // endpoints that no longer exist
    // — `POST /api/auth/invite` was removed (invitations are
    // platform-owned), and
    // `PUT /api/auth/memberships/:id` was removed as part of the same
    // auth role-ownership change (it was the one user-curlable membership mutation, authenticated by a
    // bare passport with no X-Platform-Secret).
    // Neither was merely deprecated-but-kept like acceptInvite() below,
    // because unlike that method, nothing about these two was ever
    // SemVer-relied-upon in a way worth preserving a permanently-failing
    // stub for. Use `php stone generate invitations` for invite creation,
    // and {@see setMembershipStatus()} for the one membership mutation that
    // still exists.

    /**
     * Change password for authenticated user
     *
     * @param string $currentPassword Current password
     * @param string $newPassword New password
     * @param string|null $authToken Authorization header value
     * @return array Auth service response
     * @throws AuthServiceException
     */
    public function changePassword(string $currentPassword, string $newPassword, ?string $authToken = null): array
    {
        return $this->post('/api/auth/change-password', [
            'current_password' => $currentPassword,
            'new_password' => $newPassword,
        ], $this->buildAuthHeader($authToken));
    }

    /**
     * Get user profile (proxied from auth service)
     *
     * The auth service does not have a dedicated /me endpoint.
     * Uses /api/auth/memberships which returns the user's memberships
     * (tenant info + status only — no `role`) for the current
     * platform. Callers needing the identity's role in a tenant must resolve
     * it from their OWN roles_resolver, never from this response.
     *
     * @param string|null $authToken Raw JWT token (without "Bearer " prefix)
     * @return array Auth service response
     * @throws AuthServiceException
     */
    public function getProfile(?string $authToken = null): array
    {
        $query = !empty($this->platformCode)
            ? '?platform_code=' . urlencode($this->platformCode)
            : '';
        return $this->get('/api/auth/memberships' . $query, $this->buildAuthHeader($authToken));
    }

    /**
     * Health check for the auth service
     *
     * @return array Health check response
     * @throws AuthServiceException
     */
    public function healthCheck(): array
    {
        return $this->get('/health');
    }
}
