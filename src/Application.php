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
use StoneScriptPHP\Auth\HybridCardJwtHandler;
use StoneScriptPHP\Auth\MultiAuthJwtValidator;
use StoneScriptPHP\Auth\MultiAuthJwtAdapter;
use StoneScriptPHP\Auth\AuthRoutes;
use StoneScriptPHP\Auth\AuthContext;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthRoutes;
use StoneScriptPHP\Subscriptions\SubscriptionMiddleware;
use StoneScriptPHP\Subscriptions\SubscriptionRoutes;
use StoneScriptPHP\Routing\Middleware\StoreAccessMiddleware;
use StoneScriptPHP\RequestLogging\RequestLogger;

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
        // §2 — prefer INDEX_START_TIME (set at the very top of public/index.php before autoload).
        // Fall back to now() for older platforms whose index.php predates the constant.
        self::$startTime = defined('INDEX_START_TIME')
            ? (float) INDEX_START_TIME
            : microtime(true);

        // §1 — arm the request logger FIRST, before any middleware / router wiring,
        // so the shutdown function fires even if run() throws mid-pipeline.
        RequestLogger::arm($config, self::$startTime);

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
        $storeAccessConfig  = $config['store_access'] ?? [];

        // Subscription wiring is gated on BOTH presence AND the 'enabled' flag
        // (mirrors the store_access gate below). See self::isSubscriptionEnabled().
        $subscriptionEnabled = self::isSubscriptionEnabled($subscriptionConfig);

        $jwtHandler = self::buildJwtHandler($authConfig, $env, $jwtConfig);

        // Build JWT excluded paths from config
        $jwtExcludedPaths = $jwtConfig['excluded_paths'] ?? [];

        // Always exclude /health from JWT auth (health checks must be public for Docker/Traefik)
        if (!in_array('/health', $jwtExcludedPaths, true)) {
            $jwtExcludedPaths[] = '/health';
        }

        // Add subscription public paths if subscription module is enabled
        if ($subscriptionEnabled) {
            $jwtExcludedPaths = array_merge(
                $jwtExcludedPaths,
                SubscriptionRoutes::publicPaths($subscriptionConfig)
            );
        }

        $router = new Router();
        $router->use(new LoggingMiddleware());
        $router->use(new CorsMiddleware(
            explode(',', $env->ALLOWED_ORIGINS ?? '*'),
            explode(',', $env->ALLOWED_METHODS ?? 'GET,POST,PUT,PATCH,DELETE,OPTIONS')
        ));
        $router->use(new JwtAuthMiddleware(
            jwtHandler: $jwtHandler,
            excludedPaths: $jwtExcludedPaths
        ));
        $router->use(new GatewayTenantMiddleware());

        // T3 url-tenant: StoreAccessMiddleware MUST run before SubscriptionMiddleware.
        // It extracts :storeId from the URL, validates membership via HTTP to the
        // auth service (source of truth), and sets GatewayClient tenant_id.
        if (!empty($storeAccessConfig['enabled'])) {
            // Merge auth service URL + platform code from auth config so the
            // caller doesn't have to repeat them in store_access config.
            $authRouteOptions = self::buildAuthRouteOptions($authConfig, $env);
            $resolvedStoreAccessConfig = array_merge([
                'auth_service_url' => $authRouteOptions['auth_service_url'],
                'platform_code'    => $authRouteOptions['platform_code'],
            ], $storeAccessConfig);
            $router->use(new StoreAccessMiddleware($resolvedStoreAccessConfig));
        }

        // Add SubscriptionMiddleware if subscription config is present.
        // Runs after StoreAccessMiddleware so tenant context is already set (T3).
        if ($subscriptionEnabled) {
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
        if ($subscriptionEnabled) {
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
     * Decide whether subscription wiring (SubscriptionMiddleware + routes +
     * public paths) should be registered.
     *
     * Gated on BOTH presence AND the 'enabled' flag, mirroring the store_access
     * gate. A platform that explicitly sets subscription => ['enabled' => false]
     * must NOT get the middleware wired: it would call sub_get_status() against a
     * sub_* schema that was never installed → fail-open error noise on every
     * authed request, and a latent fail-closed HTTP 402 landmine.
     *
     * Back-compat: `?? true` means a subscription config WITHOUT an 'enabled' key
     * stays ON, exactly as before this gate existed.
     *
     * @param array $subscriptionConfig The 'subscription' section of the config
     * @return bool True if subscription wiring should be registered
     */
    private static function isSubscriptionEnabled(array $subscriptionConfig): bool
    {
        return !empty($subscriptionConfig) && ($subscriptionConfig['enabled'] ?? true);
    }

    /**
     * Build the JWT handler based on auth mode config.
     *
     * ## Injection (card model platforms)
     *
     * Pass a pre-built handler via `$config['jwt']['handler']` to override the default:
     *
     *   Application::run([
     *       'jwt'  => ['handler' => new HybridCardJwtHandler(...)],
     *       'auth' => ['mode' => 'external', ...],
     *   ]);
     *
     * ## Default behaviour per mode
     *
     * - `builtin`        → `RsaJwtHandler` (platform RSA key, self-contained auth).
     * - `external/hybrid` → **`HybridCardJwtHandler`** (platform RSA for cards +
     *   JWKS fallback for passports). This is the card-model default: the platform
     *   mints and validates its own cards while still accepting auth-service passports
     *   on public-adjacent routes (e.g. exchange validates the inbound passport itself,
     *   but after exchange all subsequent requests carry platform-signed cards).
     *
     * ## Key: issuer vs network URL
     *
     * In Docker environments:
     *   - `AUTH_SERVICE_URL` = container hostname for JWKS fetch (e.g. http://auth:3139)
     *   - `AUTH_ISSUER`      = public hostname stamped in JWT 'iss' claims (e.g. https://auth.example.com)
     *
     * These are DIFFERENT values. `AUTH_ISSUER` must match the auth service's `JWT_ISSUER`
     * env exactly. Reusing `AUTH_SERVICE_URL` as the issuer is always wrong in Docker.
     *
     * @param array $authConfig   Auth section of the config array.
     * @param Env   $env          Framework Env instance.
     * @param array $jwtConfig    JWT section of the config array (for handler injection).
     * @return JwtHandlerInterface
     */
    private static function buildJwtHandler(array $authConfig, Env $env, array $jwtConfig = []): JwtHandlerInterface
    {
        // Explicit injection: platforms can pass their own handler via config['jwt']['handler'].
        // This is the escape hatch for advanced scenarios (custom JWKS, multi-issuer, etc.).
        if (isset($jwtConfig['handler']) && $jwtConfig['handler'] instanceof JwtHandlerInterface) {
            return $jwtConfig['handler'];
        }

        $mode = $authConfig['mode'] ?? $env->AUTH_MODE ?? 'builtin';

        if ($mode === 'builtin') {
            return new RsaJwtHandler();
        }

        // external or hybrid: need to validate BOTH platform-minted cards AND auth-service passports.
        $serverUrl = $authConfig['server']['url'] ?? $env->AUTH_SERVICE_URL ?? 'http://localhost:3139';

        // AUTH_ISSUER MUST be set explicitly in external/hybrid mode.
        // The old fallback to AUTH_SERVICE_URL was silently wrong in Docker: AUTH_SERVICE_URL
        // is the container-internal network address (e.g. http://auth:3139) but JWTs carry
        // the public hostname in the 'iss' claim (e.g. http://localhost:3139) — a guaranteed
        // mismatch. Platforms must set AUTH_ISSUER explicitly to the value the auth service
        // stamps in tokens (JWT_ISSUER on the auth daemon). Verify with: decode a real JWT
        // and read its 'iss' claim; set AUTH_ISSUER to that exact string.
        $issuer = $authConfig['server']['issuer'] ?? (
            !empty($env->AUTH_ISSUER) ? $env->AUTH_ISSUER : null
        );
        if ($issuer === null || trim($issuer) === '') {
            throw new \RuntimeException(
                "AUTH_ISSUER is required when AUTH_MODE is '{$mode}' but is not set or empty. "
                . "Set AUTH_ISSUER to the exact 'iss' claim value stamped in tokens by the auth service "
                . "(decode a real JWT and read its 'iss' field). AUTH_SERVICE_URL is the NETWORK address "
                . "for JWKS fetch — it is NOT the issuer. Silently reusing AUTH_SERVICE_URL as the issuer "
                . "causes every authenticated API call to 401 in Docker environments."
            );
        }
        $jwksPath = $authConfig['server']['paths']['jwks'] ?? '/api/auth/jwks';

        // Default for external/hybrid: HybridCardJwtHandler validates platform-minted cards
        // (platform RSA key) AND falls back to JWKS for auth-service passports.
        // This replaces the former MultiAuthJwtAdapter (JWKS-only), which rejected cards
        // because it only knew the auth service's public key, not the platform's.
        return new HybridCardJwtHandler($serverUrl, $issuer, $jwksPath);
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
        // AUTH_ISSUER must be set — no fallback to server URL (see buildJwtHandler comment).
        // ExternalAuthConfig constructor will enforce this; passing null here lets that check fire.
        $issuer     = $authConfig['server']['issuer'] ?? (
            !empty($env->AUTH_ISSUER) ? $env->AUTH_ISSUER : null
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

        // Card model resolver closures (framework-spec.md §6).
        // These were previously NOT threaded through buildAuthRouteOptions(), which forced
        // platforms to bypass Application::run() entirely and call ExternalAuthRoutes::register()
        // directly (the canary's manual bootstrap workaround). Threading them here means
        // platforms can wire the card model via the normal Application::run() config.
        //
        // tenants_resolver: fn(array $passportClaims): array[] — tenants for this identity.
        // roles_resolver:   fn(array $claimsWithTenant): string[] — roles in that tenant.
        //
        // ExternalAuthRoutes::register() and ExternalAuthConfig both accept these keys directly.
        if (isset($authConfig['tenants_resolver']) && is_callable($authConfig['tenants_resolver'])) {
            $options['tenants_resolver'] = $authConfig['tenants_resolver'];
        }
        if (isset($authConfig['roles_resolver']) && is_callable($authConfig['roles_resolver'])) {
            $options['roles_resolver'] = $authConfig['roles_resolver'];
        }

        return $options;
    }
}
