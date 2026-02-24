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
        $this->platformCode = $platformCode ?? (
            property_exists($env, 'PLATFORM_CODE') ? ($env->PLATFORM_CODE ?? '') : ''
        );
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
        $data['platform'] = $data['platform'] ?? $this->platformCode;
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
        $data['platform'] = $data['platform'] ?? $this->platformCode;
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
            'platform' => $this->platformCode,
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
        return $this->get('/.well-known/jwks.json');
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
            'platform' => $this->platformCode,
        ]);
    }

    /**
     * Confirm password reset with token
     *
     * @param string $token Reset token from email
     * @param string $newPassword New password
     * @return array Auth service response
     * @throws AuthServiceException
     */
    public function confirmPasswordReset(string $token, string $newPassword): array
    {
        return $this->post('/api/auth/reset-password', [
            'token' => $token,
            'new_password' => $newPassword,
        ]);
    }

    /**
     * Accept a membership invitation
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
            'platform' => $this->platformCode,
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
            ? '?platform=' . urlencode($this->platformCode)
            : '';
        return $this->get('/api/auth/memberships' . $query, $this->buildAuthHeader($authToken));
    }

    /**
     * Invite a member to a tenant
     *
     * @param string $email Invitee's email
     * @param string $tenantId Tenant ID
     * @param string $role Role to assign
     * @param string|null $authToken Authorization header value
     * @return array Auth service response
     * @throws AuthServiceException
     */
    public function inviteMember(string $email, string $tenantId, string $role, ?string $authToken = null): array
    {
        return $this->post('/api/auth/invite', [
            'email' => $email,
            'tenant_id' => $tenantId,
            'role' => $role,
            'platform' => $this->platformCode,
        ], $this->buildAuthHeader($authToken));
    }

    /**
     * Update a membership (role or status)
     *
     * @param string $membershipId Membership ID
     * @param array $data Fields to update (role, status)
     * @param string|null $authToken Authorization header value
     * @return array Auth service response
     * @throws AuthServiceException
     */
    public function updateMembership(string $membershipId, array $data, ?string $authToken = null): array
    {
        return $this->put(
            '/api/auth/memberships/' . urlencode($membershipId),
            $data,
            $this->buildAuthHeader($authToken)
        );
    }

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
     * @param string|null $authToken Authorization header value
     * @return array Auth service response
     * @throws AuthServiceException
     */
    public function getProfile(?string $authToken = null): array
    {
        return $this->get('/api/auth/me', $this->buildAuthHeader($authToken));
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
