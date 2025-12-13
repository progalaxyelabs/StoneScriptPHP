<?php

namespace StoneScriptPHP\Routing\Middleware;

use StoneScriptPHP\Routing\MiddlewareInterface;
use StoneScriptPHP\ApiResponse;

class AuthMiddleware implements MiddlewareInterface
{
    private array $excludedPaths;
    private $validator;
    private string $headerName;

    /**
     * @param callable|null $validator Custom validator function that takes token and returns bool
     * @param array $excludedPaths Paths that don't require authentication
     * @param string $headerName The header name to check for auth token (default: 'Authorization')
     */
    public function __construct(
        $validator = null,
        array $excludedPaths = [],
        string $headerName = 'Authorization'
    ) {
        $this->validator = $validator;
        $this->excludedPaths = $excludedPaths;
        $this->headerName = $headerName;
    }

    public function handle(array $request, callable $next): ?ApiResponse
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

        // Check if path is excluded from authentication
        foreach ($this->excludedPaths as $excludedPath) {
            if ($this->matchesPath($path, $excludedPath)) {
                log_debug("Auth middleware: Path $path is excluded from authentication");
                return $next($request);
            }
        }

        // Get authorization header
        $authHeader = $this->getAuthHeader();

        if (empty($authHeader)) {
            log_debug('Auth middleware: Missing authorization header');
            http_response_code(401);
            return new ApiResponse('error', 'Unauthorized: Missing authentication token');
        }

        // Extract token (remove "Bearer " prefix if present)
        $token = $this->extractToken($authHeader);

        // Validate token using custom validator if provided
        if ($this->validator !== null) {
            $isValid = call_user_func($this->validator, $token);
            if (!$isValid) {
                log_debug('Auth middleware: Invalid token');
                http_response_code(401);
                return new ApiResponse('error', 'Unauthorized: Invalid token');
            }
        }

        // Add token to request context for use in handlers
        $request['auth_token'] = $token;
        $request['authenticated'] = true;

        log_debug('Auth middleware: Request authenticated successfully');

        // Continue to next middleware
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

    /**
     * Check if a path matches a pattern (supports wildcards)
     */
    private function matchesPath(string $path, string $pattern): bool
    {
        // Exact match
        if ($path === $pattern) {
            return true;
        }

        // Wildcard match (simple implementation)
        $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#';
        return (bool) preg_match($regex, $path);
    }
}
