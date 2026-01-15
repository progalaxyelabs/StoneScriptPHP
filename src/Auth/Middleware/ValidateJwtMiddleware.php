<?php

namespace StoneScriptPHP\Auth\Middleware;

use StoneScriptPHP\Routing\MiddlewareInterface;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Auth\JwtHandlerInterface;

/**
 * ValidateJwtMiddleware
 *
 * Validates JWT tokens from the Authorization header.
 * Extracts Bearer token, validates using TokenValidator (JwtHandler),
 * and stores claims in request attributes for downstream middleware.
 * Returns 401 if token is invalid or missing.
 */
class ValidateJwtMiddleware implements MiddlewareInterface
{
    private JwtHandlerInterface $jwtHandler;
    private string $headerName;

    /**
     * @param JwtHandlerInterface $jwtHandler JWT handler for token validation
     * @param string $headerName The header name to check for auth token (default: 'Authorization')
     */
    public function __construct(
        JwtHandlerInterface $jwtHandler,
        string $headerName = 'Authorization'
    ) {
        $this->jwtHandler = $jwtHandler;
        $this->headerName = $headerName;
    }

    public function handle(array $request, callable $next): ?ApiResponse
    {
        // Get authorization header
        $authHeader = $this->getAuthHeader();

        if (empty($authHeader)) {
            http_response_code(401);
            return new ApiResponse('error', 'Unauthorized: Missing authentication token');
        }

        // Extract token (remove "Bearer " prefix)
        $token = $this->extractToken($authHeader);

        // Verify and decode JWT token
        $payload = $this->jwtHandler->verifyToken($token);

        if ($payload === false) {
            http_response_code(401);
            return new ApiResponse('error', 'Unauthorized: Invalid or expired token');
        }

        // Store JWT claims in request attributes for downstream middleware
        $request['jwt_claims'] = $payload;

        // Continue to next middleware with enriched request
        return $next($request);
    }

    /**
     * Get authorization header from various sources
     */
    private function getAuthHeader(): string
    {
        // Try standard Authorization header
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            return $_SERVER['HTTP_AUTHORIZATION'];
        }

        // Try alternate header format
        $headerKey = 'HTTP_' . strtoupper(str_replace('-', '_', $this->headerName));
        if (isset($_SERVER[$headerKey])) {
            return $_SERVER[$headerKey];
        }

        // Try apache_request_headers if available
        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (isset($headers[$this->headerName])) {
                return $headers[$this->headerName];
            }
        }

        return '';
    }

    /**
     * Extract token from authorization header
     */
    private function extractToken(string $authHeader): string
    {
        // Remove "Bearer " prefix if present
        if (stripos($authHeader, 'Bearer ') === 0) {
            return substr($authHeader, 7);
        }

        return $authHeader;
    }
}
