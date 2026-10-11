<?php

declare(strict_types=1);

namespace StoneScriptPHP\Routing\Middleware;

use StoneScriptPHP\Routing\MiddlewareInterface;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Database;
use StoneScriptPHP\Tenancy\TenancyStrategyInterface;
use StoneScriptPHP\Tenancy\NoTenantStrategy;

/**
 * GatewayTenantMiddleware
 *
 * Propagates the API token's authorization context to the StoneScriptDB gateway
 * so that every Database::fn() call within the request runs in the correct tenant
 * scope.
 *
 * Per the Tenancy & Identity Model §3:
 *   "The middleware exposes identity_id + tenant_id + role_id to the
 *    gateway/SQL layer for the whole request."
 *
 * ### What this middleware sets today (v5.3)
 *
 * - **tenant_id** → gateway client `setTenantId()` — routes all DB calls to the
 *   tenant's database schema. This is the primary gate.
 *
 * ### Deferred (§5.4 sweep — future gateway client release)
 *
 * The gateway client does not yet expose a per-request custom-header API.
 * Forwarding `identity_id` and `role_id` to the SQL layer (for audit trails and
 * optional role assertions inside PL/pgSQL functions) is tracked as part of the
 * §5.4 defense-in-depth sweep and requires a gateway client update. Until then,
 * `identity_id` and `role_id` are carried in `AuthenticatedUser` (via `auth()`)
 * and are accessible to route handlers for PHP-level enforcement.
 *
 * MUST run AFTER JwtAuthMiddleware in the middleware chain so that `auth()` returns
 * a populated `AuthenticatedUser`. Gateway context is set only when tenant_id is
 * present (i.e. the token is an API token, not an auth token).
 *
 * Usage:
 *   $router->use(new JwtAuthMiddleware($jwtHandler, $excludedPaths))
 *          ->use(new GatewayTenantMiddleware());
 *
 * ### Pluggable tenancy resolution (Phase 1 extensibility)
 *
 * `tenant_id` resolution is delegated to a `TenancyStrategyInterface` (default:
 * `NoTenantStrategy`, which reproduces the exact behavior above —
 * `$user->tenant_id ?? null`, nothing more). Pass a different strategy to
 * change how tenant_id is resolved (e.g. a future multi-tenancy plugin) without
 * modifying this class:
 *
 *   new GatewayTenantMiddleware($myTenancyStrategy)
 *
 * Omitting the constructor argument (the pre-Phase-1 call site,
 * `new GatewayTenantMiddleware()`) is unaffected — it still defaults to
 * `NoTenantStrategy` and behaves identically to every prior release.
 */
class GatewayTenantMiddleware implements MiddlewareInterface
{
    private TenancyStrategyInterface $strategy;

    public function __construct(?TenancyStrategyInterface $strategy = null)
    {
        $this->strategy = $strategy ?? new NoTenantStrategy();
    }

    public function handle(array $request, callable $next): ?ApiResponse
    {
        $user = auth();
        $tenantId = $this->strategy->resolveTenantId($request, $user);

        if ($tenantId !== null && $tenantId !== '') {
            // Route subsequent DB calls to this tenant's database — but only
            // when the active transport IS the gateway. DB_MODE=direct/pgandroid
            // have no "physical tenant database" concept to route to (see
            // Database::isGatewayMode()'s docblock). This middleware is wired
            // unconditionally by Application::run() regardless of DB_MODE, so it
            // must ask before touching the gateway client.
            if (Database::isGatewayMode()) {
                // A tenant id arrived (token claim / URL) but tenant routing is not configured: answer with the
                // variable name now, not an opaque failure at the first query.
                if (trim((string) \StoneScriptPHP\Env::get_instance()->DB_GATEWAY_TENANT_SCHEMA_NAME) === '') {
                    log_error('GatewayTenantMiddleware: request carries tenant_id but DB_GATEWAY_TENANT_SCHEMA_NAME is not set.');
                    return new ApiResponse(
                        'error',
                        'Tenant routing is not configured on this server (DB_GATEWAY_TENANT_SCHEMA_NAME).',
                        null,
                        500
                    );
                }
                Database::getGatewayClient()->setTenantId((string) $tenantId);
                log_debug('GatewayTenantMiddleware: tenant_id set to ' . $tenantId);
            } else {
                log_debug('GatewayTenantMiddleware: tenant_id=' . $tenantId
                    . ' present but transport is not gateway-mode — skipping '
                    . 'setTenantId() (no gateway-specific tenant routing concept '
                    . 'for this DB_MODE, see Database::isGatewayMode()).');
            }

            // identity_id and role_id are on $user (via auth()) and available to route
            // handlers. SQL-layer forwarding is deferred to the §5.4 sweep.
            if ($user && $user->user_id) {
                log_debug('GatewayTenantMiddleware: identity_id=' . $user->user_id
                    . ' role_id=' . ($user->role_id ?? '(none)'));
            }
        }

        return $next($request);
    }
}
