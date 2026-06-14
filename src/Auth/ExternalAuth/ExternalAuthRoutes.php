<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth;

use StoneScriptPHP\Routing\Router;
use StoneScriptPHP\Auth\ExternalAuth\Routes\RegisterRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\LoginRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\LogoutRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\RefreshTokenRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\SelectTenantRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\ForgotPasswordRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\ResetPasswordRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\ChangePasswordRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\AcceptInviteRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\InviteMemberRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\UpdateMembershipRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\MembershipsRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\CheckTenantSlugRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\OnboardingStatusRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\ProfileRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\OAuthInitiateRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\OAuthCallbackRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\AuthHealthRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\VerifyEmailRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\ResendVerificationCodeRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\ProvisionTenantRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\ExchangeRoute;

/**
 * ExternalAuth Route Registration
 *
 * Registers framework-level proxy routes for external auth services.
 * Replaces 18+ duplicate proxy routes that each platform previously maintained.
 *
 * AUTH-SPEC §S1: canonical prefix is `/api/auth`. Default changed from `/auth` to
 * `/api/auth` in v3.26.0. When `legacy_compat` is true (the default), all routes
 * are ALSO registered under the old `/auth` prefix so existing deployments keep
 * working during the migration window. Set `'legacy_compat' => false` once all
 * Angular clients have been updated to use `/api/auth`.
 *
 * Usage in your index.php:
 *
 *   ExternalAuthRoutes::register($router, [
 *       'prefix' => '/api/auth',      // canonical — AUTH-SPEC §S1
 *       'legacy_compat' => true,      // also answer /auth/* during transition (default: true)
 *       'registration' => ['mode' => 'tenant'],
 *       'after_register' => fn($result, $input) => log_info('New registration'),
 *   ]);
 *
 *   $jwtMiddleware = new JwtAuthMiddleware([
 *       'excludedPaths' => ExternalAuthRoutes::publicPaths($options),
 *   ]);
 *
 * @package StoneScriptPHP\Auth\ExternalAuth
 */
class ExternalAuthRoutes
{
    /** @var string The legacy prefix that compat mode registers routes under */
    private const LEGACY_PREFIX = '/auth';

    /**
     * Register all enabled external auth routes with the router.
     *
     * When `legacy_compat` is true and the canonical prefix is NOT `/auth`,
     * routes are registered under BOTH the canonical prefix and the legacy `/auth`
     * prefix. This allows Angular clients to keep calling `/auth/*` during the
     * transition to `/api/auth/*`.
     *
     * @param Router $router The router instance
     * @param array $options Configuration options (see ExternalAuthConfig)
     * @return void
     */
    public static function register(Router $router, array $options = []): void
    {
        $config = new ExternalAuthConfig($options);
        $client = new ExternalAuthServiceClient($config->authServiceUrl, $config->platformCode);
        $provisioner = $options['provisioner'] ?? null;
        // Platform-specific role resolver for the token exchange route.
        // Signature: fn(array $identityClaims): array. Injected like $provisioner.
        $rolesResolver = $options['roles_resolver'] ?? null;

        // Register routes under the canonical prefix
        self::registerForPrefix($router, $config->prefix, $client, $config, $provisioner, $rolesResolver);

        // AUTH-SPEC §S1 legacy compat: also register under /auth if the canonical
        // prefix differs from /auth. This keeps existing clients working during
        // the transition window. Skip when prefix is already /auth (no double-register).
        if ($config->legacyCompat && $config->prefix !== self::LEGACY_PREFIX) {
            log_warning(
                "ExternalAuthRoutes: legacy_compat=true — also registering routes under " .
                self::LEGACY_PREFIX . " (deprecated; set legacy_compat=false once clients use {$config->prefix})"
            );
            self::registerForPrefix($router, self::LEGACY_PREFIX, $client, $config, $provisioner, $rolesResolver);
        }

        log_info("ExternalAuthRoutes: Registration complete with prefix '{$config->prefix}'" .
            ($config->legacyCompat && $config->prefix !== self::LEGACY_PREFIX
                ? ' + legacy compat ' . self::LEGACY_PREFIX
                : ''));
    }

