<?php

namespace StoneScriptPHP\Routing\Middleware;

use StoneScriptPHP\Routing\MiddlewareInterface;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Database;

/**
 * Gateway Tenant Middleware
 *
 * Sets the tenant_id on the gateway client from the JWT auth context.
 * Must run AFTER JwtAuthMiddleware in the middleware chain.
 *
 * When a user has a tenant_id in their JWT claims, all subsequent
 * Database::fn() calls will route to that tenant's database via the gateway.
 * If no tenant_id is present, calls route to the platform's main database.
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
            Database::getGatewayClient()->setTenantId((string) $user->tenant_id);
            log_debug('GatewayTenantMiddleware: tenant_id set to ' . $user->tenant_id);
        }

        return $next($request);
    }
}
