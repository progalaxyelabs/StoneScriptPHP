<?php

declare(strict_types=1);

namespace StoneScriptPHP\Routing\Middleware;

use StoneScriptPHP\Routing\MiddlewareInterface;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Database;

/**
 * GatewayTenantMiddleware
 *
 * Propagates the card token's authorization context to the StoneScriptDB gateway
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
 * present (i.e. the token is a card, not a passport).
 *
 * Usage:
 *   $router->use(new JwtAuthMiddleware($jwtHandler, $excludedPaths))
 *          ->use(new GatewayTenantMiddleware());
 */
class GatewayTenantMiddleware implements MiddlewareInterface
{
    public function handle(array $request, callable $next): ?ApiResponse
    {
        $user = auth();

        if ($user && $user->tenant_id) {
            // Route subsequent DB calls to this tenant's database.
            Database::getGatewayClient()->setTenantId((string) $user->tenant_id);
            log_debug('GatewayTenantMiddleware: tenant_id set to ' . $user->tenant_id);

            // identity_id and role_id are on $user (via auth()) and available to route
            // handlers. SQL-layer forwarding is deferred to the §5.4 sweep.
            if ($user->user_id) {
                log_debug('GatewayTenantMiddleware: identity_id=' . $user->user_id
                    . ' role_id=' . ($user->role_id ?? '(none)'));
            }
        }

        return $next($request);
    }
}