    /**
     * Register all enabled routes under a single prefix.
     *
     * Private helper extracted so both canonical and legacy registrations
     * share identical logic without duplication.
     */
    private static function registerForPrefix(
        Router $router,
        string $prefix,
        ExternalAuthServiceClient $client,
        ExternalAuthConfig $config,
        mixed $provisioner,
        mixed $rolesResolver = null
    ): void {
        // Public routes (no auth required)
        if ($config->isEnabled('register') && $config->registrationMode !== 'none') {
            $router->post("$prefix/register", new RegisterRoute($client, $config->hooks, $config), [], true);
            log_debug("ExternalAuthRoutes: Registered POST $prefix/register (mode={$config->registrationMode})");
        }

        if ($config->isEnabled('login')) {
            $router->post("$prefix/login", new LoginRoute($client, $config->hooks, $config), [], true);
            log_debug("ExternalAuthRoutes: Registered POST $prefix/login");
        }

        if ($config->isEnabled('logout')) {
            $router->post("$prefix/logout", new LogoutRoute($client, $config->hooks, $config), [], true);
            log_debug("ExternalAuthRoutes: Registered POST $prefix/logout");
        }

        if ($config->isEnabled('refresh')) {
            $router->post("$prefix/refresh-token", new RefreshTokenRoute($client, $config->hooks, $config), [], true);
            log_debug("ExternalAuthRoutes: Registered POST $prefix/refresh-token");
        }

        if ($config->isEnabled('password_reset')) {
            $router->post("$prefix/forgot-password", new ForgotPasswordRoute($client, $config->hooks, $config), [], true);
            $router->post("$prefix/reset-password", new ResetPasswordRoute($client, $config->hooks, $config), [], true);
            log_debug("ExternalAuthRoutes: Registered POST $prefix/forgot-password, $prefix/reset-password");
        }

        if ($config->isEnabled('accept_invite')) {
            $router->post("$prefix/accept-invite", new AcceptInviteRoute($client, $config->hooks, $config), [], true);
            log_debug("ExternalAuthRoutes: Registered POST $prefix/accept-invite");
        }

        if ($config->isEnabled('check_slug')) {
            $router->get("$prefix/check-tenant-slug/{slug}", new CheckTenantSlugRoute($client, $config->hooks, $config), [], true);
            log_debug("ExternalAuthRoutes: Registered GET $prefix/check-tenant-slug/{slug}");
        }

        if ($config->isEnabled('onboarding_status')) {
            $router->get("$prefix/onboarding/status", new OnboardingStatusRoute($client, $config->hooks, $config), [], true);
            log_debug("ExternalAuthRoutes: Registered GET $prefix/onboarding/status");
        }

        if ($config->isEnabled('verify_email')) {
            $router->post("$prefix/verify-email", new VerifyEmailRoute($client, $config->hooks, $config), [], true);
            log_debug("ExternalAuthRoutes: Registered POST $prefix/verify-email");
        }

        if ($config->isEnabled('resend_code')) {
            $router->post("$prefix/resend-code", new ResendVerificationCodeRoute($client, $config->hooks, $config), [], true);
            log_debug("ExternalAuthRoutes: Registered POST $prefix/resend-code");
        }

        if ($config->isEnabled('oauth')) {
            $router->post("$prefix/oauth/initiate", new OAuthInitiateRoute($client, $config->hooks, $config), [], true);
            $router->post("$prefix/oauth/callback", new OAuthCallbackRoute($client, $config->hooks, $config), [], true);
            log_debug("ExternalAuthRoutes: Registered OAuth routes at $prefix/oauth/*");
        }

        if ($config->isEnabled('health')) {
            $router->get("$prefix/health", new AuthHealthRoute($client, $config->hooks, $config), [], true);
            log_debug("ExternalAuthRoutes: Registered GET $prefix/health");
        }

        // Token exchange is PUBLIC: the inbound Authorization token is an
        // auth-service identity token (not a platform token), so it must bypass
        // JwtAuthMiddleware — the route validates it itself against the JWKS.
        if ($config->isEnabled('exchange')) {
            $router->post(
                "$prefix/exchange",
                new ExchangeRoute($client, $config->hooks, $config, $rolesResolver),
                [],
                true
            );
            log_debug("ExternalAuthRoutes: Registered POST $prefix/exchange (public)");
        }

        // Protected routes (auth required)
        if ($config->isEnabled('select_tenant')) {
            $router->post(
                "$prefix/select-tenant",
                new SelectTenantRoute($client, $config->hooks, $config)
            );
            log_debug("ExternalAuthRoutes: Registered POST $prefix/select-tenant (protected)");
        }

        if ($config->isEnabled('provision_tenant')) {
            $router->post(
                "$prefix/provision-tenant",
                new ProvisionTenantRoute($client, $config->hooks, $config, $provisioner)
            );
            log_debug("ExternalAuthRoutes: Registered POST $prefix/provision-tenant (protected)");
        }

        if ($config->isEnabled('change_password')) {
            $router->post(
                "$prefix/change-password",
                new ChangePasswordRoute($client, $config->hooks, $config)
            );
            log_debug("ExternalAuthRoutes: Registered POST $prefix/change-password (protected)");
        }

        if ($config->isEnabled('invite')) {
            $router->post(
                "$prefix/invite-member",
                new InviteMemberRoute($client, $config->hooks, $config)
            );
            log_debug("ExternalAuthRoutes: Registered POST $prefix/invite-member (protected)");
        }

        if ($config->isEnabled('memberships')) {
            $router->get(
                "$prefix/memberships",
                new MembershipsRoute($client, $config->hooks, $config)
            );
            $router->addRoute(
                'PUT',
                "$prefix/memberships/{id}",
                new UpdateMembershipRoute($client, $config->hooks, $config)
            );
            log_debug("ExternalAuthRoutes: Registered GET $prefix/memberships, PUT $prefix/memberships/{id} (protected)");
        }

        if ($config->isEnabled('profile')) {
            $router->get(
                "$prefix/me",
                new ProfileRoute($client, $config->hooks, $config)
            );
            log_debug("ExternalAuthRoutes: Registered GET $prefix/me (protected)");
        }
    }

