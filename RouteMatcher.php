<?php

namespace Framework;

/**
 * RouteMatcher
 *
 * Handles dynamic route pattern matching and parameter extraction.
 * Converts route patterns like "/user/{id}" into regex and extracts parameters.
 */
class RouteMatcher
{
    /**
     * Match a request path against registered routes and return handler class + params
     *
     * @param array $routes Array of route patterns => handler classes
     * @param string $requestPath The incoming request path (e.g., "/user/123")
     * @return array ['handler' => string|null, 'params' => array]
     */
    public static function match(array $routes, string $requestPath): array
    {
        // First, try exact match (for performance with static routes)
        if (isset($routes[$requestPath])) {
            return [
                'handler' => $routes[$requestPath],
                'params' => []
            ];
        }

        // Try dynamic pattern matching
        foreach ($routes as $pattern => $handler) {
            // Skip if pattern has no dynamic segments
            if (strpos($pattern, '{') === false) {
                continue;
            }

            $regex = self::convertPatternToRegex($pattern);

            if (preg_match($regex, $requestPath, $matches)) {
                // Extract parameter names and values
                $params = self::extractParameters($pattern, $requestPath, $matches);

                return [
                    'handler' => $handler,
                    'params' => $params
                ];
            }
        }

        // No match found
        return [
            'handler' => null,
            'params' => []
        ];
    }

    /**
     * Convert route pattern to regex
     *
     * Example: "/user/{id}" => "#^/user/([^/]+)$#"
     *
     * @param string $pattern Route pattern with {param} placeholders
     * @return string Regex pattern
     */
    public static function convertPatternToRegex(string $pattern): string
    {
        // Escape forward slashes and other regex special characters
        $regex = preg_quote($pattern, '#');

        // Replace escaped {param} with capture group
        // preg_quote converts { to \{ and } to \}
        $regex = preg_replace('/\\\{([a-zA-Z0-9_]+)\\\}/', '([^/]+)', $regex);

        return '#^' . $regex . '$#';
    }

    /**
     * Extract parameter names from pattern and match with values
     *
     * @param string $pattern Route pattern with {param} placeholders
     * @param string $url The matched URL
     * @param array $matches Regex matches from preg_match
     * @return array Associative array of parameter names => values
     */
    public static function extractParameters(string $pattern, string $url, array $matches): array
    {
        // Extract parameter names from pattern
        preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $pattern, $paramNames);

        // Combine names with captured values (skip $matches[0] which is full match)
        $params = [];
        foreach ($paramNames[1] as $index => $name) {
            $params[$name] = $matches[$index + 1] ?? null;
        }

        return $params;
    }

    /**
     * Check if a pattern contains dynamic segments
     *
     * @param string $pattern Route pattern
     * @return bool True if pattern has {param} segments
     */
    public static function isDynamic(string $pattern): bool
    {
        return strpos($pattern, '{') !== false;
    }
}
