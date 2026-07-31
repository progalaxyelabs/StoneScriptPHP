<?php

namespace StoneScriptPHP\Auth\Middleware;

use StoneScriptPHP\Routing\MiddlewareInterface;
use StoneScriptPHP\ApiResponse;

/**
 * RequireRoleMiddleware
 *
 * Checks the card's active role against an allowed-roles list.
 * This middleware should be used AFTER ValidateJwtMiddleware/JwtAuthMiddleware.
 * Returns 403 if the user does not have sufficient role permissions.
 *
 * FIXED (found while working on a related auth role-ownership change —
 * adjacent to it, not caused by it): this previously checked `$claims['role']`, but every card
 * this framework mints (TokenExchangeService::exchangeCard()) stamps
 * `role_id`, never `role` — see AuthenticatedUser's own docblock, which
 * documents the same card/passport claim contract. The old check meant ANY
 * card holder using this middleware got an unconditional 403 (the "role
 * claim exists" check always failed), regardless of their actual role. Now
 * checks `role_id` (falling back to the legacy `user_role` alias for
 * non-card token shapes) — matching AuthenticatedUser::fromPayload()'s own
 * resolution order.
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

        // Card model: role_id is the canonical claim; user_role is the
        // documented legacy alias for non-card token shapes. Never `role` —
        // see class docblock.
        $activeRole = $claims['role_id'] ?? $claims['user_role'] ?? null;

        if ($activeRole === null) {
            http_response_code(403);
            return new ApiResponse('error', 'Forbidden: Insufficient permissions');
        }

        // Check if user's role is in the allowed list
        if (!in_array($activeRole, $this->allowedRoles, true)) {
            http_response_code(403);
            return new ApiResponse('error', 'Forbidden: Insufficient role permissions');
        }

        // Continue to next middleware
        return $next($request);
    }
}
