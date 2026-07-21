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
 * `RequireCardMiddleware` fixes this by distinguishing three states:
 *
 *   - **No `jwt_claims` in request** → public route (JwtAuthMiddleware excluded this path
 *     via `publicPaths()`). Pass through — the route is responsible for its own auth.
 *
 *   - **`jwt_claims` present, no `tenant_id`, path is a known tenant-agnostic route**
 *     (§`$tenantAgnosticPaths` below) → intentional: this route takes a passport, never
 *     a card. Pass through.
 *
 *   - **`jwt_claims` present, no `tenant_id`, path is anything else** → authenticated
 *     identity (passport) on a tenant-scoped route → reject 403 (framework-spec.md §6 §5.1).
 *
 *   - **`jwt_claims` present, `tenant_id` set** → valid card → pass through.
 *
 * ## REGRESSION this fixes (real fleet incident, 2026-07-05)
 *
 * Before the third bullet existed, this middleware had ZERO path awareness — it 403'd
 * ANY authenticated-but-tenantless request, including `ExternalAuthRoutes`' own tier-2
 * routes (`provision-tenant`, `select-tenant`, `change-password`, `memberships`, `me` —
 * routes that intentionally take a passport, never a card; `invite-member` was also one
 * of these tier-2 routes at the time of this incident, but was removed 2026-07-21 along
 * with the rest of the framework's invite/accept-invite proxy — see
 * `DefaultTenantRouteProvider`'s class docblock). This
 * was latent on a platform whose `JwtAuthMiddleware` didn't reliably populate
 * `jwt_claims` (a separate, since-fixed bug that accidentally masked it), and broke every
 * new user's first "create organization" call the moment that masking bug was fixed.
 * See `framework-spec.md §5.5` and this file's CHANGELOG entry (v5.8.0) for the full
 * chain. `$tenantAgnosticPaths` closes the gap.
 *
 * ## Usage — RECOMMENDED (since the fix above): `Application::run()` config key
 *
 * `Application::run(['require_card' => ['enabled' => true], ...])` wires this middleware
 * itself, automatically passing `ExternalAuthRoutes::protectedPaths($authRouteOptions)` as
 * `$tenantAgnosticPaths` — the exemption list is derived from the SAME config that defines
 * those routes, so it can never drift out of sync. Prefer this over manual construction.
 *
 * ## Usage — manual construction (legacy; MUST pass the exemption list yourself)
 *
 *   Application::run([
 *       'auth' => [...],
 *       'middleware' => [new RequireCardMiddleware(
 *           StoneScriptPHP\Auth\ExternalAuth\ExternalAuthRoutes::protectedPaths($authOptions)
 *       )],
 *   ]);
 *
 * Constructing this with NO arguments (`new RequireCardMiddleware()`) reproduces the
 * 2026-07-05 regression on any platform that has ANY tier-2 route enabled — do not do this.
 *
 * **T1 platforms (no tenant concept):** Do NOT add this middleware. Passports are valid
 * for all routes on T1 platforms; there is no card model.
 *
 * ## Relationship to RequireTenantMiddleware
 *
 * `RequireCardMiddleware` is a superset: the same §5.1 logic PLUS the public-route
 * pass-through PLUS the tenant-agnostic-route pass-through. Use it as the global
 * middleware; use `RequireTenantMiddleware` directly only when you need strict per-route
 * enforcement where all callers are authenticated.
 *
 * @package StoneScriptPHP\Auth\Middleware
 * @since   5.4.0
 */
class RequireCardMiddleware implements MiddlewareInterface
{
    /**
     * @param array $tenantAgnosticPaths Exact route-pattern strings (matched against
     *   `$request['route']['pattern']`) that are allowed to pass an authenticated
     *   passport (no `tenant_id`) through without rejection. Normally the output of
     *   `ExternalAuthRoutes::protectedPaths($authRouteOptions)` — see class docblock.
     *   Defaults to empty for backward compatibility with existing manual construction;
     *   an empty list reproduces the pre-fix (2026-07-05) behavior, so pass the real list.
     */
    public function __construct(private readonly array $tenantAgnosticPaths = [])
    {
    }

    /**
     * Handle the request.
     *
     * Four outcomes:
     *   1. No `jwt_claims` → public route → pass through.
     *   2. Claims present, no `tenant_id`, path in $tenantAgnosticPaths → pass through.
     *   3. Claims present, no `tenant_id`, path NOT in $tenantAgnosticPaths → 403.
     *   4. Claims present, `tenant_id` set → pass through.
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

        // framework-spec.md §6 §5.1 — a tenant-less token CANNOT authorize a
        // tenant-scoped route. This catches passports accidentally sent to business
        // routes (identity is authenticated, but the token type is wrong) — UNLESS the
        // matched route is a known tenant-agnostic (tier-2) route, which intentionally
        // takes a passport and was never supposed to require a card in the first place.
        if (empty($claims['tenant_id'])) {
            $pattern = $request['route']['pattern'] ?? null;
            if ($pattern !== null && in_array($pattern, $this->tenantAgnosticPaths, true)) {
                return $next($request);
            }

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
