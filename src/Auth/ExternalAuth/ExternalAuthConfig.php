<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth;

/**
 * ExternalAuth Configuration
 *
 * Value object that validates and normalizes the options array
 * passed to ExternalAuthRoutes::register().
 *
 * @package StoneScriptPHP\Auth\ExternalAuth
 */
class ExternalAuthConfig
{
    /** @var string URL prefix for all auth routes (canonical) */
    public readonly string $prefix;

    /**
     * @var bool When true (default), also register all routes under the legacy `/auth` prefix
     * for backward compatibility. Set to false once all clients have migrated to `/api/auth`.
     * Automatically skipped when prefix is already `/auth` (no double-registration).
     */
    public readonly bool $legacyCompat;

    /** @var string Auth service base URL (used for JWKS fetch — container URL in Docker) */
    public readonly string $authServiceUrl;

    /**
     * @var string JWT 'iss' claim value (public URL the auth server stamps in tokens).
     * In Docker: AUTH_SERVICE_URL = container URL (JWKS fetch), AUTH_ISSUER = public URL (JWT iss).
     * MUST be set explicitly — the old fallback to AUTH_SERVICE_URL is removed because it
     * silently produces wrong issuer values in Docker (container hostname ≠ JWT 'iss' claim).
     */
    public readonly string $authIssuer;

    /** @var string Platform code sent with requests */
    public readonly string $platformCode;

    /** @var string Registration mode: 'tenant' (default) or 'identity' */
    public readonly string $registrationMode;

    /** @var array Extra fields to accept during registration */
    public readonly array $extraFields;

    /** @var array Extra validation rules merged into RegisterRoute */
    public readonly array $extraValidation;

    /** @var string|null Platform secret for server-to-server auth (X-Platform-Secret) */
    public readonly ?string $platformSecret;

    /**
     * @var string JWKS endpoint used by the token exchange route to validate
     * inbound identity tokens. Defaults to `{authServiceUrl}/api/auth/jwks`
     * (matches the auth service's published JWKS path). Override with `jwks_url`.
     */
    public readonly string $jwksUrl;

    /** @var int TTL (seconds) for platform tokens issued by the exchange route. */
    public readonly int $exchangeTtl;

    /**
     * @var string Private key path used to SIGN platform tokens in the exchange
     * route. Defaults to the platform's existing JWT_PRIVATE_KEY_PATH so the
     * issued token is signed with the same key JwtAuthMiddleware validates —
     * no second keypair to keep in sync. Override with `signing_private_key_path`.
     */
    public readonly string $signingPrivateKeyPath;

    /** @var string|null Passphrase for the signing private key (defaults to JWT_PRIVATE_KEY_PASSPHRASE). */
    public readonly ?string $signingPrivateKeyPassphrase;

    /** @var string Issuer (`iss`) stamped on platform tokens (defaults to JWT_ISSUER). */
    public readonly string $signingIssuer;

    /**
     * @var callable|null Platform-specific tenants resolver (card model — §4/§6).
     *
     * Signature: fn(array $passportClaims): array
     *   Returns a list of tenant objects the identity has access to on this platform.
     *   Each element: [ 'id' => string, 'name' => string, ... ]
     *
     * Used by the exchange route to:
     *   1. Verify the requested tenant_id is in the identity's membership set.
     *   2. Build the available_tenants[] array in the §6 response.
     *   3. Power the /me (session) endpoint's available_tenants field.
     *
     * When null, the requested tenant_id is trusted without membership verification
     * (suitable only for T1 platforms or during migration).
     */
    public readonly mixed $tenantsResolver;

    /**
     * @var TenantRouteProviderInterface Registers the tenant-specific route
     * subset (select-tenant, provision-tenant, invite, memberships,
     * check-tenant-slug, accept-invite) and computes their public/protected
     * path lists. Defaults to `DefaultTenantRouteProvider` — the framework's
     * built-in behavior, unchanged from pre-Phase-1 StoneScriptPHP. Override
     * with `tenant_route_provider` in options (Phase 2 extraction seam — see
     * TenantRouteProviderInterface's docblock).
     */
    public readonly TenantRouteProviderInterface $tenantRouteProvider;

