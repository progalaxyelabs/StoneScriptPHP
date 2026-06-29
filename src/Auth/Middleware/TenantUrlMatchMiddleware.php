<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\Middleware;

use StoneScriptPHP\Routing\MiddlewareInterface;
use StoneScriptPHP\ApiResponse;

/**
 * TenantUrlMatchMiddleware
 *
 * Enforces authorization invariant §5.2 of the Tenancy & Identity Model:
 *   "Where a URL carries :tenantId, the server requires
 *    url.tenantId === card.tenant_id — the URL is routing/echo only,
 *    never the authority."
 *
 * The **card token** is the authority. The URL parameter is merely an echo that
 * confirms which resource the client is addressing. If they diverge, the request
 * is rejected — it indicates the client is either confused or attempting to access
 * another tenant's data by manipulating the URL.
 *
 * ## Usage
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
 * ## Preconditions
 *
 * - MUST run AFTER JwtAuthMiddleware / ValidateJwtMiddleware (jwt_claims required)
 * - MUST run AFTER RequireTenantMiddleware (tenant_id claim already verified present)
 * - URL parameter name MUST be registered in the route pattern (e.g. {tenantId})
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
        $claims = $request['jwt_claims'] ?? [];

        // card.tenant_id is the authority (§5.2)
        $cardTenantId = $claims['tenant_id'] ?? null;

        if ($cardTenantId === null) {
            http_response_code(403);
            return new ApiResponse(
                'error',
                'Card token does not carry a tenant context',
                ['error' => 'tenant_context_required'],
                403
            );
        }

        // Read the URL tenant parameter — set by the router in $request['params']
        $urlTenantId = $request['params'][$this->urlParamName] ?? null;

        if ($urlTenantId === null) {
            // Route does not carry the expected param — this middleware is misconfigured.
            // Fail closed: reject rather than silently skip the check.
            http_response_code(500);
            return new ApiResponse(
                'error',
                "TenantUrlMatchMiddleware: URL parameter '{$this->urlParamName}' not found in route params. "
                . "Ensure the route pattern includes {{$this->urlParamName}}.",
                ['error' => 'middleware_misconfigured'],
                500
            );
        }

        // §5.2 — URL tenantId must equal card tenantId
        if ($urlTenantId !== $cardTenantId) {
            http_response_code(403);
            return new ApiResponse(
                'error',
                'URL tenant does not match the card tenant context',
                ['error' => 'tenant_mismatch'],
                403
            );
        }

        return $next($request);
    }
}
