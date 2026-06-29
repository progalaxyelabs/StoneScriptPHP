<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\Middleware;

use StoneScriptPHP\Routing\MiddlewareInterface;
use StoneScriptPHP\ApiResponse;

/**
 * RequireCardMiddleware — card-model tenant enforcement with public-route pass-through.
 *
 * ## Why this middleware exists
 *
 * `RequireTenantMiddleware` (§5.1 enforcement) returns 401 when `jwt_claims` is absent.
 * This is CORRECT for a pure authenticated route, but it blocks the exchange endpoint
 * (`POST /api/auth/exchange`) — which is intentionally public (the inbound passport is
 * validated by the route itself, not by JwtAuthMiddleware).
 *
 * Wiring `RequireTenantMiddleware` globally would self-block exchange. Platforms worked
 * around this by NOT wiring any global tenant check, leaving business routes unprotected.
 *
 * `RequireCardMiddleware` fixes this by distinguishing two states:
 *
 *   - **No `jwt_claims` in request** → public route (JwtAuthMiddleware excluded this path
 *     via `publicPaths()`). Pass through — the route is responsible for its own auth.
 *
 *   - **`jwt_claims` present, no `tenant_id`** → authenticated identity (passport) on a
 *     tenant-scoped route → reject 403 (TENANCY-IDENTITY-MODEL §5.1).
 *
 *   - **`jwt_claims` present, `tenant_id` set** → valid card → pass through.
 *
 * ## Usage (TENANCY-IDENTITY-MODEL §5.1, multi-tenant platforms)
 *
 * Add to the `middleware` array in `Application::run()` config, AFTER `JwtAuthMiddleware`
 * and `GatewayTenantMiddleware`:
 *
 *   Application::run([
 *       'auth' => [...],
 *       'middleware' => [new RequireCardMiddleware()],
 *   ]);
 *
 * **T1 platforms (no tenant concept):** Do NOT add this middleware. Passports are valid
 * for all routes on T1 platforms; there is no card model.
 *
 * ## Relationship to RequireTenantMiddleware
 *
 * `RequireCardMiddleware` is a superset: the same §5.1 logic PLUS the public-route
 * pass-through. Use it as the global middleware; use `RequireTenantMiddleware` directly
 * only when you need strict per-route enforcement where all callers are authenticated.
 *
 * @package StoneScriptPHP\Auth\Middleware
 * @since   5.4.0
 */
class RequireCardMiddleware implements MiddlewareInterface
{
    /**
     * Handle the request.
     *
     * Three outcomes:
     *   1. No `jwt_claims` → public route → pass through.
     *   2. Claims present, no `tenant_id` → 403 tenant_context_required.
     *   3. Claims present, `tenant_id` set → pass through.
     */
    public function handle(array $request, callable $next): ?ApiResponse
    {
        // Empty claims = public route (JwtAuthMiddleware excluded this path).
        // Exchange, login, register, OAuth callback, etc. fall here — let them handle
        // their own auth logic. Do NOT return 401; the caller already excluded the path.
        if (empty($request['jwt_claims'])) {
            return $next($request);
        }

        $claims = $request['jwt_claims'];

        // TENANCY-IDENTITY-MODEL §5.1 — a tenant-less token CANNOT authorize a
        // tenant-scoped route. This catches passports accidentally sent to business
        // routes (identity is authenticated, but the token type is wrong).
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
