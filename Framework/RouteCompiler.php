<?php

namespace Framework;

/**
 * RouteCompiler
 *
 * Compiles nested route group structures into flat associative arrays.
 * Enables Angular-style declarative routing with prefixes and nested groups.
 *
 * Example Input:
 * [
 *     '/' => HomeRoute::class,
 *     [
 *         'prefix' => '/api/v1',
 *         'routes' => [
 *             '/users' => UsersRoute::class,
 *             '/user/{id}' => UserRoute::class,
 *         ]
 *     ]
 * ]
 *
 * Example Output:
 * [
 *     '/' => HomeRoute::class,
 *     '/api/v1/users' => UsersRoute::class,
 *     '/api/v1/user/{id}' => UserRoute::class,
 * ]
 */
class RouteCompiler
{
    /**
     * Compile nested route structure into flat array
     *
     * @param array $routes Nested route configuration
     * @param string $prefix Accumulated prefix from parent groups
     * @return array Flat associative array of route => handler
     */
    public static function compile(array $routes, string $prefix = ''): array
    {
        $compiled = [];

        foreach ($routes as $key => $value) {
            // Check if this is a route group (associative array with 'prefix' and 'routes')
            if (is_array($value) && isset($value['routes'])) {
                // It's a group - recursively compile with accumulated prefix
                $groupPrefix = $value['prefix'] ?? '';
                $fullPrefix = self::joinPaths($prefix, $groupPrefix);

                $groupRoutes = self::compile($value['routes'], $fullPrefix);
                $compiled = array_merge($compiled, $groupRoutes);
            }
            // Check if this is a simple route (string key => handler)
            else if (is_string($key)) {
                // Simple route: apply current prefix
                $fullPath = self::joinPaths($prefix, $key);
                $compiled[$fullPath] = $value;
            }
            // Check if this is a nested group in numeric array
            else if (is_int($key) && is_array($value) && isset($value['routes'])) {
                // Numeric key with group structure
                $groupPrefix = $value['prefix'] ?? '';
                $fullPrefix = self::joinPaths($prefix, $groupPrefix);

                $groupRoutes = self::compile($value['routes'], $fullPrefix);
                $compiled = array_merge($compiled, $groupRoutes);
            }
        }

        return $compiled;
    }

    /**
     * Join two path segments with proper slash handling
     *
     * @param string $prefix Left path segment
     * @param string $path Right path segment
     * @return string Joined and normalized path
     */
    private static function joinPaths(string $prefix, string $path): string
    {
        // Handle empty cases
        if ($prefix === '' && $path === '') {
            return '/';
        }
        if ($prefix === '') {
            return self::normalizePath($path);
        }
        if ($path === '') {
            return self::normalizePath($prefix);
        }

        // Ensure prefix ends without slash (unless it's root)
        $prefix = rtrim($prefix, '/');
        if ($prefix === '') {
            $prefix = '/';
        }

        // Ensure path starts with slash
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        // Join and normalize
        $joined = $prefix . $path;
        return self::normalizePath($joined);
    }

    /**
     * Normalize path to ensure consistent slash handling
     *
     * - Ensures path starts with /
     * - Removes duplicate slashes
     * - Preserves route parameters like {id}
     *
     * @param string $path Path to normalize
     * @return string Normalized path
     */
    private static function normalizePath(string $path): string
    {
        // Handle empty path
        if ($path === '') {
            return '/';
        }

        // Ensure leading slash
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        // Remove duplicate slashes (but preserve route parameters)
        $path = preg_replace('#/+#', '/', $path);

        // Special case: if path is just '/' don't append anything
        if ($path === '/') {
            return $path;
        }

        // Remove trailing slash (except for root)
        $path = rtrim($path, '/');

        return $path;
    }

    /**
     * Check if routes array contains any groups
     *
     * @param array $routes Routes array to check
     * @return bool True if contains group structures
     */
    public static function hasGroups(array $routes): bool
    {
        foreach ($routes as $value) {
            if (is_array($value) && isset($value['routes'])) {
                return true;
            }
        }
        return false;
    }
}
