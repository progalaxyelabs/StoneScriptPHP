<?php

namespace StoneScriptPHP\Auth\Middleware;

use StoneScriptPHP\Routing\MiddlewareInterface;
use StoneScriptPHP\ApiResponse;

/**
 * RequireTenantMiddleware
 *
 * Ensures the JWT has a tenant_id claim.
 * This middleware should be used AFTER ValidateJwtMiddleware.
 * Returns 400 if no tenant context is present in the JWT.
 */
class RequireTenantMiddleware implements MiddlewareInterface
{
    public function handle(array $request, callable $next): ?ApiResponse
    {
        // Check if request has JWT claims
        if (!isset($request['jwt_claims']) || empty($request['jwt_claims'])) {
            http_response_code(401);
            return new ApiResponse('error', 'Unauthorized: Authentication required');
        }

        $claims = $request['jwt_claims'];

        // Check if tenant_id claim exists
        if (!isset($claims['tenant_id']) || empty($claims['tenant_id'])) {
            http_response_code(400);
            return new ApiResponse('error', 'Bad Request: No tenant context');
        }

        // Continue to next middleware
        return $next($request);
    }
}
