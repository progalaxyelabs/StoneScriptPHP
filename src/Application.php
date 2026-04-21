<?php

declare(strict_types=1);

namespace StoneScriptPHP;

use StoneScriptPHP\Routing\Router;
use StoneScriptPHP\Routing\Middleware\LoggingMiddleware;
use StoneScriptPHP\Routing\Middleware\CorsMiddleware;
use StoneScriptPHP\Routing\Middleware\JsonBodyParserMiddleware;
use StoneScriptPHP\Routing\Middleware\JwtAuthMiddleware;
use StoneScriptPHP\Routing\Middleware\GatewayTenantMiddleware;
use StoneScriptPHP\Routing\MiddlewareInterface;
use StoneScriptPHP\Auth\JwtHandlerInterface;
use StoneScriptPHP\Auth\RsaJwtHandler;
use StoneScriptPHP\Auth\MultiAuthJwtValidator;
use StoneScriptPHP\Auth\MultiAuthJwtAdapter;
use StoneScriptPHP\Auth\AuthRoutes;
use StoneScriptPHP\Auth\AuthContext;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthRoutes;
use StoneScriptPHP\Subscriptions\SubscriptionMiddleware;
use StoneScriptPHP\Subscriptions\SubscriptionRoutes;

/**
 * Application entry point
 *
 * Replaces the 200+ line boilerplate index.php that every platform had to copy-paste.
 * Takes a config array and handles everything: middleware setup, JWT handler construction,
 * auth route registration, subscription enforcement, and request dispatch.
 *
 * Usage in public/index.php:
 *
 *   Application::run([
 *       'routes' => require CONFIG_PATH . 'routes.php',
 *       'auth'   => [
 *           'mode' => 'external',
 *           'provisioner' => new MyProvisioner(...),
 *           // ... other auth config
 *       ],
 *       'subscription' => [
 *           'prefix' => '/subscription',
 *           'razorpay_webhook_secret' => '...',
 *           'admin_api_key' => '...',
 *       ],
 *       'jwt' => [
 *           'excluded_paths' => ['/health', '/webhook/...'],
 *       ],
 *       'middleware' => [
 *           new CustomMiddleware(),
 *       ],
 *   ]);
 *
 * @package StoneScriptPHP
 */
class Application
{
    /** @var float Request start time for logging */
    private static float $startTime;

