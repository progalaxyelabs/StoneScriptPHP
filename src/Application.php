<?php

declare(strict_types=1);

namespace StoneScriptPHP;

use StoneScriptPHP\Routing\Router;
use StoneScriptPHP\Routing\Middleware\LoggingMiddleware;
use StoneScriptPHP\Routing\Middleware\CorsMiddleware;
use StoneScriptPHP\Routing\Middleware\JsonBodyParserMiddleware;
use StoneScriptPHP\Routing\Middleware\JwtAuthMiddleware;
use StoneScriptPHP\Routing\Middleware\GatewayTenantMiddleware;
use StoneScriptPHP\Auth\JwtHandlerInterface;
use StoneScriptPHP\Auth\RsaJwtHandler;
use StoneScriptPHP\Auth\MultiAuthJwtValidator;
use StoneScriptPHP\Auth\MultiAuthJwtAdapter;
use StoneScriptPHP\Auth\AuthRoutes;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthRoutes;

/**
 * Application entry point
 *
 * Replaces the 200+ line boilerplate index.php that every platform had to copy-paste.
 * Takes a config array and handles everything: middleware setup, JWT handler construction,
 * auth route registration, and request dispatch.
 *
 * Usage in public/index.php:
 *
 *   Application::run([
 *       'routes' => require CONFIG_PATH . 'routes.php',
 *       'auth'   => require CONFIG_PATH . 'auth.php',
 *   ]);
 *
 * @package StoneScriptPHP
 */
class Application
{
    /**
     * Run the application with the given config
     *
     * @param array $config Must contain 'routes' and optionally 'auth'
     */
    public static function run(array $config): void
    {
        $env = Env::get_instance();
        $authConfig = $config['auth'] ?? [];
        $appRoutes  = $config['routes'] ?? [];

        $jwtHandler = self::buildJwtHandler($authConfig, $env);

        $router = new Router();
        $router->use(new LoggingMiddleware());
        $router->use(new CorsMiddleware(
            explode(',', $env->ALLOWED_ORIGINS ?? '*')
        ));
        $router->use(new JwtAuthMiddleware($jwtHandler));
        $router->use(new GatewayTenantMiddleware());

        $authMode = $authConfig['mode'] ?? $env->AUTH_MODE ?? 'builtin';

        // Register built-in refresh/logout routes when not using external-only auth
        if ($authMode !== 'external') {
            AuthRoutes::register($router, [
                'jwt_handler' => $jwtHandler,
                'prefix' => $authConfig['prefix'] ?? '/auth',
            ]);
        }

        // Register external auth proxy routes for external/hybrid modes
        if ($authMode === 'external' || $authMode === 'hybrid') {
            $authRouteOptions = self::buildAuthRouteOptions($authConfig, $env);
            ExternalAuthRoutes::register($router, $authRouteOptions);
        }

        $router->loadRoutes($appRoutes);

        $response = $router->dispatch();

        header('Content-Type: application/json');
        echo $response->toJson();
    }

    /**
     * Build the JWT handler based on auth mode config
     *
     * Key: issuer (for JWT 'iss' claim validation) may differ from server URL
     * (for JWKS HTTP fetch). In Docker environments:
     *   - AUTH_SERVICE_URL = container hostname (JWKS fetch URL)
     *   - AUTH_ISSUER      = public hostname (value in JWT 'iss' claim)
     *
     * @param array $authConfig Auth section of the config array
     * @param Env $env Framework Env instance
     * @return JwtHandlerInterface
     */
    private static function buildJwtHandler(array $authConfig, Env $env): JwtHandlerInterface
    {
        $mode = $authConfig['mode'] ?? $env->AUTH_MODE ?? 'builtin';

        if ($mode === 'builtin') {
            return new RsaJwtHandler();
        }

        // external or hybrid: validate tokens via JWKS
        $serverUrl = $authConfig['server']['url'] ?? $env->AUTH_SERVICE_URL ?? 'http://localhost:3139';
        $issuer = $authConfig['server']['issuer'] ?? (
            !empty($env->AUTH_ISSUER) ? $env->AUTH_ISSUER : $serverUrl
        );
        $jwksPath = $authConfig['server']['paths']['jwks'] ?? '/api/auth/jwks';

        $validator = new MultiAuthJwtValidator([
            'primary' => [
                'issuer'    => $issuer,
                'jwks_url'  => rtrim($serverUrl, '/') . $jwksPath,
                'audience'  => null,
                'cache_ttl' => 3600,
            ],
        ]);

        return new MultiAuthJwtAdapter($validator);
    }

    /**
     * Build options array for ExternalAuthRoutes::register() from auth config
     *
     * @param array $authConfig Auth section of the config array
     * @param Env $env Framework Env instance
     * @return array
     */
    private static function buildAuthRouteOptions(array $authConfig, Env $env): array
    {
        $serverUrl  = $authConfig['server']['url'] ?? $env->AUTH_SERVICE_URL ?? 'http://localhost:3139';
        $issuer     = $authConfig['server']['issuer'] ?? (
            !empty($env->AUTH_ISSUER) ? $env->AUTH_ISSUER : $serverUrl
        );
        $platformCode   = $authConfig['platform']['code'] ?? $env->PLATFORM_CODE ?? '';
        $platformSecret = $authConfig['platform']['secret'] ?? $env->PLATFORM_SECRET ?? null;

        $options = [
            'prefix'           => $authConfig['prefix'] ?? '/auth',
            'auth_service_url' => $serverUrl,
            'auth_issuer'      => $issuer,
            'platform_code'    => $platformCode,
            'registration'     => [
                'mode'             => $authConfig['registration_mode'] ?? 'tenant',
                'extra_fields'     => $authConfig['registration']['extra_fields'] ?? [],
                'extra_validation' => $authConfig['registration']['extra_validation'] ?? [],
            ],
        ];

        // Merge feature toggles from config['features'] if present
        if (!empty($authConfig['features'])) {
            $options = array_merge($options, $authConfig['features']);
        }

        // Merge lifecycle hooks from config['hooks'] if present
        if (!empty($authConfig['hooks'])) {
            $options = array_merge($options, $authConfig['hooks']);
        }

        return $options;
    }
}
