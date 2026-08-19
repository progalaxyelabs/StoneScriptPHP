<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth;

use StoneScriptPHP\Routing\Router;

/**
 * TenantRouteProviderInterface
 *
 * Phase 1 extensibility seam: the subset of ExternalAuth routes that are
 * specifically about the TENANT entity (creating/selecting/joining a tenant,
 * tenant slug lookup, tenant memberships) rather than pure identity (login,
 * logout, password reset, profile). `ExternalAuthRoutes` no longer hard-`use`
 * -imports these route classes directly — it delegates both registration AND
 * the RequireApiTokenMiddleware exemption-path computation to a single provider
 * implementing this interface.
 *
 * ## Why registration and exemption-path computation live together
 *
 * `RequireApiTokenMiddleware`'s tenant-agnostic exemption list is derived from
 * `ExternalAuthRoutes::protectedPaths()` (see that method's docblock and the
 * 2026-07-05 fleet incident it fixes: a bare `RequireApiTokenMiddleware` 403s any
 * authenticated-but-tenant-less request, including these tier-2 routes, unless
 * they're explicitly exempted). If route REGISTRATION and exemption-list
 * COMPUTATION ever live in different places, they can silently drift apart —
 * a route added to one and not the other reopens that exact incident. Bundling
 * both `register()` and `protectedPaths()` into one interface makes that
 * drift structurally impossible: a plugin author replacing
 * `DefaultTenantRouteProvider` must implement both together, in the same
 * review, in the same class.
 *
 * ## Phase 1 vs Phase 2
 *
 * Today (Phase 1), `ExternalAuthConfig` defaults `tenantRouteProvider` to
 * `DefaultTenantRouteProvider` — the exact same routes register under the
 * exact same conditions as before this refactor. Nothing is removed or moved
 * out of the base framework yet. Phase 2 can later ship these tenant routes as
 * an opt-in plugin package that supplies its own `TenantRouteProviderInterface`
 * implementation via `ExternalAuthConfig`/`ExternalAuthRoutes::register()`
 * options — a clean extraction point, not a rewrite.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth
 */
interface TenantRouteProviderInterface
{
    /**
     * Register this provider's tenant routes on $router under $prefix.
     *
     * Mirrors `ExternalAuthRoutes::registerForPrefix()`'s call signature so a
     * provider can construct route handler instances using the same
     * dependencies the framework already builds once per prefix.
     *
     * @param Router $router
     * @param string $prefix
     * @param ExternalAuthServiceClient $client
     * @param ExternalAuthConfig $config
     * @param mixed $provisioner Tenant provisioner (for provision-tenant), or null.
     * @param callable|null $rolesResolver fn(array $claimsWithTenant): string[]
     * @param callable|null $tenantsResolver fn(array $authClaims): array[]
     */
    public function register(
        Router $router,
        string $prefix,
        ExternalAuthServiceClient $client,
        ExternalAuthConfig $config,
        mixed $provisioner,
        mixed $rolesResolver,
        mixed $tenantsResolver
    ): void;

    /**
     * Public (no-auth) path strings this provider registers under $prefix —
     * merged into `ExternalAuthRoutes::publicPaths()`.
     *
     * @return string[]
     */
    public function publicPaths(string $prefix, ExternalAuthConfig $config): array;

    /**
     * Protected (tier-2, identity-required-but-tenant-agnostic) path strings
     * this provider registers under $prefix — merged into
     * `ExternalAuthRoutes::protectedPaths()`, the single source of truth for
     * `RequireApiTokenMiddleware`'s exemption list. MUST stay in sync with
     * `register()` — see class docblock.
     *
     * @return string[]
     */
    public function protectedPaths(string $prefix, ExternalAuthConfig $config): array;

    /**
     * Route definitions for the client generator (`ExternalAuthRoutes::getRouteDefinitions()`),
     * in the same shape Router::loadRoutes() / the generator expect:
     * `['GET' => ['/path' => ['handler' => HandlerClass::class, 'group' => ..., 'response' => ..., ...]], 'POST' => [...], ...]`.
     * Router::normalizeRouteConfig() also accepts a bare `HandlerClass::class`
     * string per route (pre-v9.6.0 shape) — both forms are valid values here.
     *
     * Takes a plain feature-toggle array (not ExternalAuthConfig) because the
     * caller deliberately avoids instantiating ExternalAuthConfig here — it
     * requires Env/database config the client generator doesn't have.
     *
     * @param string $prefix
     * @param array<string, bool> $features Feature toggle map (same keys as
     *   ExternalAuthConfig's feature toggles: select_tenant, provision_tenant,
     *   invite, memberships, check_slug, accept_invite, ...).
     * @return array<string, array<string, string|array<string, mixed>>>
     */
    public function getRouteDefinitions(string $prefix, array $features): array;
}
