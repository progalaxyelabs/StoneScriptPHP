<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth;

use StoneScriptPHP\Routing\Router;
use StoneScriptPHP\Routing\RouteAccess;
use StoneScriptPHP\Auth\ExternalAuth\Routes\RegisterRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\LoginRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\LogoutRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\RefreshTokenRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\ForgotPasswordRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\ResetPasswordRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\ChangePasswordRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\OnboardingStatusRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\ProfileRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\OAuthInitiateRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\OAuthCallbackRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\AuthHealthRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\VerifyEmailRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\ResendVerificationCodeRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\ExchangeRoute;
use StoneScriptPHP\Auth\ExternalAuth\Dto\RegisterResponseDto;
use StoneScriptPHP\Auth\ExternalAuth\Dto\LoginResponseDto;
use StoneScriptPHP\Auth\ExternalAuth\Dto\LogoutResponseDto;
use StoneScriptPHP\Auth\ExternalAuth\Dto\RefreshResponseDto;
use StoneScriptPHP\Auth\ExternalAuth\Dto\OnboardingStatusResponseDto;
use StoneScriptPHP\Auth\ExternalAuth\Dto\OAuthInitiateResponseDto;
use StoneScriptPHP\Auth\ExternalAuth\Dto\AuthHealthResponseDto;
use StoneScriptPHP\Auth\ExternalAuth\Dto\ExchangeResponseDto;
use StoneScriptPHP\Auth\ExternalAuth\Dto\ProfileResponseDto;

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

        // API-token model resolvers (framework-spec.md §6).
        // roles_resolver: fn(array $claimsWithTenant): string[] — roles for identity in tenant.
        // tenants_resolver: fn(array $authClaims): array[] — tenants the identity belongs to.
        $rolesResolver   = $options['roles_resolver']   ?? null;
        $tenantsResolver = $config->tenantsResolver;

        // Register routes under the canonical prefix
        self::registerForPrefix($router, $config->prefix, $client, $config, $provisioner, $rolesResolver, $tenantsResolver);

        // AUTH-SPEC §S1 legacy compat: also register under /auth if the canonical
        // prefix differs from /auth. This keeps existing clients working during
        // the transition window. Skip when prefix is already /auth (no double-register).
        if ($config->legacyCompat && $config->prefix !== self::LEGACY_PREFIX) {
            log_warning(
                "ExternalAuthRoutes: legacy_compat=true — also registering routes under " .
                self::LEGACY_PREFIX . " (deprecated; set legacy_compat=false once clients use {$config->prefix})"
            );
            self::registerForPrefix($router, self::LEGACY_PREFIX, $client, $config, $provisioner, $rolesResolver, $tenantsResolver);
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
        mixed $rolesResolver = null,
        mixed $tenantsResolver = null
    ): void {
        // Public routes (no auth required)
        if ($config->isEnabled('register') && $config->registrationMode !== 'none') {
            $router->post(
                "$prefix/register",
                new RegisterRoute($client, $config->hooks, $config),
                middleware: [],
                isPublic: true,
                group: 'auth',
                response: RegisterResponseDto::class,
            );
            log_debug("ExternalAuthRoutes: Registered POST $prefix/register (mode={$config->registrationMode})");
        }

        if ($config->isEnabled('login')) {
            $router->post(
                "$prefix/login",
                new LoginRoute($client, $config->hooks, $config),
                middleware: [],
                isPublic: true,
                group: 'auth',
                response: LoginResponseDto::class,
            );
            log_debug("ExternalAuthRoutes: Registered POST $prefix/login");
        }

        if ($config->isEnabled('logout')) {
            $router->post(
                "$prefix/logout",
                new LogoutRoute($client, $config->hooks, $config),
                middleware: [],
                isPublic: true,
                group: 'auth',
                response: LogoutResponseDto::class,
            );
            log_debug("ExternalAuthRoutes: Registered POST $prefix/logout");
        }

        if ($config->isEnabled('refresh')) {
            $router->post(
                "$prefix/refresh-token",
                new RefreshTokenRoute($client, $config->hooks, $config),
                middleware: [],
                isPublic: true,
                group: 'auth',
                response: RefreshResponseDto::class,
            );
            log_debug("ExternalAuthRoutes: Registered POST $prefix/refresh-token");
        }

        // KNOWN BUG (found during v9.6.0 typing pass, not fixed here — out of
        // scope for a typing change, needs its own triage/fix task): these two
        // routes proxy to ExternalAuthServiceClient::requestPasswordReset() /
        // confirmPasswordReset(), which POST to `/api/auth/forgot-password` and
        // `/api/auth/reset-password`. A live audit of the configured external
        // auth service found neither path exists there — the real endpoints
        // are POST /api/account/password-reset/request and
        // POST /api/account/password-reset/confirm. Any platform with
        // password_reset enabled is calling a 404. Deliberately NOT given a
        // `response:` DTO — inventing a typed shape for an endpoint that
        // doesn't exist would be a fabricated contract, worse than no
        // contract. Flag this to your own auth service integration before
        // relying on it.
        if ($config->isEnabled('password_reset')) {
            $router->post(
                "$prefix/forgot-password",
                new ForgotPasswordRoute($client, $config->hooks, $config),
                middleware: [],
                isPublic: true,
                group: 'auth',
            );
            $router->post(
                "$prefix/reset-password",
                new ResetPasswordRoute($client, $config->hooks, $config),
                middleware: [],
                isPublic: true,
                group: 'auth',
            );
            log_debug("ExternalAuthRoutes: Registered POST $prefix/forgot-password, $prefix/reset-password");
        }

        // check_slug is a tenant route — delegated below via
        // $config->tenantRouteProvider (Phase 1 seam, see TenantRouteProviderInterface).
        // (accept_invite used to live here too — removed 2026-07-21, see
        // DefaultTenantRouteProvider's class docblock.)

        if ($config->isEnabled('onboarding_status')) {
            $router->get(
                "$prefix/onboarding/status",
                new OnboardingStatusRoute($client, $config->hooks, $config),
                middleware: [],
                isPublic: true,
                group: 'auth',
                response: OnboardingStatusResponseDto::class,
            );
            log_debug("ExternalAuthRoutes: Registered GET $prefix/onboarding/status");
        }

        // KNOWN BUG (see forgot-password/reset-password note above, same
        // audit): proxies to `/api/auth/verify-email`, which does not exist
        // on the current auth service at all (no equivalent endpoint found).
        // No `response:` DTO for the same reason.
        if ($config->isEnabled('verify_email')) {
            $router->post(
                "$prefix/verify-email",
                new VerifyEmailRoute($client, $config->hooks, $config),
                middleware: [],
                isPublic: true,
                group: 'auth',
            );
            log_debug("ExternalAuthRoutes: Registered POST $prefix/verify-email");
        }

        // KNOWN BUG — same audit: proxies to `/api/auth/resend-code`, which
        // does not exist on the current auth service. No `response:` DTO.
        if ($config->isEnabled('resend_code')) {
            $router->post(
                "$prefix/resend-code",
                new ResendVerificationCodeRoute($client, $config->hooks, $config),
                middleware: [],
                isPublic: true,
                group: 'auth',
            );
            log_debug("ExternalAuthRoutes: Registered POST $prefix/resend-code");
        }

        if ($config->isEnabled('oauth')) {
            $router->post(
                "$prefix/oauth/initiate",
                new OAuthInitiateRoute($client, $config->hooks, $config),
                middleware: [],
                isPublic: true,
                group: 'auth',
                response: OAuthInitiateResponseDto::class,
            );
            $router->post(
                "$prefix/oauth/callback",
                new OAuthCallbackRoute($client, $config->hooks, $config),
                middleware: [],
                isPublic: true,
                group: 'auth',
                response: LoginResponseDto::class,
            );
            log_debug("ExternalAuthRoutes: Registered OAuth routes at $prefix/oauth/*");
        }

        if ($config->isEnabled('health')) {
            $router->get(
                "$prefix/health",
                new AuthHealthRoute($client, $config->hooks, $config),
                middleware: [],
                isPublic: true,
                group: 'auth',
                service: 'infra',
                response: AuthHealthResponseDto::class,
            );
            log_debug("ExternalAuthRoutes: Registered GET $prefix/health");
        }

        // Token exchange is PUBLIC: the inbound Authorization token is an auth token (identity
        // JWT from the auth service, not a platform API token). JwtAuthMiddleware validates platform
        // API tokens and would reject it. The exchange route validates the auth token itself via JWKS.
        // API-token model: body carries tenant_id + optional role_id. Returns §6 session contract.
        if ($config->isEnabled('exchange')) {
            $router->post(
                "$prefix/exchange",
                new ExchangeRoute($client, $config->hooks, $config, $rolesResolver, $tenantsResolver),
                middleware: [],
                isPublic: true,
                group: 'auth',
                response: ExchangeResponseDto::class,
            );
            log_debug("ExternalAuthRoutes: Registered POST $prefix/exchange (public, API-token model)");
        }

        // Protected routes (auth required)

        // KNOWN BUG (see forgot-password/reset-password note above, same
        // audit): proxies to `/api/auth/change-password`, which does not
        // exist — the real endpoint is `POST /api/account/password`. No
        // `response:` DTO for the same reason.
        if ($config->isEnabled('change_password')) {
            // Tier-2 identity route: consumes an AUTH TOKEN (purpose=authentication),
            // never an API token. Typed access=authentication so the 7.x typed-auth
            // AccessTokenMiddleware admits the auth token (an API token would be a purpose
            // mismatch → 403). Mirrors this route's membership of protectedPaths()
            // (the RequireApiTokenMiddleware "no API token needed" exemption list).
            $router->post(
                "$prefix/change-password",
                new ChangePasswordRoute($client, $config->hooks, $config),
                group: 'auth',
                access: RouteAccess::AUTHENTICATION
            );
            log_debug("ExternalAuthRoutes: Registered POST $prefix/change-password (protected, authentication)");
        }

        // select_tenant, provision_tenant, memberships are tenant routes —
        // delegated below via $config->tenantRouteProvider (Phase 1 seam, see
        // TenantRouteProviderInterface — bundles registration with the
        // RequireApiTokenMiddleware exemption-path computation so they can't drift apart).
        // (invite used to live here too — removed 2026-07-21, see
        // DefaultTenantRouteProvider's class docblock.)
        $config->tenantRouteProvider->register(
            $router,
            $prefix,
            $client,
            $config,
            $provisioner,
            $rolesResolver,
            $tenantsResolver
        );

        if ($config->isEnabled('profile')) {
            // Tier-2 identity route: consumes an AUTH TOKEN (purpose=authentication).
            // /me returns the cross-tenant session context (identity + available
            // tenants/roles) resolved from the auth token — it is NOT a tenant-scoped
            // API-token route. Typed access=authentication (see change-password above).
            //
            // ProfileResponseDto is ONLY accurate for ProfileRoute's api-token-model
            // branch (both resolvers configured) — see that DTO's class docblock.
            // When either resolver is missing, the route falls back to proxying the
            // raw auth-service response, a DIFFERENT shape; declaring the DTO there
            // would be a wrong typed contract, worse than none. Mirror the exact
            // condition ProfileRoute::process() branches on.
            $router->get(
                "$prefix/me",
                new ProfileRoute($client, $config->hooks, $config, $rolesResolver, $tenantsResolver),
                group: 'auth',
                response: ($rolesResolver !== null && $tenantsResolver !== null) ? ProfileResponseDto::class : null,
                access: RouteAccess::AUTHENTICATION
            );
            log_debug("ExternalAuthRoutes: Registered GET $prefix/me (protected, authentication session)");
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
        // check_slug is a tenant route — merged below via
        // $config->tenantRouteProvider->publicPaths().
        // (accept_invite used to live here too — removed 2026-07-21.)
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

        $paths = array_merge($paths, $config->tenantRouteProvider->publicPaths($prefix, $config));

        return $paths;
    }

    /**
     * Get protected paths — tier-2, identity-required-but-tenant-agnostic routes
     * (auth required, but an auth token suffices; no API token/tenant_id needed).
     *
     * Used by `RequireApiTokenMiddleware`'s exemption list (see its class docblock and
     * `Application::run()`'s `require_api_token` config key) so those routes are never
     * wrongly rejected for lacking a `tenant_id` — they were never supposed to need
     * one. This is the single source of truth for that list; do not hand-maintain
     * a duplicate anywhere.
     *
     * Mirrors `publicPaths()`'s legacy_compat handling (2026-07-05 fix — this method
     * previously only returned the canonical-prefix paths, so a platform still on the
     * default `legacy_compat: true` would 403 requests to the legacy `/auth/*` prefix
     * even after wiring the exemption correctly for `/api/auth/*`).
     *
     * @param array $options Same options passed to register()
     * @return array List of protected path strings
     */
    public static function protectedPaths(array $options = []): array
    {
        $config = new ExternalAuthConfig($options);

        $paths = self::computeProtectedPaths($config->prefix, $config);

        if ($config->legacyCompat && $config->prefix !== self::LEGACY_PREFIX) {
            $paths = array_merge($paths, self::computeProtectedPaths(self::LEGACY_PREFIX, $config));
        }

        return $paths;
    }

    /**
     * Compute protected (tier-2) path strings for a given prefix.
     *
     * @param string $prefix URL prefix
     * @param ExternalAuthConfig $config Parsed config
     * @return array List of protected path strings under this prefix
     */
    private static function computeProtectedPaths(string $prefix, ExternalAuthConfig $config): array
    {
        $paths = [];

        // select_tenant, provision_tenant, memberships are tenant routes —
        // merged below via $config->tenantRouteProvider->protectedPaths(). Kept in
        // the SAME class as their registration (see TenantRouteProviderInterface)
        // so this exemption list can never drift out of sync with what's actually
        // registered — the root cause of the 2026-07-05 fleet incident.
        // (invite used to live here too — removed 2026-07-21.)
        if ($config->isEnabled('change_password')) {
            $paths[] = "$prefix/change-password";
        }
        if ($config->isEnabled('profile')) {
            $paths[] = "$prefix/me";
        }

        $paths = array_merge($paths, $config->tenantRouteProvider->protectedPaths($prefix, $config));

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
            // 'invite' / 'accept_invite' REMOVED 2026-07-21 — see
            // ExternalAuthConfig::__construct() and DefaultTenantRouteProvider's
            // class docblock for why (auth-service endpoints they proxied to
            // are gone; invitations are now generated per-platform).
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

        // v9.6.0: full array-config format (handler + group + response DTO +
        // collection) instead of a bare handler class string — mirrors
        // registerForPrefix()'s runtime wiring so a platform merging these
        // into its own routes.php (`array_merge($routes[$method] ?? [],
        // ExternalAuthRoutes::getRouteDefinitions($opts)[$method] ?? [])`)
        // gets the SAME typed contract client-generation would use if these
        // routes were registered directly on $router. See CHANGELOG.
        //
        // Routes marked "no response — see registerForPrefix()" proxy to an
        // external auth-service endpoint that a live audit found does not
        // exist on the configured service — declaring a DTO for them would
        // fabricate a contract for a 404. See the matching comment in
        // registerForPrefix() for detail.
        if ($isEnabled('register')) {
            $routes['POST']["$prefix/register"] = ['handler' => RegisterRoute::class, 'group' => 'auth', 'is_public' => true, 'response' => RegisterResponseDto::class];
        }
        if ($isEnabled('login')) {
            $routes['POST']["$prefix/login"] = ['handler' => LoginRoute::class, 'group' => 'auth', 'is_public' => true, 'response' => LoginResponseDto::class];
        }
        if ($isEnabled('logout')) {
            $routes['POST']["$prefix/logout"] = ['handler' => LogoutRoute::class, 'group' => 'auth', 'is_public' => true, 'response' => LogoutResponseDto::class];
        }
        if ($isEnabled('refresh')) {
            $routes['POST']["$prefix/refresh-token"] = ['handler' => RefreshTokenRoute::class, 'group' => 'auth', 'is_public' => true, 'response' => RefreshResponseDto::class];
        }
        if ($isEnabled('password_reset')) {
            // KNOWN BUG — see registerForPrefix(): dead endpoints, no response DTO.
            $routes['POST']["$prefix/forgot-password"] = ['handler' => ForgotPasswordRoute::class, 'group' => 'auth', 'is_public' => true];
            $routes['POST']["$prefix/reset-password"] = ['handler' => ResetPasswordRoute::class, 'group' => 'auth', 'is_public' => true];
        }
        // check_slug is a tenant route — merged below via the configured (or
        // default) TenantRouteProviderInterface. (accept_invite used to live
        // here too — removed 2026-07-21.)
        if ($isEnabled('onboarding_status')) {
            $routes['GET']["$prefix/onboarding/status"] = ['handler' => OnboardingStatusRoute::class, 'group' => 'auth', 'is_public' => true, 'response' => OnboardingStatusResponseDto::class];
        }
        if ($isEnabled('verify_email')) {
            // KNOWN BUG — see registerForPrefix(): dead endpoint, no response DTO.
            $routes['POST']["$prefix/verify-email"] = ['handler' => VerifyEmailRoute::class, 'group' => 'auth', 'is_public' => true];
        }
        if ($isEnabled('resend_code')) {
            // KNOWN BUG — see registerForPrefix(): dead endpoint, no response DTO.
            $routes['POST']["$prefix/resend-code"] = ['handler' => ResendVerificationCodeRoute::class, 'group' => 'auth', 'is_public' => true];
        }
        if ($isEnabled('oauth')) {
            $routes['POST']["$prefix/oauth/initiate"] = ['handler' => OAuthInitiateRoute::class, 'group' => 'auth', 'is_public' => true, 'response' => OAuthInitiateResponseDto::class];
            $routes['POST']["$prefix/oauth/callback"] = ['handler' => OAuthCallbackRoute::class, 'group' => 'auth', 'is_public' => true, 'response' => LoginResponseDto::class];
        }
        if ($isEnabled('health')) {
            $routes['GET']["$prefix/health"] = ['handler' => AuthHealthRoute::class, 'group' => 'auth', 'service' => 'infra', 'is_public' => true, 'response' => AuthHealthResponseDto::class];
        }
        if ($isEnabled('exchange')) {
            $routes['POST']["$prefix/exchange"] = ['handler' => ExchangeRoute::class, 'group' => 'auth', 'is_public' => true, 'response' => ExchangeResponseDto::class];
        }

        // Protected routes
        if ($isEnabled('change_password')) {
            // KNOWN BUG — see registerForPrefix(): dead endpoint, no response DTO.
            $routes['POST']["$prefix/change-password"] = ['handler' => ChangePasswordRoute::class, 'group' => 'auth', 'access' => 'authentication'];
        }
        if ($isEnabled('profile')) {
            // ProfileResponseDto only fits the api-token-model branch (both
            // resolvers configured) — see that DTO's docblock and the matching
            // condition in registerForPrefix().
            $hasResolvers = ($options['roles_resolver'] ?? null) !== null && ($options['tenants_resolver'] ?? null) !== null;
            $routes['GET']["$prefix/me"] = array_filter([
                'handler' => ProfileRoute::class,
                'group' => 'auth',
                'access' => 'authentication',
                'response' => $hasResolvers ? ProfileResponseDto::class : null,
            ], fn($v) => $v !== null);
        }

        // select_tenant, provision_tenant, memberships, check_slug are tenant
        // routes — merged from the configured (or default)
        // TenantRouteProviderInterface. Reads the raw options key directly (not via
        // ExternalAuthConfig, which requires Env/database config — see docblock above).
        // (invite, accept_invite used to live here too — removed 2026-07-21.)
        $tenantRouteProvider = $options['tenant_route_provider'] ?? null;
        if (!$tenantRouteProvider instanceof TenantRouteProviderInterface) {
            $tenantRouteProvider = new DefaultTenantRouteProvider();
        }
        foreach ($tenantRouteProvider->getRouteDefinitions($prefix, $features) as $method => $methodRoutes) {
            $routes[$method] = array_merge($routes[$method] ?? [], $methodRoutes);
        }

        return $routes;
    }
}
