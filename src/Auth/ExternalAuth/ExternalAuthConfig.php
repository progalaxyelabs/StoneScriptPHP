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
    /** @var string URL prefix for all auth routes */
    public readonly string $prefix;

    /** @var string Auth service base URL (used for JWKS fetch — container URL in Docker) */
    public readonly string $authServiceUrl;

    /**
     * @var string JWT 'iss' claim value (public URL the auth server stamps in tokens).
     * In Docker: AUTH_SERVICE_URL = container URL (JWKS fetch), AUTH_ISSUER = public URL (JWT iss).
     * Falls back to authServiceUrl when not set (same-host / local dev deployments).
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

        $this->prefix = rtrim($options['prefix'] ?? '/auth', '/');
        $this->authServiceUrl = $options['auth_service_url'] ?? $env->AUTH_SERVICE_URL;
        $this->authIssuer = $options['auth_issuer'] ?? (
            !empty($env->AUTH_ISSUER) ? $env->AUTH_ISSUER : $this->authServiceUrl
        );
        $this->platformCode = $options['platform_code'] ?? ($env->PLATFORM_CODE ?? '');

        // Registration config
        $registration = $options['registration'] ?? [];
        $this->registrationMode = $registration['mode'] ?? 'tenant';
        $this->extraFields = $registration['extra_fields'] ?? [];
        $this->extraValidation = $registration['extra_validation'] ?? [];

        if (!in_array($this->registrationMode, ['tenant', 'identity'], true)) {
            throw new \InvalidArgumentException(
                "Invalid registration mode '{$this->registrationMode}'. Must be 'tenant' or 'identity'."
            );
        }

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
