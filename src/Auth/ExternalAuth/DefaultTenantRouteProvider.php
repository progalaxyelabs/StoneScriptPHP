<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth;

use StoneScriptPHP\Routing\Router;
use StoneScriptPHP\Routing\RouteAccess;
use StoneScriptPHP\Auth\ExternalAuth\Routes\SelectTenantRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\ProvisionTenantRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\UpdateMembershipRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\MembershipsRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\CheckTenantSlugRoute;

/**
 * DefaultTenantRouteProvider
 *
 * The framework's built-in `TenantRouteProviderInterface` implementation —
 * registers exactly the same tenant routes, under exactly the same feature
 * toggles, as ExternalAuthRoutes did before the Phase 1 plugin seam existed.
 * This is the ONLY place in the framework that still hard-`use`-imports these
 * route classes; `ExternalAuthRoutes` itself no longer does.
 *
 * `ExternalAuthConfig` uses this as the default `tenantRouteProvider` — a
 * platform that never touches the new `tenant_route_provider` option gets
 * byte-for-byte identical route registration to pre-Phase-1 StoneScriptPHP.
 *
 * REMOVED 2026-07-21 (real, live-breaking fix — see
 * DESIGN-invitation-system.md §1.3/§4.3/§5 in this repo): `invite-member`
 * and `accept-invite` used to be registered here, proxying to
 * `POST /api/auth/invite` / `POST /api/auth/accept-invite` on the auth
 * service. Those auth-service endpoints are gone — the invitation system
 * moved fully platform-side (auth owns zero invitation data, per the design
 * doc's non-negotiable constraint §0). Every platform still on framework
 * defaults had these two routes live and auto-registered, silently pointing
 * at endpoints that now 404/error. Removed entirely rather than left
 * toggled-off-by-default, because there is no configuration under which
 * re-enabling them would ever work again — a dead toggle that always fails
 * is worse than no toggle. Replacement: `php stone generate invitations`
 * scaffolds a platform-owned equivalent into the CONSUMING platform's own
 * repo, orchestrated by the framework-shipped
 * `StoneScriptPHP\Auth\Invitations\InvitationCompletionService`.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth
 */
class DefaultTenantRouteProvider implements TenantRouteProviderInterface
{
    public function register(
        Router $router,
        string $prefix,
        ExternalAuthServiceClient $client,
        ExternalAuthConfig $config,
        mixed $provisioner,
        mixed $rolesResolver,
        mixed $tenantsResolver
    ): void {
        // Public (no-auth) tenant routes.
        if ($config->isEnabled('check_slug')) {
            $router->get("$prefix/check-tenant-slug/{slug}", new CheckTenantSlugRoute($client, $config->hooks, $config), [], true);
            log_debug("DefaultTenantRouteProvider: Registered GET $prefix/check-tenant-slug/{slug}");
        }

        // accept-invite REMOVED 2026-07-21 — see class docblock.

        // Protected (tier-2) tenant routes. These are IDENTITY routes: the caller
        // presents a PASSPORT (purpose=authentication), never a tenant-scoped card —
        // they operate BEFORE / ACROSS tenant selection (select/provision/invite/
        // memberships all resolve against the identity, and memberships proxies the
        // passport straight to the auth service's JWKS-validated endpoint). Typed
        // access=authentication so the 7.x AccessTokenMiddleware admits the passport;
        // a card here would be a purpose mismatch → 403. This mirrors their membership
        // of protectedPaths() (the RequireCardMiddleware "no card needed" exemptions).
        if ($config->isEnabled('select_tenant')) {
            $router->post(
                "$prefix/select-tenant",
                new SelectTenantRoute($client, $config->hooks, $config),
                access: RouteAccess::AUTHENTICATION
            );
            log_debug("DefaultTenantRouteProvider: Registered POST $prefix/select-tenant (protected, authentication)");
        }

        if ($config->isEnabled('provision_tenant')) {
            $router->post(
                "$prefix/provision-tenant",
                new ProvisionTenantRoute($client, $config->hooks, $config, $provisioner),
                access: RouteAccess::AUTHENTICATION
            );
            log_debug("DefaultTenantRouteProvider: Registered POST $prefix/provision-tenant (protected, authentication)");
        }

        // invite-member REMOVED 2026-07-21 — see class docblock.

        if ($config->isEnabled('memberships')) {
            $router->get(
                "$prefix/memberships",
                new MembershipsRoute($client, $config->hooks, $config),
                access: RouteAccess::AUTHENTICATION
            );
            $router->addRoute(
                'PUT',
                "$prefix/memberships/{id}",
                new UpdateMembershipRoute($client, $config->hooks, $config),
                access: RouteAccess::AUTHENTICATION
            );
            log_debug("DefaultTenantRouteProvider: Registered GET $prefix/memberships, PUT $prefix/memberships/{id} (protected, authentication)");
        }
    }

    public function publicPaths(string $prefix, ExternalAuthConfig $config): array
    {
        $paths = [];

        if ($config->isEnabled('check_slug')) {
            $paths[] = "$prefix/check-tenant-slug";
        }
        // accept-invite REMOVED 2026-07-21 — see class docblock.

        return $paths;
    }

    public function protectedPaths(string $prefix, ExternalAuthConfig $config): array
    {
        $paths = [];

        if ($config->isEnabled('select_tenant')) {
            $paths[] = "$prefix/select-tenant";
        }
        if ($config->isEnabled('provision_tenant')) {
            $paths[] = "$prefix/provision-tenant";
        }
        // invite-member REMOVED 2026-07-21 — see class docblock.
        if ($config->isEnabled('memberships')) {
            $paths[] = "$prefix/memberships";
        }

        return $paths;
    }

    public function getRouteDefinitions(string $prefix, array $features): array
    {
        $isEnabled = fn(string $feature) => $features[$feature] ?? false;
        $routes = ['GET' => [], 'POST' => [], 'PUT' => []];

        // accept-invite REMOVED 2026-07-21 — see class docblock.
        if ($isEnabled('check_slug')) {
            $routes['GET']["$prefix/check-tenant-slug/{slug}"] = CheckTenantSlugRoute::class;
        }
        if ($isEnabled('select_tenant')) {
            $routes['POST']["$prefix/select-tenant"] = SelectTenantRoute::class;
        }
        if ($isEnabled('provision_tenant')) {
            $routes['POST']["$prefix/provision-tenant"] = ProvisionTenantRoute::class;
        }
        // invite-member REMOVED 2026-07-21 — see class docblock.
        if ($isEnabled('memberships')) {
            $routes['GET']["$prefix/memberships"] = MembershipsRoute::class;
            $routes['PUT']["$prefix/memberships/{id}"] = UpdateMembershipRoute::class;
        }

        return $routes;
    }
}
