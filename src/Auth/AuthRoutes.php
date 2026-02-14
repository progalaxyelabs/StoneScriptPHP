<?php

namespace StoneScriptPHP\Auth;

use StoneScriptPHP\Routing\Router;
use StoneScriptPHP\Auth\Routes\RefreshRoute;
use StoneScriptPHP\Auth\Routes\LogoutRoute;
use StoneScriptPHP\Auth\TokenStorageInterface;
use StoneScriptPHP\Auth\JwtHandlerInterface;

/**
 * Auth Routes Registration
 *
 * Registers built-in authentication routes with your router.
 * Provides secure token refresh and logout endpoints out of the box.
 *
 * Basic usage:
 *   AuthRoutes::register($router);
 *
 * With custom prefix:
 *   AuthRoutes::register($router, ['prefix' => '/api/auth']);
 *
 * With token storage:
 *   $tokenStorage = new PostgresTokenStorage($db);
 *   AuthRoutes::register($router, ['token_storage' => $tokenStorage]);
 *
 * Full customization:
 *   AuthRoutes::register($router, [
 *       'prefix' => '/api/auth',
 *       'token_storage' => $tokenStorage,
 *       'refresh' => true,  // enable/disable refresh route
 *       'logout' => true,   // enable/disable logout route
 *       'middleware' => []  // route-specific middleware
 *   ]);
 */
class AuthRoutes
{
    /**
     * Static token storage instance shared across auth routes
     */
    private static ?TokenStorageInterface $tokenStorage = null;

    /**
     * Register auth routes with the router
     *
     * @param Router $router The router instance
     * @param array $options Configuration options
     *   - prefix: string (default: '/auth') - URL prefix for auth routes
     *   - token_storage: TokenStorageInterface|null - Optional token storage for blacklisting
     *   - jwt_handler: JwtHandlerInterface|null - Optional JWT handler (defaults to RsaJwtHandler)
     *   - refresh: bool (default: true) - Enable refresh route
     *   - logout: bool (default: true) - Enable logout route
     *   - middleware: array (default: []) - Route-specific middleware
     * @return void
     */
    public static function register(Router $router, array $options = []): void
    {
        // Extract options with defaults
        $prefix = $options['prefix'] ?? '/auth';
        $tokenStorage = $options['token_storage'] ?? null;
        $jwtHandler = $options['jwt_handler'] ?? null;
        $enableRefresh = $options['refresh'] ?? true;
        $enableLogout = $options['logout'] ?? true;
        $middleware = $options['middleware'] ?? [];

        // Validate token storage if provided
        if ($tokenStorage !== null && !($tokenStorage instanceof TokenStorageInterface)) {
            throw new \InvalidArgumentException(
                'token_storage must implement TokenStorageInterface'
            );
        }

        // Store token storage for route handlers to use
        self::$tokenStorage = $tokenStorage;

        // Normalize prefix (remove trailing slash)
        $prefix = rtrim($prefix, '/');

        // Register refresh route
        if ($enableRefresh) {
            $refreshPath = "$prefix/refresh";
            // Pass JWT handler to RefreshRoute if provided
            $refreshRoute = $jwtHandler ? new RefreshRoute($jwtHandler) : RefreshRoute::class;
            $router->post($refreshPath, $refreshRoute, $middleware);
            log_debug("AuthRoutes: Registered POST $refreshPath");
        }

        // Register logout route
        if ($enableLogout) {
            $logoutPath = "$prefix/logout";
            $router->post($logoutPath, LogoutRoute::class, $middleware);
            log_debug("AuthRoutes: Registered POST $logoutPath");
        }

        log_info("AuthRoutes: Successfully registered auth routes with prefix '$prefix'");
    }

    /**
     * Get the configured token storage instance
     *
     * @return TokenStorageInterface|null
     */
    public static function getTokenStorage(): ?TokenStorageInterface
    {
        return self::$tokenStorage;
    }
}
