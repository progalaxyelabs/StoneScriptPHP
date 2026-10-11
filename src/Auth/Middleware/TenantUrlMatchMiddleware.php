<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\Middleware;

use StoneScriptPHP\Routing\MiddlewareInterface;
use StoneScriptPHP\Routing\RouteAccess;
use StoneScriptPHP\ApiResponse;

/**
 * TenantUrlMatchMiddleware
 *
 * Enforces authorization invariant §5.2 of the Tenancy & Identity Model:
 *   "Where a URL carries :tenantId, the server requires
 *    url.tenantId === card.tenant_id — the URL is routing/echo only,
 *    never the authority."
 *
 * The **API token** is the authority. The URL parameter is merely an echo that
 * confirms which resource the client is addressing. If they diverge, the request
 * is rejected — it indicates the client is either confused or attempting to access
 * another tenant's data by manipulating the URL.
 *
 * ## Usage — per-route
 *
 * Apply per-route or per-group on any route whose URL carries a tenant identifier:
 *
 *   $router->get('/api/tenants/{tenantId}/orders', $handler, [
 *       new JwtAuthMiddleware(...),
 *       new TenantUrlMatchMiddleware('tenantId'),   // URL param name
 *   ]);
 *
 * The constructor accepts the name of the URL parameter that holds the tenant ID
 * (default: 'tenantId'). The framework stores matched URL params in
 * $request['params'] after routing.
 *
 * ## Usage — GLOBAL (framework-spec.md §5.2 gap closure, since 5.7.0)
 *
 * This middleware is now SELF-SKIPPING: it inspects the matched route's PATTERN
 * (`$request['route']['pattern']`, set by `Router::dispatch()`) rather than
 * blindly requiring the URL param to be present. If the pattern does not declare
 * `{tenantId}` (or whatever `$urlParamName` is configured as) as a path segment,
 * the route is treated as non-tenant-scoped and passed through — no 500.
 *
 * This makes it safe to wire GLOBALLY via `Application::run()`:
 *
 *   Application::run([
 *       ...
 *       'tenant_url_match' => ['enabled' => true, 'param' => 'tenantId'],
 *   ]);
 *
 * Flat/non-tenant routes (health, webhooks, admin auth, infra) simply don't
 * carry `{tenantId}` in their pattern and are skipped automatically — mirroring
 * how `StoreAccessMiddleware` self-skips non-tenant-scoped routes by pattern.
 *
 * ## Public routes (since 12.2.0)
 *
 * A route declared `access => public` (or legacy `is_public => true`) carries no tenant
 * claim to match: the caller is, by definition, not yet acting inside a tenant (e.g. an
 * invitee previewing/accepting an invitation by an unguessable token). The guard therefore
 * passes such routes through untouched, whether or not a token is attached — a token a
 * caller happens to hold for tenant A must not block a public flow addressed at tenant B.
 * The URL tenant id on a public route is NOT authoritative; the handler must authorise by
 * its own credential (e.g. the invitation token) and never trust the URL id as identity.
 *
 * Every non-public route (`authentication`, `authorization`) is still enforced and fails
 * closed. A request with no matched route metadata (`$request['route']` absent `access` and
 * `is_public`) is treated as non-public. The check runs before the handler, so a rejected
 * request has no handler side effect.
 *
 * ## Preconditions
 *
 * - MUST run AFTER JwtAuthMiddleware / ValidateJwtMiddleware (jwt_claims required)
 * - MUST run AFTER RequireTenantMiddleware (tenant_id claim already verified present)
 *   when wired per-route; when wired globally it fails closed with 403
 *   tenant_context_required for any tenant-scoped route hit with a tenant-less token,
 *   so RequireTenantMiddleware is not strictly required upstream.
 * - MUST run AFTER routing (needs `$request['route']['pattern']` — always true when
 *   wired via `Router::use()` / `Application::run()`, since the pipeline only runs
 *   post-match).
 */
class TenantUrlMatchMiddleware implements MiddlewareInterface
{
    /**
     * @param string $urlParamName The route parameter name holding the tenant ID (default 'tenantId')
     */
    public function __construct(private readonly string $urlParamName = 'tenantId')
    {
    }

    public function handle(array $request, callable $next): ?ApiResponse
    {
        // Self-skip: only enforce on routes whose PATTERN actually declares the
        // tenant param as a path segment (e.g. "/portal/tenant/{tenantId}/...").
        // Routes without it (health, webhooks, admin auth, non-tenant infra) are
        // not tenant-scoped and pass through untouched — this is what makes the
        // middleware safe to wire globally (see class docblock).
        $pattern = $request['route']['pattern'] ?? null;
        if ($pattern === null || !str_contains($pattern, '{' . $this->urlParamName . '}')) {
            return $next($request);
        }

        // Public routes have no tenant claim to match — pass through (see class docblock).
        // Absence of metadata is NOT treated as public: fail closed.
        if (RouteAccess::isPublicRoute($request['route'] ?? [])) {
            return $next($request);
        }

        $claims = $request['jwt_claims'] ?? [];

        // card.tenant_id is the authority (§5.2)
        $apiTokenTenantId = $claims['tenant_id'] ?? null;

        if ($apiTokenTenantId === null) {
            http_response_code(403);
            return new ApiResponse(
                'error',
                'API token does not carry a tenant context',
                ['error' => 'tenant_context_required'],
                403
            );
        }

        // Read the URL tenant parameter — set by the router in $request['params']
        $urlTenantId = $request['params'][$this->urlParamName] ?? null;

        if ($urlTenantId === null || $urlTenantId === '') {
            // The route pattern DOES declare {tenantId} (checked above) but the router
            // failed to resolve it into $request['params'] — a genuine bug (param-name
            // drift, routing regex mismatch, etc.), not an unrelated flat route.
            // Fail closed: reject rather than silently skip the check.
            http_response_code(500);
            return new ApiResponse(
                'error',
                "TenantUrlMatchMiddleware: URL parameter '{$this->urlParamName}' not found in route params "
                . "despite pattern '{$pattern}' declaring it. Router/param-name mismatch.",
                ['error' => 'middleware_misconfigured'],
                500
            );
        }

        // §5.2 — URL tenantId must equal API-token tenantId
        if ($urlTenantId !== $apiTokenTenantId) {
            http_response_code(403);
            return new ApiResponse(
                'error',
                'URL tenant does not match the API token tenant context',
                ['error' => 'tenant_mismatch'],
                403
            );
        }

        return $next($request);
    }
}
