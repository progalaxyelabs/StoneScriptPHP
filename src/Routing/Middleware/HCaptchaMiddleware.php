<?php

namespace Framework\Routing\Middleware;

use Framework\Routing\MiddlewareInterface;
use Framework\ApiResponse;
use Framework\Security\HCaptchaVerifier;

/**
 * hCaptcha Middleware
 *
 * Verifies hCaptcha tokens on protected routes.
 * Automatically disabled if HCAPTCHA_SECRET_KEY is not set in .env
 *
 * Usage:
 * $router->use(new HCaptchaMiddleware([
 *     '/api/auth/register',
 *     '/api/auth/login',
 *     '/api/contact/submit'
 * ]));
 */
class HCaptchaMiddleware implements MiddlewareInterface
{
    private HCaptchaVerifier $verifier;
    private array $protectedRoutes;
    private array $excludedRoutes;

    /**
     * @param array $protectedRoutes Routes that require hCaptcha verification
     * @param array $excludedRoutes Routes to exclude from verification
     * @param HCaptchaVerifier|null $verifier Custom verifier instance
     */
    public function __construct(
        array $protectedRoutes = [],
        array $excludedRoutes = [],
        ?HCaptchaVerifier $verifier = null
    ) {
        $this->protectedRoutes = $protectedRoutes;
        $this->excludedRoutes = $excludedRoutes;
        $this->verifier = $verifier ?? new HCaptchaVerifier();
    }

    public function handle(array $request, callable $next): ?ApiResponse
    {
        // If hCaptcha is not configured, skip verification
        if (!$this->verifier->isEnabled()) {
            log_debug("hCaptcha middleware: Disabled (no API keys configured)");
            return $next($request);
        }

        $path = $request['path'] ?? '/';
        $method = $request['method'] ?? 'GET';

        // Only verify POST/PUT/DELETE/PATCH requests
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

        // Extract hCaptcha token
        $token = $this->extractToken($request);

        if (!$token) {
            log_warning("hCaptcha verification failed: No token provided", [
                'path' => $path,
                'method' => $method,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);

            return new ApiResponse('error', 'CAPTCHA verification required', [
                'error_code' => 'CAPTCHA_REQUIRED',
                'message' => 'Please complete the CAPTCHA verification'
            ], 403);
        }

        // Verify token
        $remoteIp = $_SERVER['REMOTE_ADDR'] ?? null;
        $valid = $this->verifier->verify($token, $remoteIp);

        if (!$valid) {
            $error = $this->verifier->getLastError();

            log_warning("hCaptcha verification failed", [
                'path' => $path,
                'method' => $method,
                'error' => $error,
                'ip' => $remoteIp ?? 'unknown'
            ]);

            return new ApiResponse('error', 'CAPTCHA verification failed', [
                'error_code' => 'CAPTCHA_INVALID',
                'message' => $error ?? 'Please complete the CAPTCHA verification again'
            ], 403);
        }

        log_debug("hCaptcha verification successful", [
            'path' => $path
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
     * Extract hCaptcha token from request
     */
    private function extractToken(array $request): ?string
    {
        // 1. Check h-captcha-response in body (standard hCaptcha field name)
        if (isset($request['body']['h-captcha-response'])) {
            return $request['body']['h-captcha-response'];
        }

        // 2. Check X-HCaptcha-Token header
        if (isset($request['headers']['X-HCaptcha-Token'])) {
            return $request['headers']['X-HCaptcha-Token'];
        }

        // 3. Check captcha_token in body (alternative field name)
        if (isset($request['body']['captcha_token'])) {
            return $request['body']['captcha_token'];
        }

        return null;
    }

    /**
     * Get verifier instance (for testing)
     */
    public function getVerifier(): HCaptchaVerifier
    {
        return $this->verifier;
    }
}
