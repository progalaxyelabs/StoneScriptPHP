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
        $this->authServiceUrl = $options['auth_service_url'] ?? $env->AUTH_SERVICE_URL;

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
        $this->signingIssuer = $options['signing_issuer']
            ?? $env->JWT_ISSUER;

        // Hooks
        $this->hooks = [
            'before_register' => $options['before_register'] ?? null,
            'after_register' => $options['after_register'] ?? null,
            'after_login' => $options['after_login'] ?? null,
            'after_select_tenant' => $options['after_select_tenant'] ?? null,
            'after_password_reset' => $options['after_password_reset'] ?? null,
            'after_accept_invite' => $options['after_accept_invite'] ?? null,
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
            'invite' => $options['invite'] ?? true,
            'accept_invite' => $options['accept_invite'] ?? true,
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