    /** @var array<string, callable|null> Lifecycle hooks */
    public readonly array $hooks;

    /** @var array<string, bool> Feature toggles */
    private array $features;

    /**
     * @param array $options Raw options from ExternalAuthRoutes::register()
     */
    public function __construct(array $options = [])
    {
        $env = \StoneScriptPHP\Env::get_instance();

        // AUTH-SPEC §S1: canonical prefix is /api/auth.
        // Legacy default was /auth — kept as compat routes when legacyCompat=true.
        $this->prefix = rtrim($options['prefix'] ?? '/api/auth', '/');
        $this->legacyCompat = $options['legacy_compat'] ?? true;

        // NO localhost default. Callers normally go through Application::buildAuthRouteOptions(),
        // which already fails loud on a missing AUTH_SERVICE_URL — this check is defense-in-depth
        // for direct callers (e.g. tests, or a platform calling ExternalAuthRoutes::register()
        // without going through Application::run()). The framework used to leave this to
        // Env::$AUTH_SERVICE_URL's own hardcoded 'http://localhost:3139' default, which silently
        // routed every JWKS fetch and auth proxy call at loopback for any platform whose config
        // didn't explicitly override it.
        $resolvedAuthServiceUrl = $options['auth_service_url'] ?? (
            !empty($env->AUTH_SERVICE_URL) ? $env->AUTH_SERVICE_URL : null
        );
        if ($resolvedAuthServiceUrl === null || trim($resolvedAuthServiceUrl) === '') {
            throw new \RuntimeException(
                "auth_service_url is required for ExternalAuth but is not set or empty. "
                . "Pass 'auth_service_url' in options, or set the AUTH_SERVICE_URL env var to the "
                . "auth service's network address (e.g. http://auth:3139 inside Docker). "
                . "There is no localhost default."
            );
        }
        $this->authServiceUrl = $resolvedAuthServiceUrl;

        // AUTH_ISSUER MUST be set explicitly — silently falling back to AUTH_SERVICE_URL
        // was wrong in Docker: the service URL is the container-internal address used only
        // for JWKS fetch, but JWTs carry the public hostname in 'iss'. Reusing the service
        // URL as the issuer caused a guaranteed mismatch → every authed API call 401s.
        // Verify the correct value by decoding a real JWT and reading its 'iss' claim.
        $resolvedIssuer = $options['auth_issuer'] ?? (
            !empty($env->AUTH_ISSUER) ? $env->AUTH_ISSUER : null
        );
        if ($resolvedIssuer === null || trim($resolvedIssuer) === '') {
            throw new \RuntimeException(
                "AUTH_ISSUER is required for ExternalAuth but is not set or empty. "
                . "Set AUTH_ISSUER to the exact 'iss' claim value stamped in tokens by the auth service "
                . "(decode a real JWT and read its 'iss' field). AUTH_SERVICE_URL is the NETWORK address "
                . "for JWKS fetch — it is NOT the issuer."
            );
        }
        $this->authIssuer = $resolvedIssuer;
        $this->platformCode = $options['platform_code'] ?? ($env->PLATFORM_CODE ?? '');

        // Registration config
        $registration = $options['registration'] ?? [];
        $this->registrationMode = $registration['mode'] ?? 'tenant';
        $this->extraFields = $registration['extra_fields'] ?? [];
        $this->extraValidation = $registration['extra_validation'] ?? [];

        if (!in_array($this->registrationMode, ['tenant', 'identity', 'none'], true)) {
            throw new \InvalidArgumentException(
                "Invalid registration mode '{$this->registrationMode}'. Must be 'tenant', 'identity', or 'none'."
            );
        }

        // Client secret for server-to-server calls to auth service (e.g., create-membership)
        // Only needed when AUTH_MODE=external and provision_tenant is enabled
        $this->platformSecret = $options['platform_secret']
            ?? ($env->EXTERNAL_AUTH_CLIENT_SECRET ?? null);

        // Token exchange config. JWKS for inbound identity-token validation, and
        // signing config for the issued platform token. Signing defaults are
        // derived from the platform's EXISTING JWT config so there is no second
        // keypair to keep in sync with JwtAuthMiddleware.
        $this->jwksUrl = $options['jwks_url']
            ?? (rtrim($this->authServiceUrl, '/') . '/api/auth/jwks');
        $this->exchangeTtl = (int) ($options['exchange_ttl'] ?? 3600);
        $this->signingPrivateKeyPath = $options['signing_private_key_path']
            ?? $env->JWT_PRIVATE_KEY_PATH;
        $this->signingPrivateKeyPassphrase = $options['signing_private_key_passphrase']
            ?? ($env->JWT_PRIVATE_KEY_PASSPHRASE ?? null);

        // JWT_ISSUER MUST be set explicitly — it is stamped as 'iss' on every card this platform
        // mints via POST /api/auth/exchange. If unset, TokenExchangeService::mintToken() will
        // throw at card-signing time (it already validates that issuer is non-empty).
        // See also: RsaJwtHandler::generateToken() throws on empty JWT_ISSUER.
        $this->signingIssuer = (string) ($options['signing_issuer'] ?? ($env->JWT_ISSUER ?? ''));

        // Tenants resolver for the card model (framework-spec.md §6).
        // fn(array $passportClaims): array — returns tenant objects for the identity.
        $this->tenantsResolver = $options['tenants_resolver'] ?? null;

        // Phase 1 plugin seam (see TenantRouteProviderInterface docblock). Defaults to
        // the framework's built-in provider — identical behavior to before this seam existed.
        $providedTenantRouteProvider = $options['tenant_route_provider'] ?? null;
        $this->tenantRouteProvider = $providedTenantRouteProvider instanceof TenantRouteProviderInterface
            ? $providedTenantRouteProvider
            : new DefaultTenantRouteProvider();

        // Hooks
        $this->hooks = [
            'before_register' => $options['before_register'] ?? null,
            'after_register' => $options['after_register'] ?? null,
            'after_login' => $options['after_login'] ?? null,
            'after_select_tenant' => $options['after_select_tenant'] ?? null,
            'after_password_reset' => $options['after_password_reset'] ?? null,
            // 'after_accept_invite' REMOVED 2026-07-21 — AcceptInviteRoute
            // (the only caller) was deleted along with invite/accept_invite
            // (see DefaultTenantRouteProvider's docblock). Invitations are
            // now generated per-platform via `php stone generate
            // invitations`, which does not use this hook mechanism.
            'before_provision' => $options['before_provision'] ?? null,
            'after_provision' => $options['after_provision'] ?? null,
        ];

        // Feature toggles (defaults)
        $this->features = [
            'register' => $options['register'] ?? true,
            'login' => $options['login'] ?? true,
            'logout' => $options['logout'] ?? true,
            'refresh' => $options['refresh'] ?? true,
            'select_tenant' => $options['select_tenant'] ?? true,
            'memberships' => $options['memberships'] ?? true,
            'check_slug' => $options['check_slug'] ?? true,
            'onboarding_status' => $options['onboarding_status'] ?? true,
            'password_reset' => $options['password_reset'] ?? true,
            'change_password' => $options['change_password'] ?? true,
            // 'invite' / 'accept_invite' REMOVED 2026-07-21 — the auth-service
            // endpoints they proxied to (`POST /api/auth/invite`,
            // `POST /api/auth/accept-invite`) no longer exist (see
            // DefaultTenantRouteProvider's docblock and
            // DESIGN-invitation-system.md §0/§1.3/§4.3 in this repo).
            // Invitations are now an opt-in, platform-generated feature via
            // `php stone generate invitations`, not a framework default.
            'oauth' => $options['oauth'] ?? false,
            'provision_tenant' => $options['provision_tenant'] ?? ($options['oauth'] ?? false),
            'profile' => $options['profile'] ?? true,
            'health' => $options['health'] ?? false,
            'verify_email' => $options['verify_email'] ?? true,
            'resend_code' => $options['resend_code'] ?? true,
            'exchange' => $options['exchange'] ?? true,
        ];
    }

    /**
     * Check if a feature is enabled
     *
     * @param string $feature Feature name
     * @return bool
     */
    public function isEnabled(string $feature): bool
    {
        return $this->features[$feature] ?? false;
    }

    /**
     * Get all enabled features
     *
     * @return array<string, bool>
     */
    public function getFeatures(): array
    {
        return $this->features;
    }
}
