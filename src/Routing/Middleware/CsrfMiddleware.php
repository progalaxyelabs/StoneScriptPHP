<?php

namespace Framework\Routing\Middleware;

use Framework\Routing\MiddlewareInterface;
use Framework\ApiResponse;
use Framework\Security\CsrfTokenHandler;

/**
 * CSRF Protection Middleware
 *
 * Validates CSRF tokens on public routes to prevent:
 * - Bot spam (automated registrations)
 * - CSRF attacks
 * - API abuse
 *
 * Usage:
 * $router->use(new CsrfMiddleware([
 *     '/api/auth/register',
 *     '/api/auth/login',
 *     '/api/contact/submit'
 * ]));
 */
class CsrfMiddleware implements MiddlewareInterface
{
    private CsrfTokenHandler $handler;
    private array $protectedRoutes;
    private array $excludedRoutes;

    /**
     * @param array $protectedRoutes Routes that require CSRF validation
     * @param array $excludedRoutes Routes to exclude from CSRF validation
     * @param CsrfTokenHandler|null $handler Custom handler (optional)
     */
    public function __construct(
        array $protectedRoutes = [],
        array $excludedRoutes = [],
        ?CsrfTokenHandler $handler = null
    ) {
        $this->protectedRoutes = $protectedRoutes;
        $this->excludedRoutes = $excludedRoutes;
        $this->handler = $handler ?? new CsrfTokenHandler();
    }

    /**
     * Execute middleware
     */
    public function handle(array $request, callable $next): ?ApiResponse
    {
        $path = $request['path'] ?? '/';
        $method = $request['method'] ?? 'GET';

        // Only validate POST/PUT/DELETE requests
        if (!in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'])) {
            return $next($request);
        }

        // Check if route is excluded
        if ($this->isExcluded($path)) {
            return $next($request);
        }

        // Check if route is protected
        if (!$this->isProtected($path)) {
            return $next($request);
        }

        // Extract CSRF token from request
        $token = $this->extractToken($request);

        if (!$token) {
            log_warning("CSRF validation failed: No token provided", [
                'path' => $path,
                'method' => $method,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);

            return new ApiResponse('error', 'CSRF token required', [
                'error_code' => 'CSRF_TOKEN_MISSING',
                'message' => 'Please refresh the page and try again'
            ], 403);
        }

        // Validate token
        $action = $this->getActionFromPath($path);
        $valid = $this->handler->validateToken($token, ['action' => $action]);

        if (!$valid) {
            log_warning("CSRF validation failed: Invalid token", [
                'path' => $path,
                'method' => $method,
                'action' => $action,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);

            return new ApiResponse('error', 'Invalid or expired CSRF token', [
                'error_code' => 'CSRF_TOKEN_INVALID',
                'message' => 'Please refresh the page and try again'
            ], 403);
        }

        log_debug("CSRF validation passed", [
            'path' => $path,
            'action' => $action
        ]);

        return $next($request);
    }

    /**
     * Check if route is protected
     */
    private function isProtected(string $path): bool
    {
        // If no protected routes specified, protect all routes
        if (empty($this->protectedRoutes)) {
            return true;
        }

        foreach ($this->protectedRoutes as $route) {
            if ($this->matchRoute($path, $route)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if route is excluded
     */
    private function isExcluded(string $path): bool
    {
        foreach ($this->excludedRoutes as $route) {
            if ($this->matchRoute($path, $route)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match route pattern
     */
    private function matchRoute(string $path, string $pattern): bool
    {
        // Exact match
        if ($path === $pattern) {
            return true;
        }

        // Wildcard match
        if (str_ends_with($pattern, '*')) {
            $prefix = rtrim($pattern, '*');
            return str_starts_with($path, $prefix);
        }

        return false;
    }

    /**
     * Extract CSRF token from request
     */
    private function extractToken(array $request): ?string
    {
        // 1. Check X-CSRF-Token header (recommended)
        if (isset($request['headers']['X-CSRF-Token'])) {
            return $request['headers']['X-CSRF-Token'];
        }

        // 2. Check X-XSRF-TOKEN header (Angular convention)
        if (isset($request['headers']['X-XSRF-TOKEN'])) {
            return $request['headers']['X-XSRF-TOKEN'];
        }

        // 3. Check request body
        if (isset($request['body']['csrf_token'])) {
            return $request['body']['csrf_token'];
        }

        // 4. Check query parameters (least secure, but supported)
        if (isset($request['params']['csrf_token'])) {
            return $request['params']['csrf_token'];
        }

        return null;
    }

    /**
     * Get action name from path for context validation
     */
    private function getActionFromPath(string $path): string
    {
        // Extract action from path
        // /api/auth/register -> register
        // /api/auth/login -> login
        // /api/contact/submit -> contact

        $parts = array_filter(explode('/', $path));
        $lastPart = end($parts);

        return $lastPart ?: 'general';
    }
}
