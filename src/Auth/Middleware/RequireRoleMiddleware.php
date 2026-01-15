<?php

namespace StoneScriptPHP\Auth\Middleware;

use StoneScriptPHP\Routing\MiddlewareInterface;
use StoneScriptPHP\ApiResponse;

/**
 * RequireRoleMiddleware
 *
 * Checks if the JWT role claim is in the allowed roles list.
 * This middleware should be used AFTER ValidateJwtMiddleware.
 * Returns 403 if the user does not have sufficient role permissions.
 */
class RequireRoleMiddleware implements MiddlewareInterface
{
    private array $allowedRoles;

    /**
     * @param array $allowedRoles List of roles that are allowed to access the resource
     */
    public function __construct(array $allowedRoles)
    {
        $this->allowedRoles = $allowedRoles;
    }

    public function handle(array $request, callable $next): ?ApiResponse
    {
        // Check if request has JWT claims
        if (!isset($request['jwt_claims']) || empty($request['jwt_claims'])) {
            http_response_code(401);
            return new ApiResponse('error', 'Unauthorized: Authentication required');
        }

        $claims = $request['jwt_claims'];

        // Check if role claim exists
        if (!isset($claims['role'])) {
            http_response_code(403);
            return new ApiResponse('error', 'Forbidden: Insufficient permissions');
        }

        // Check if user's role is in the allowed list
        if (!in_array($claims['role'], $this->allowedRoles, true)) {
            http_response_code(403);
            return new ApiResponse('error', 'Forbidden: Insufficient role permissions');
        }

        // Continue to next middleware
        return $next($request);
    }
}
