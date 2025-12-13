<?php

namespace Framework\Http\Middleware;

use Framework\Tenancy\TenantResolver;
use Framework\Tenancy\TenantContext;
use Framework\ApiResponse;

/**
 * Tenant Middleware
 *
 * Automatically resolves the current tenant from the request and sets it in TenantContext.
 * Supports path exclusion for public endpoints that don't require tenant context.
 *
 * Usage:
 *   $resolver = new TenantResolver($authDb, ['jwt', 'header', 'subdomain']);
 *   $router->use(new TenantMiddleware($resolver, ['/api/health', '/api/public/*']));
 */
class TenantMiddleware implements IMiddleware
{
    /**
     * Create a new TenantMiddleware
     *
     * @param TenantResolver $resolver Tenant resolver instance
     * @param array $excludedPaths Paths that don't require tenant resolution (supports wildcards)
     * @param bool $required Whether tenant is required (returns 404 if not found)
     */
    public function __construct(
        private TenantResolver $resolver,
        private array $excludedPaths = [],
        private bool $required = false
    ) {}

    /**
     * Handle the request
     *
     * @param array $request
     * @param callable $next
     * @return ApiResponse|null
     */
    public function handle(array $request, callable $next): ?ApiResponse
    {
        $path = $request['path'] ?? $request['uri'] ?? '/';

        // Skip excluded paths
        if ($this->isExcluded($path)) {
            return $next($request);
        }

        // Resolve tenant
        $tenant = $this->resolver->resolve($request);

        // Handle missing tenant
        if (!$tenant) {
            if ($this->required) {
                return new ApiResponse(
                    'error',
                    'Tenant not found or not specified',
                    null,
                    404
                );
            }

            // Tenant is optional, continue without tenant context
            return $next($request);
        }

        // Set global tenant context
        TenantContext::setTenant($tenant);

        // Add tenant to request for easy access in route handlers
        $request['tenant'] = $tenant;

        // Process request
        $response = $next($request);

        // Clear tenant context after response
        // This is important to prevent context leaking between requests
        TenantContext::clear();

        return $response;
    }

    /**
     * Check if path is excluded from tenant resolution
     *
     * Supports exact matches and wildcard patterns:
     * - /api/health (exact match)
     * - /api/public/* (wildcard match)
     *
     * @param string $path Request path
     * @return bool
     */
    private function isExcluded(string $path): bool
    {
        foreach ($this->excludedPaths as $excludedPath) {
            // Exact match
            if ($excludedPath === $path) {
                return true;
            }

            // Wildcard match
            if (str_ends_with($excludedPath, '/*')) {
                $prefix = substr($excludedPath, 0, -2);
                if (str_starts_with($path, $prefix)) {
                    return true;
                }
            }

            // Wildcard in the middle or start
            if (str_contains($excludedPath, '*')) {
                $pattern = str_replace('*', '.*', preg_quote($excludedPath, '/'));
                if (preg_match('/^' . $pattern . '$/', $path)) {
                    return true;
                }
            }
        }

        return false;
    }
}
