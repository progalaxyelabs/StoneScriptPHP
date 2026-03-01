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

/**
 * ExternalAuth Route Registration
 *
 * Registers framework-level proxy routes for external auth services.
 * Replaces 18+ duplicate proxy routes that each platform previously maintained.
 *
 * Usage in your index.php:
 *
 *   ExternalAuthRoutes::register($router, [
 *       'prefix' => '/auth',
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
    /**
     * Register all enabled external auth routes with the router
     *
     * @param Router $router The router instance
     * @param array $options Configuration options (see ExternalAuthConfig)
     * @return void
     */
    public static function register(Router $router, array $options = []): void
    {
        $config = new ExternalAuthConfig($options);
        $client = new ExternalAuthServiceClient($config->authServiceUrl, $config->platformCode);
        $prefix = $config->prefix;

        // Public routes (no auth required)
        if ($config->isEnabled('register')) {
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
            $router->get("$prefix/check-tenant-slug/:slug", new CheckTenantSlugRoute($client, $config->hooks, $config), [], true);
            log_debug("ExternalAuthRoutes: Registered GET $prefix/check-tenant-slug/:slug");
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
                new ProvisionTenantRoute($client, $config->hooks, $config)
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
                "$prefix/memberships/:id",
                new UpdateMembershipRoute($client, $config->hooks, $config)
            );
            log_debug("ExternalAuthRoutes: Registered GET $prefix/memberships, PUT $prefix/memberships/:id (protected)");
        }

        if ($config->isEnabled('profile')) {
            $router->get(
                "$prefix/me",
                new ProfileRoute($client, $config->hooks, $config)
            );
            log_debug("ExternalAuthRoutes: Registered GET $prefix/me (protected)");
        }

        log_info("ExternalAuthRoutes: Registration complete with prefix '$prefix'");
    }

    /**
     * Get public paths (no auth required) based on options
     *
     * Pure function — computes paths WITHOUT registering routes.
     * Use this to build JwtAuthMiddleware excludedPaths.
     *
     * @param array $options Same options passed to register()
     * @return array List of public path strings
     */
    public static function publicPaths(array $options = []): array
    {
        $config = new ExternalAuthConfig($options);
        $prefix = $config->prefix;
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
}