    /**
     * Get public paths (no auth required) based on options.
     *
     * Pure function — computes paths WITHOUT registering routes.
     * Use this to build JwtAuthMiddleware excludedPaths.
     *
     * When legacy_compat is true and prefix is not /auth, returns paths for BOTH
     * the canonical prefix and the legacy /auth prefix so the JWT middleware
     * excludes requests to either path.
     *
     * @param array $options Same options passed to register()
     * @return array List of public path strings
     */
    public static function publicPaths(array $options = []): array
    {
        $config = new ExternalAuthConfig($options);

        $paths = self::computePublicPaths($config->prefix, $config);

        // AUTH-SPEC §S1 legacy compat: include /auth/* paths in the exclusion list
        // so the JWT middleware does not block requests to the old prefix.
        if ($config->legacyCompat && $config->prefix !== self::LEGACY_PREFIX) {
            $paths = array_merge($paths, self::computePublicPaths(self::LEGACY_PREFIX, $config));
        }

        return $paths;
    }

    /**
     * Compute public path strings for a given prefix.
     *
     * @param string $prefix URL prefix
     * @param ExternalAuthConfig $config Parsed config
     * @return array List of public path strings under this prefix
     */
    private static function computePublicPaths(string $prefix, ExternalAuthConfig $config): array
    {
        $paths = [];

        if ($config->isEnabled('register')) {
            $paths[] = "$prefix/register";
        }
        if ($config->isEnabled('login')) {
            $paths[] = "$prefix/login";
        }
        if ($config->isEnabled('logout')) {
            $paths[] = "$prefix/logout";
        }
        if ($config->isEnabled('refresh')) {
            $paths[] = "$prefix/refresh-token";
        }
        if ($config->isEnabled('password_reset')) {
            $paths[] = "$prefix/forgot-password";
            $paths[] = "$prefix/reset-password";
        }
        if ($config->isEnabled('accept_invite')) {
            $paths[] = "$prefix/accept-invite";
        }
        if ($config->isEnabled('check_slug')) {
            $paths[] = "$prefix/check-tenant-slug";
        }
        if ($config->isEnabled('onboarding_status')) {
            $paths[] = "$prefix/onboarding/status";
        }
        if ($config->isEnabled('verify_email')) {
            $paths[] = "$prefix/verify-email";
        }
        if ($config->isEnabled('resend_code')) {
            $paths[] = "$prefix/resend-code";
        }
        if ($config->isEnabled('oauth')) {
            $paths[] = "$prefix/oauth/initiate";
            $paths[] = "$prefix/oauth/callback";
        }
        if ($config->isEnabled('health')) {
            $paths[] = "$prefix/health";
        }
        if ($config->isEnabled('exchange')) {
            $paths[] = "$prefix/exchange";
        }

        return $paths;
    }

    /**
     * Get protected paths (auth required) based on options
     *
     * @param array $options Same options passed to register()
     * @return array List of protected path strings
     */
    public static function protectedPaths(array $options = []): array
    {
        $config = new ExternalAuthConfig($options);
        $prefix = $config->prefix;
        $paths = [];

        if ($config->isEnabled('select_tenant')) {
            $paths[] = "$prefix/select-tenant";
        }
        if ($config->isEnabled('provision_tenant')) {
            $paths[] = "$prefix/provision-tenant";
        }
        if ($config->isEnabled('change_password')) {
            $paths[] = "$prefix/change-password";
        }
        if ($config->isEnabled('invite')) {
            $paths[] = "$prefix/invite-member";
        }
        if ($config->isEnabled('memberships')) {
            $paths[] = "$prefix/memberships";
        }
        if ($config->isEnabled('profile')) {
            $paths[] = "$prefix/me";
        }

        return $paths;
    }

