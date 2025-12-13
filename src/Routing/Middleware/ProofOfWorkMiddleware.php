<?php

namespace Framework\Routing\Middleware;

use Framework\Routing\MiddlewareInterface;
use Framework\ApiResponse;
use Framework\Security\ProofOfWorkChallenge;

/**
 * Proof-of-Work Middleware
 *
 * Requires clients to solve computational puzzle before accessing protected routes.
 * Transparent to users (1-5 second delay) but makes bot automation expensive.
 *
 * Usage:
 * $router->use(new ProofOfWorkMiddleware([
 *     '/api/auth/register',
 *     '/api/auth/login'
 * ], difficulty: 4));
 */
class ProofOfWorkMiddleware implements MiddlewareInterface
{
    private ProofOfWorkChallenge $challenge;
    private array $protectedRoutes;
    private int $difficulty;

    public function __construct(
        array $protectedRoutes = [],
        int $difficulty = 4,
        ?ProofOfWorkChallenge $challenge = null
    ) {
        $this->protectedRoutes = $protectedRoutes;
        $this->difficulty = $difficulty;
        $this->challenge = $challenge ?? new ProofOfWorkChallenge();
    }

    public function handle(array $request, callable $next): ?ApiResponse
    {
        $path = $request['path'] ?? '/';
        $method = $request['method'] ?? 'GET';

        // Only check POST/PUT requests
        if (!in_array($method, ['POST', 'PUT', 'PATCH'])) {
            return $next($request);
        }

        // Check if route is protected
        if (!$this->isProtected($path)) {
            return $next($request);
        }

        // Extract PoW solution from request
        $solution = $this->extractSolution($request);

        if (!$solution) {
            log_warning("PoW verification failed: No solution provided", [
                'path' => $path,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);

            return new ApiResponse('error', 'Proof-of-work solution required', [
                'error_code' => 'POW_REQUIRED',
                'message' => 'Please complete the verification challenge',
                'hint' => 'Request a challenge from /api/challenge endpoint'
            ], 403);
        }

        // Validate solution
        $valid = $this->challenge->verifySolution(
            $solution['challenge'],
            $solution['nonce'],
            $solution['difficulty'],
            $solution['expires_at']
        );

        if (!$valid) {
            log_warning("PoW verification failed: Invalid solution", [
                'path' => $path,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);

            return new ApiResponse('error', 'Invalid proof-of-work solution', [
                'error_code' => 'POW_INVALID',
                'message' => 'Challenge solution is invalid or expired. Please request a new challenge.'
            ], 403);
        }

        log_debug("PoW verification successful", [
            'path' => $path
        ]);

        return $next($request);
    }

    /**
     * Check if route is protected
     */
    private function isProtected(string $path): bool
    {
        foreach ($this->protectedRoutes as $route) {
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
        if ($path === $pattern) {
            return true;
        }

        if (str_ends_with($pattern, '*')) {
            $prefix = rtrim($pattern, '*');
            return str_starts_with($path, $prefix);
        }

        return false;
    }

    /**
     * Extract PoW solution from request
     */
    private function extractSolution(array $request): ?array
    {
        // Check X-POW-Solution header (recommended)
        if (isset($request['headers']['X-POW-Solution'])) {
            $solution = json_decode($request['headers']['X-POW-Solution'], true);
            if ($this->isValidSolutionFormat($solution)) {
                return $solution;
            }
        }

        // Check request body
        if (isset($request['body']['pow_solution'])) {
            $solution = $request['body']['pow_solution'];
            if (is_string($solution)) {
                $solution = json_decode($solution, true);
            }
            if ($this->isValidSolutionFormat($solution)) {
                return $solution;
            }
        }

        return null;
    }

    /**
     * Validate solution format
     */
    private function isValidSolutionFormat($solution): bool
    {
        return is_array($solution) &&
               isset($solution['challenge']) &&
               isset($solution['nonce']) &&
               isset($solution['difficulty']) &&
               isset($solution['expires_at']);
    }
}