    /**
     * Run the application with the given config
     *
     * @param array $config Must contain 'routes' and optionally 'auth', 'subscription', 'jwt', 'middleware'
     */
    public static function run(array $config): void
    {
        self::$startTime = microtime(true);

        // Define STDIN, STDOUT, STDERR for PHP-FPM compatibility (CLI has them by default)
        self::defineStdStreams();

        // Handle robots.txt before any routing or auth — disallow all crawlers
        if (self::handleRobotsTxt()) {
            return;
        }

        $env = Env::get_instance();
        $authConfig         = $config['auth'] ?? [];
        $appRoutes          = $config['routes'] ?? [];
        $subscriptionConfig = $config['subscription'] ?? [];
        $jwtConfig          = $config['jwt'] ?? [];
        $customMiddleware   = $config['middleware'] ?? [];

        $jwtHandler = self::buildJwtHandler($authConfig, $env);

        // Build JWT excluded paths from config
        $jwtExcludedPaths = $jwtConfig['excluded_paths'] ?? [];

        // Always exclude /health from JWT auth (health checks must be public for Docker/Traefik)
        if (!in_array('/health', $jwtExcludedPaths, true)) {
            $jwtExcludedPaths[] = '/health';
        }

        // Add subscription public paths if subscription module is enabled
        if (!empty($subscriptionConfig)) {
            $jwtExcludedPaths = array_merge(
                $jwtExcludedPaths,
                SubscriptionRoutes::publicPaths($subscriptionConfig)
            );
        }

        $router = new Router();
        $router->use(new LoggingMiddleware());
        $router->use(new CorsMiddleware(
            explode(',', $env->ALLOWED_ORIGINS ?? '*')
        ));
        $router->use(new JwtAuthMiddleware(
            jwtHandler: $jwtHandler,
            excludedPaths: $jwtExcludedPaths
        ));
        $router->use(new GatewayTenantMiddleware());

        // Add SubscriptionMiddleware if subscription config is present
        if (!empty($subscriptionConfig)) {
            $router->use(new SubscriptionMiddleware());
        }

        // Add custom middleware
        foreach ($customMiddleware as $middleware) {
            if ($middleware instanceof MiddlewareInterface) {
                $router->use($middleware);
            }
        }

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

        // Register subscription routes if subscription config is present
        if (!empty($subscriptionConfig)) {
            SubscriptionRoutes::register($router, array_merge(
                $subscriptionConfig,
                ['platform_code' => $authConfig['platform']['code'] ?? $env->PLATFORM_CODE ?? '']
            ));
        }

        $router->loadRoutes($appRoutes);

        $response = $router->dispatch();

        // Set HTTP status code from ApiResponse when provided.
        // Middleware/error handlers set their own codes via http_response_code() directly.
        // This covers route handlers returning res_error(msg, 400) / res_not_ok(msg, 422) etc.
        if ($response->httpStatusCode !== null) {
            http_response_code($response->httpStatusCode);
        }

        header('Content-Type: application/json');
        echo $response->toJson();

        // Log request to STDERR for Docker/Swarm
        self::logRequest();
    }

    /**
     * Define STDIN, STDOUT, STDERR for PHP-FPM compatibility
     *
     * CLI mode defines these automatically, but PHP-FPM does not.
     */
    private static function defineStdStreams(): void
    {
        if (!defined('STDIN')) {
            define('STDIN', fopen('php://stdin', 'rb'));
        }
        if (!defined('STDOUT')) {
            define('STDOUT', fopen('php://stdout', 'wb'));
        }
        if (!defined('STDERR')) {
            define('STDERR', fopen('php://stderr', 'wb'));
        }
    }

    /**
     * Handle robots.txt request before routing
     *
     * @return bool True if request was handled, false otherwise
     */
    private static function handleRobotsTxt(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' &&
            parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/robots.txt') {
            header('Content-Type: text/plain');
            echo "User-agent: *\nDisallow: /\n";
            return true;
        }
        return false;
    }

    /**
     * Log request to STDERR for Docker/Swarm
     *
     * Format: [REQUEST] METHOD /path | status=CODE | duration=Xms | user=ID | tenant=ID
     */
    private static function logRequest(): void
    {
        $method   = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
        $uri      = $_SERVER['REQUEST_URI'] ?? '/';
        $route    = strtok($uri, '?');
        $status   = http_response_code() ?: 200;
        $duration = round((microtime(true) - self::$startTime) * 1000, 2);
        $userId   = AuthContext::check() ? (string) AuthContext::getUser()->user_id : '-';
        $tenantId = AuthContext::check() ? (string) (AuthContext::getUser()->tenant_id ?? '-') : '-';

        fwrite(STDERR, implode(' | ', [
            '[REQUEST]',
            $method . ' ' . $route,
            'status=' . $status,
            'duration=' . $duration . 'ms',
            'user=' . $userId,
            'tenant=' . $tenantId,
        ]) . PHP_EOL);
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
        $platformSecret = $authConfig['platform']['secret'] ?? $env->EXTERNAL_AUTH_CLIENT_SECRET ?? null;

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

        // Pass through provisioner if present (for tenant provisioning flow)
        if (isset($authConfig['provisioner'])) {
            $options['provisioner'] = $authConfig['provisioner'];
            // Enable provision_tenant route when provisioner is provided
            $options['provision_tenant'] = true;
        }

        return $options;
    }
}
