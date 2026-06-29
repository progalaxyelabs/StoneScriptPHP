<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\Middleware;

use StoneScriptPHP\Routing\MiddlewareInterface;
use StoneScriptPHP\ApiResponse;

/**
 * RequireTenantMiddleware
 *
 * Enforces the authorization invariant for tenant-scoped routes (framework-spec.md §6 §5.1):
 *   "A tenant-less token MUST be rejected on any tenant-scoped route."
 *
 * In the passport/card model:
 *   - A **passport** (identity JWT, no tenant_id) is rejected here — the client
 *     must call POST /api/auth/exchange to obtain a **card** first.
 *   - A **card** carries tenant_id and passes this middleware.
 *
 * This middleware MUST run AFTER JwtAuthMiddleware / ValidateJwtMiddleware so that
 * jwt_claims are already in the request.
 *
 * Response on failure: 403 Forbidden (not 401 — the identity is authenticated,
 * but the token type is wrong for this route).
 *
 * Machine-readable error: { "error": "tenant_context_required" }
 */
class RequireTenantMiddleware implements MiddlewareInterface
{
    public function handle(array $request, callable $next): ?ApiResponse
    {
        // Check if request has JWT claims (set by ValidateJwtMiddleware / JwtAuthMiddleware)
        if (!isset($request['jwt_claims']) || empty($request['jwt_claims'])) {
            http_response_code(401);
            return new ApiResponse('error', 'Unauthorized: Authentication required', null, 401);
        }

        $claims = $request['jwt_claims'];

        // framework-spec.md §6 §5.1 — reject passport/tenant-less tokens on tenant-scoped routes.
        if (empty($claims['tenant_id'])) {
            http_response_code(403);
            return new ApiResponse(
                'error',
                'A card token is required for this route. Obtain one via POST /api/auth/exchange.',
                ['error' => 'tenant_context_required'],
                403
            );
        }

        return $next($request);
    }
}
