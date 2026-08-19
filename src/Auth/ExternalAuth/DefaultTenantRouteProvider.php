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
use StoneScriptPHP\Auth\ExternalAuth\Dto\LoginResponseDto;
use StoneScriptPHP\Auth\ExternalAuth\Dto\ProvisionTenantResponseDto;
use StoneScriptPHP\Auth\ExternalAuth\Dto\MembershipsResponseDto;

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
 * REMOVED (real, live-breaking fix): `invite-member`
 * and `accept-invite` used to be registered here, proxying to
 * `POST /api/auth/invite` / `POST /api/auth/accept-invite` on the auth
 * service. Those auth-service endpoints are gone — the invitation system
 * moved fully platform-side (auth owns zero invitation data, by design).
 * Every platform still on framework
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
        //
        // KNOWN BUG (found during v9.6.0 typing pass, not fixed here — out of
        // scope for a typing change): CheckTenantSlugRoute proxies to
        // ExternalAuthServiceClient::checkTenantSlug(), which GETs
        // `/api/auth/check-tenant-slug/{slug}` — a live audit found this path
        // does not exist on the configured external auth service. Any
        // platform with check_slug enabled is calling a 404. No `response:`
        // DTO — see ExternalAuthRoutes::registerForPrefix()'s matching notes
        // for the other endpoints this same audit found dead.
        if ($config->isEnabled('check_slug')) {
            $router->get(
                "$prefix/check-tenant-slug/{slug}",
                new CheckTenantSlugRoute($client, $config->hooks, $config),
                middleware: [],
                isPublic: true,
                group: 'auth',
            );
            log_debug("DefaultTenantRouteProvider: Registered GET $prefix/check-tenant-slug/{slug}");
        }

        // accept-invite REMOVED 2026-07-21 — see class docblock.

        // Protected (tier-2) tenant routes. These are IDENTITY routes: the caller
        // presents an AUTH TOKEN (purpose=authentication), never a tenant-scoped API
        // token — they operate BEFORE / ACROSS tenant selection (select/provision/invite/
        // memberships all resolve against the identity, and memberships proxies the
        // auth token straight to the auth service's JWKS-validated endpoint). Typed
        // access=authentication so the 7.x AccessTokenMiddleware admits the auth token;
        // an API token here would be a purpose mismatch → 403. This mirrors their membership
        // of protectedPaths() (the RequireApiTokenMiddleware "no API token needed" exemptions).
        if ($config->isEnabled('select_tenant')) {
            $router->post(
                "$prefix/select-tenant",
                new SelectTenantRoute($client, $config->hooks, $config),
                group: 'auth',
                response: LoginResponseDto::class,
                access: RouteAccess::AUTHENTICATION
            );
            log_debug("DefaultTenantRouteProvider: Registered POST $prefix/select-tenant (protected, authentication)");
        }

        if ($config->isEnabled('provision_tenant')) {
            // $config->provisionTenantRouteClass defaults to ProvisionTenantRoute::class —
            // see that property's docblock (ExternalAuthConfig.php) for why a
            // platform-specific subclass is substituted HERE, at the one place that
            // already has real $client/$hooks/$config/$provisioner in scope, rather
            // than via routes.php (chronologically impossible — routes.php evaluates
            // before this method ever runs).
            //
            // ProvisionTenantResponseDto describes THIS class's response shape.
            // A platform-specific subclass that overrides process() to build a
            // different response should register its OWN `response:` DTO on its
            // own routes.php entry rather than relying on this default.
            $routeClass = $config->provisionTenantRouteClass;
            $router->post(
                "$prefix/provision-tenant",
                new $routeClass($client, $config->hooks, $config, $provisioner),
                group: 'auth',
                response: $routeClass === ProvisionTenantRoute::class ? ProvisionTenantResponseDto::class : null,
                access: RouteAccess::AUTHENTICATION
            );
            log_debug("DefaultTenantRouteProvider: Registered POST $prefix/provision-tenant "
                . ($routeClass === ProvisionTenantRoute::class ? '(protected, authentication)' : "(protected, authentication, class=$routeClass)"));
        }

        // invite-member REMOVED 2026-07-21 — see class docblock.

        if ($config->isEnabled('memberships')) {
            $router->get(
                "$prefix/memberships",
                new MembershipsRoute($client, $config->hooks, $config),
                group: 'auth',
                response: MembershipsResponseDto::class,
                access: RouteAccess::AUTHENTICATION
            );
            // KNOWN BUG (found during v9.6.0 typing pass, not fixed here — out
            // of scope for a typing change): UpdateMembershipRoute::process()
            // calls `$this->client->updateMembership(...)`, a method that no
            // longer exists on ExternalAuthServiceClient (removed — see that
            // client class's docblock, "inviteMember() and updateMembership()
            // were REMOVED"). Calling this route as registered below would be
            // a PHP fatal error (undefined method call). It is not exposed
            // through any `getRouteDefinitions()`/feature-toggle path a
            // platform would discover by following the documented config
            // surface, so it appears unreachable in practice — but the class
            // is dead code that will fatal if ever wired up. No `response:`
            // DTO — inventing one for a route that cannot successfully run
            // would misrepresent it as working.
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

        // v9.6.0: full array-config format — mirrors register()'s runtime
        // wiring. See ExternalAuthRoutes::getRouteDefinitions() for the
        // rationale and the KNOWN BUG notes for check_slug/updateMembership.
        // accept-invite REMOVED 2026-07-21 — see class docblock.
        if ($isEnabled('check_slug')) {
            $routes['GET']["$prefix/check-tenant-slug/{slug}"] = ['handler' => CheckTenantSlugRoute::class, 'group' => 'auth', 'is_public' => true];
        }
        if ($isEnabled('select_tenant')) {
            $routes['POST']["$prefix/select-tenant"] = ['handler' => SelectTenantRoute::class, 'group' => 'auth', 'access' => 'authentication', 'response' => LoginResponseDto::class];
        }
        if ($isEnabled('provision_tenant')) {
            $routes['POST']["$prefix/provision-tenant"] = ['handler' => ProvisionTenantRoute::class, 'group' => 'auth', 'access' => 'authentication', 'response' => ProvisionTenantResponseDto::class];
        }
        // invite-member REMOVED 2026-07-21 — see class docblock.
        if ($isEnabled('memberships')) {
            $routes['GET']["$prefix/memberships"] = ['handler' => MembershipsRoute::class, 'group' => 'auth', 'access' => 'authentication', 'response' => MembershipsResponseDto::class];
            // UpdateMembershipRoute — no response DTO, see register()'s KNOWN BUG note.
            $routes['PUT']["$prefix/memberships/{id}"] = ['handler' => UpdateMembershipRoute::class, 'group' => 'auth', 'access' => 'authentication'];
        }

        return $routes;
    }
}
