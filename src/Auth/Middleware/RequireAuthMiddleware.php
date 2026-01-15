<?php

namespace StoneScriptPHP\Auth\Middleware;

use StoneScriptPHP\Routing\MiddlewareInterface;
use StoneScriptPHP\ApiResponse;

/**
 * RequireAuthMiddleware
 *
 * Ensures the request has validated JWT claims.
 * This middleware should be used AFTER ValidateJwtMiddleware.
 * Returns 401 if the request does not have validated claims.
 */
class RequireAuthMiddleware implements MiddlewareInterface
{
    public function handle(array $request, callable $next): ?ApiResponse
    {
        // Check if request has validated JWT claims
        if (!isset($request['jwt_claims']) || empty($request['jwt_claims'])) {
            http_response_code(401);
            return new ApiResponse('error', 'Unauthorized: Authentication required');
        }

        // Continue to next middleware
        return $next($request);
    }
}