    /**
     * Get all paths (public + protected) based on options
     *
     * @param array $options Same options passed to register()
     * @return array List of all path strings
     */
    public static function allPaths(array $options = []): array
    {
        return array_merge(
            self::publicPaths($options),
            self::protectedPaths($options)
        );
    }

    /**
     * Get route definitions in the same format as routes.php
     *
     * Returns ['GET' => ['/path' => HandlerClass::class], 'POST' => [...], ...]
     * Used by the client generator to include framework-level routes.
     *
     * Does NOT instantiate ExternalAuthConfig (which requires Env/database config).
     * Instead, reads prefix and feature toggles directly from the options array
     * using the same defaults as ExternalAuthConfig.
     *
     * @param array $options Same options passed to register()
     * @return array Route definitions grouped by HTTP method
     */
    public static function getRouteDefinitions(array $options = []): array
    {
        // AUTH-SPEC §S1: default changed from /auth to /api/auth (matches ExternalAuthConfig).
        $prefix = rtrim($options['prefix'] ?? '/api/auth', '/');

        // Feature toggle defaults (must match ExternalAuthConfig::__construct)
        $features = [
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

        $isEnabled = fn(string $feature) => $features[$feature] ?? false;
        $routes = ['GET' => [], 'POST' => [], 'PUT' => []];

        // Public routes
        if ($isEnabled('register')) {
            $routes['POST']["$prefix/register"] = RegisterRoute::class;
        }
        if ($isEnabled('login')) {
            $routes['POST']["$prefix/login"] = LoginRoute::class;
        }
        if ($isEnabled('logout')) {
            $routes['POST']["$prefix/logout"] = LogoutRoute::class;
        }
        if ($isEnabled('refresh')) {
            $routes['POST']["$prefix/refresh-token"] = RefreshTokenRoute::class;
        }
        if ($isEnabled('password_reset')) {
            $routes['POST']["$prefix/forgot-password"] = ForgotPasswordRoute::class;
            $routes['POST']["$prefix/reset-password"] = ResetPasswordRoute::class;
        }
        if ($isEnabled('accept_invite')) {
            $routes['POST']["$prefix/accept-invite"] = AcceptInviteRoute::class;
        }
        if ($isEnabled('check_slug')) {
            $routes['GET']["$prefix/check-tenant-slug/{slug}"] = CheckTenantSlugRoute::class;
        }
        if ($isEnabled('onboarding_status')) {
            $routes['GET']["$prefix/onboarding/status"] = OnboardingStatusRoute::class;
        }
        if ($isEnabled('verify_email')) {
            $routes['POST']["$prefix/verify-email"] = VerifyEmailRoute::class;
        }
        if ($isEnabled('resend_code')) {
            $routes['POST']["$prefix/resend-code"] = ResendVerificationCodeRoute::class;
        }
        if ($isEnabled('oauth')) {
            $routes['POST']["$prefix/oauth/initiate"] = OAuthInitiateRoute::class;
            $routes['POST']["$prefix/oauth/callback"] = OAuthCallbackRoute::class;
        }
        if ($isEnabled('health')) {
            $routes['GET']["$prefix/health"] = AuthHealthRoute::class;
        }
        if ($isEnabled('exchange')) {
            $routes['POST']["$prefix/exchange"] = ExchangeRoute::class;
        }

        // Protected routes
        if ($isEnabled('select_tenant')) {
            $routes['POST']["$prefix/select-tenant"] = SelectTenantRoute::class;
        }
        if ($isEnabled('provision_tenant')) {
            $routes['POST']["$prefix/provision-tenant"] = ProvisionTenantRoute::class;
        }
        if ($isEnabled('change_password')) {
            $routes['POST']["$prefix/change-password"] = ChangePasswordRoute::class;
        }
        if ($isEnabled('invite')) {
            $routes['POST']["$prefix/invite-member"] = InviteMemberRoute::class;
        }
        if ($isEnabled('memberships')) {
            $routes['GET']["$prefix/memberships"] = MembershipsRoute::class;
            $routes['PUT']["$prefix/memberships/{id}"] = UpdateMembershipRoute::class;
        }
        if ($isEnabled('profile')) {
            $routes['GET']["$prefix/me"] = ProfileRoute::class;
        }

        return $routes;
    }
}
