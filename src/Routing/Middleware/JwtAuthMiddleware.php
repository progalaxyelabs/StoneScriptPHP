<?php

namespace StoneScriptPHP\Routing\Middleware;

use StoneScriptPHP\Routing\MiddlewareInterface;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Auth\AuthenticatedUser;
use StoneScriptPHP\Auth\AuthContext;
use StoneScriptPHP\Auth\JwtHandlerInterface;

/**
 * JWT Authentication Middleware
 *
 * Extracts and validates JWT tokens, then populates the authenticated user context.
 * Use this middleware on routes that require authentication.
 *
 * Example usage in routes:
 *   $router->use(new JwtAuthMiddleware($jwtHandler, ['/api/public/*']));
 *
 * Access authenticated user in route handlers:
 *   $user = auth()->user();
 *   $userId = auth()->id();
 *
 * URL Prefix Auto-Detection:
 *   When using nginx URL rewriting (e.g., /api/user/access → /user/access internally),
 *   you can specify excluded paths using EITHER the external URL or internal route path.
 *   The middleware auto-detects the URL prefix and normalizes paths accordingly.
 *
 *   Example with nginx rewrite "/api/* → /*":
 *     excludedPaths: ['/api/user/access']  // Works - external URL
 *     excludedPaths: ['/user/access']      // Works - internal route
 *     Both will correctly exclude the /user/access route.
 */
class JwtAuthMiddleware implements MiddlewareInterface
{
    private JwtHandlerInterface $jwtHandler;
    private array $excludedPaths;
    private array $normalizedExcludedPaths;
    private string $headerName;
    private ?string $detectedUrlPrefix = null;

    /**
     * @param JwtHandlerInterface $jwtHandler JWT handler instance (JwtHandler or RsaJwtHandler)
     * @param array $excludedPaths Paths that don't require authentication (supports wildcards)
     * @param string $headerName The header name to check for auth token (default: 'Authorization')
     */
    public function __construct(
        JwtHandlerInterface $jwtHandler,
        array $excludedPaths = [],
        string $headerName = 'Authorization'
    ) {
        $this->jwtHandler = $jwtHandler;
        $this->excludedPaths = $excludedPaths;
        $this->headerName = $headerName;

        // Detect URL prefix and normalize excluded paths
        $this->detectedUrlPrefix = $this->detectUrlPrefix();
        $this->normalizedExcludedPaths = $this->normalizeExcludedPaths($excludedPaths);
    }

    /**
     * Detect URL prefix by comparing REQUEST_URI with SCRIPT_NAME.
     * Handles nginx rewrites like /api/* → /index.php
     */
    private function detectUrlPrefix(): ?string
    {
        $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';

        // If script is /index.php and request is /api/something, prefix is /api
        if ($scriptName === '/index.php' || str_ends_with($scriptName, '/index.php')) {
            // Check common prefixes
            foreach (['/api', '/v1', '/v2'] as $prefix) {
                if (str_starts_with($requestUri, $prefix . '/') || $requestUri === $prefix) {
                    log_debug("JWT middleware: Detected URL prefix '$prefix'");
                    return $prefix;
                }
            }
        }

        return null;
    }

    /**
     * Normalize excluded paths to match both with and without URL prefix.
     */
    private function normalizeExcludedPaths(array $paths): array
    {
        $normalized = [];

        foreach ($paths as $path) {
            // Always add the original path
            $normalized[] = $path;

            if ($this->detectedUrlPrefix !== null) {
                // If path starts with prefix, also add without prefix
                if (str_starts_with($path, $this->detectedUrlPrefix)) {
                    $withoutPrefix = substr($path, strlen($this->detectedUrlPrefix));
                    if ($withoutPrefix !== '' && !in_array($withoutPrefix, $normalized)) {
                        $normalized[] = $withoutPrefix;
                    }
                }
                // If path doesn't start with prefix, also add with prefix
                else if (!str_starts_with($path, $this->detectedUrlPrefix)) {
                    $withPrefix = $this->detectedUrlPrefix . $path;
                    if (!in_array($withPrefix, $normalized)) {
                        $normalized[] = $withPrefix;
                    }
                }
            }
        }

        return $normalized;
    }

    public function handle(array $request, callable $next): ?ApiResponse
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

        // Check if path is excluded from authentication (uses normalized paths)
        foreach ($this->normalizedExcludedPaths as $excludedPath) {
            if ($this->matchesPath($path, $excludedPath)) {
                log_debug("JWT middleware: Path $path is excluded from authentication (matched: $excludedPath)");
                return $next($request);
            }
        }

        // Get authorization header
        $authHeader = $this->getAuthHeader();

        if (empty($authHeader)) {
            log_debug('JWT middleware: Missing authorization header');
            http_response_code(401);
            return new ApiResponse('error', 'Unauthorized: Missing authentication token');
        }

        // Extract token (remove "Bearer " prefix)
        $token = $this->extractToken($authHeader);

        // Verify and decode JWT token
        $payload = $this->jwtHandler->verifyToken($token);

        if ($payload === false) {
            log_debug('JWT middleware: Invalid or expired token');
            http_response_code(401);
            return new ApiResponse('error', 'Unauthorized: Invalid or expired token');
        }

        // Create authenticated user from payload
        try {
            $user = AuthenticatedUser::fromPayload($payload);

            // Store user in global auth context
            AuthContext::setUser($user);

            log_debug("JWT middleware: User {$user->user_id} authenticated successfully");

            // Continue to next middleware
            return $next($request);

        } catch (\InvalidArgumentException $e) {
            log_error("JWT middleware: Invalid token payload - {$e->getMessage()}");
            http_response_code(401);
            return new ApiResponse('error', 'Unauthorized: Invalid token format');
        }
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

        // Wildcard match
        $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#';
        return (bool) preg_match($regex, $path);
    }
}
