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
use StoneScriptPHP\Auth\BuiltinOAuth\GoogleOAuthRoutes;
use StoneScriptPHP\Subscriptions\SubscriptionMiddleware;
use StoneScriptPHP\Subscriptions\SubscriptionRoutes;
use StoneScriptPHP\Routing\Middleware\StoreAccessMiddleware;
use StoneScriptPHP\Auth\Middleware\TenantUrlMatchMiddleware;
use StoneScriptPHP\Auth\Middleware\RequireCardMiddleware;
use StoneScriptPHP\RequestLogging\RequestLogger;
use StoneScriptPHP\Plugin\PluginInterface;
use StoneScriptPHP\Tenancy\TenancyStrategyInterface;

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
 *       // Optional (§5.2 url.tenantId === card.tenant_id enforcement). Safe to
 *       // enable globally — self-skips routes whose pattern has no {tenantId}.
 *       'tenant_url_match' => ['enabled' => true, 'param' => 'tenantId'],
 *       // Optional (S5.1 tenant-less token cannot authorize a tenant-scoped route).
 *       // Safe to enable globally -- auto-exempts ExternalAuthRoutes' own tier-2
 *       // routes (provision-tenant, select-tenant, change-password,
 *       // memberships, me) so a valid passport is never wrongly rejected on them.
 *       // (invite-member was also on this list until 2026-07-21, when it was
 *       // removed along with the rest of the framework's invite/accept-invite
 *       // proxy — see DefaultTenantRouteProvider's class docblock.)
 *       // Prefer this over manually constructing `new RequireCardMiddleware()` in
 *       // 'middleware' -- the manual form has no exemption list and WILL 403 every
 *       // one of those routes the moment JwtAuthMiddleware populates jwt_claims for
 *       // them (see class docblock -- this was a real fleet incident, 2026-07-05).
 *       'require_card' => ['enabled' => true],
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
        $requireCardConfig  = $config['require_card'] ?? [];
        $authMode           = $authConfig['mode'] ?? $env->AUTH_MODE ?? 'builtin';

        // Phase 1 plugin seam (§ PluginInterface). `$config['plugins']` is `?? []` for
        // every platform today — none of the 11 fleet platforms pass this key yet, so
        // $plugins is always empty and nothing below this point changes behavior.
        // Invalid entries (not a PluginInterface instance) are dropped, not fatal —
        // a malformed plugins.php must not take down the whole platform at boot.
        $plugins = self::normalizePlugins($config['plugins'] ?? []);

        // Computed once, lazily, and reused everywhere it's needed (store_access,
        // require_card exemption list, ExternalAuthRoutes::register itself) so there
        // is exactly one derivation of these options, not several that could drift.
        // MUST stay lazy, not unconditional: buildAuthRouteOptions() throws when
        // AUTH_SERVICE_URL is unset (via resolveAuthServiceUrl), which is the normal,
        // valid state for T1 / pure-builtin platforms that never touch external auth
        // at all — calling it unconditionally would break every one of them.
        $authRouteOptions = null;
        $needsAuthRouteOptions = !empty($storeAccessConfig['enabled'])
            || !empty($requireCardConfig['enabled'])
            || $authMode === 'external'
            || $authMode === 'hybrid';
        if ($needsAuthRouteOptions) {
            $authRouteOptions = self::buildAuthRouteOptions($authConfig, $env);
        }

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

        // Tenancy strategy (§ TenancyStrategyInterface): explicit config wins over a
        // plugin-supplied one; with neither, GatewayTenantMiddleware defaults to
        // NoTenantStrategy itself — identical to pre-Phase-1 behavior.
        $tenancyStrategy = self::resolveTenancyStrategy($config['tenancy'] ?? [], $plugins);

        $router = new Router();
        $router->use(new LoggingMiddleware());
        // No '*' fallback here on purpose — Env::$ALLOWED_ORIGINS is a non-nullable
        // typed string (Env.php already resolves it from src/config/allowed-origins.php
        // and/or the ALLOWED_ORIGINS env var, defaulting to '' if neither is set), so
        // this never actually receives null; a literal '*' would also never work
        // anyway (CorsMiddleware matches the real Origin header by exact string, and
        // '*' is not a real origin any browser sends) while implying a match-all
        // behavior that would be unsafe to actually implement given
        // Access-Control-Allow-Credentials is always sent true. Unconfigured ->
        // empty array -> CorsMiddleware allows no origin (fail-closed).
        $router->use(new CorsMiddleware(
            array_values(array_filter(array_map('trim', explode(',', $env->ALLOWED_ORIGINS)))),
            explode(',', $env->ALLOWED_METHODS ?? 'GET,POST,PUT,PATCH,DELETE,OPTIONS')
        ));
        $router->use(new JwtAuthMiddleware(
            jwtHandler: $jwtHandler,
            excludedPaths: $jwtExcludedPaths
        ));
        $router->use(new GatewayTenantMiddleware($tenancyStrategy));

        // T3 url-tenant: StoreAccessMiddleware MUST run before SubscriptionMiddleware.
        // It extracts :storeId from the URL, validates membership via HTTP to the
        // auth service (source of truth), and sets GatewayClient tenant_id.
        if (!empty($storeAccessConfig['enabled'])) {
            // Merge auth service URL + platform code from auth config so the
            // caller doesn't have to repeat them in store_access config.
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

        // Plugin middleware (§ PluginInterface) — appended AFTER the platform's own
        // custom middleware, BEFORE require_card/tenant_url_match enforcement below,
        // so a plugin can never silently reorder a platform's own middleware
        // guarantees. Empty $plugins (the default) adds nothing.
        foreach ($plugins as $plugin) {
            foreach ($plugin->middleware() as $middleware) {
                if ($middleware instanceof MiddlewareInterface) {
                    $router->use($middleware);
                }
            }
        }

        // §5.1 tenant-context enforcement (framework-spec.md §5.1): a tenant-less
        // token cannot authorize a tenant-scoped route. Opt-in — off by default for
        // backward compat with platforms that still wire RequireCardMiddleware manually
        // (or a custom equivalent) via 'middleware' above.
        //
        // REGRESSION THIS FIXES (2026-07-05, real fleet incident): RequireCardMiddleware,
        // used bare (`new RequireCardMiddleware()`, the previously-documented usage), has
        // zero path awareness — it 403s ANY authenticated-but-tenantless request, which
        // includes ExternalAuthRoutes' own tier-2 routes (provision-tenant, select-tenant,
        // change-password, memberships, me — routes that intentionally take a passport,
        // never a card; invite-member was also one of these until it was removed
        // 2026-07-21, see DefaultTenantRouteProvider's class docblock). Wiring it
        // globally via THIS config key auto-derives the
        // exemption list from ExternalAuthRoutes::protectedPaths($authRouteOptions) — the
        // same config that defines those routes — so the list can never drift out of sync
        // with what's actually registered, and a platform never has to hand-maintain it.
        if (!empty($requireCardConfig['enabled'])) {
            $router->use(new RequireCardMiddleware(
                ExternalAuthRoutes::protectedPaths($authRouteOptions)
            ));
        }

        // §5.2 URL-echo enforcement (framework-spec.md §5.2): url.tenantId MUST
        // equal card.tenant_id. Opt-in — off by default for backward compat with
        // platforms that haven't adopted the canonical {tenantId} URL param yet.
        // Safe to wire globally: TenantUrlMatchMiddleware self-skips any route whose
        // matched pattern doesn't declare the configured param, so flat/non-tenant
        // routes (health, webhooks, admin auth, infra) are never affected.
        // Runs AFTER require_card: confirm a card exists before checking its tenant claim.
        $tenantUrlMatchConfig = $config['tenant_url_match'] ?? [];
        if (!empty($tenantUrlMatchConfig['enabled'])) {
            $router->use(new TenantUrlMatchMiddleware($tenantUrlMatchConfig['param'] ?? 'tenantId'));
        }

        // Register built-in refresh/logout routes when not using external-only auth
        if ($authMode !== 'external') {
            AuthRoutes::register($router, [
                'jwt_handler' => $jwtHandler,
                'prefix' => $authConfig['prefix'] ?? '/auth',
            ]);
        }

        // Register external auth proxy routes for external/hybrid modes
        if ($authMode === 'external' || $authMode === 'hybrid') {
            ExternalAuthRoutes::register($router, $authRouteOptions);
        }

        // Builtin Google OAuth popup flow — standalone platforms with no central
        // auth service (AUTH_MODE=builtin) opt in via auth.oauth.google.enabled.
        // See Auth\BuiltinOAuth\GoogleOAuthRoutes for what this registers and why.
        $googleOAuthConfig = $authConfig['oauth']['google'] ?? [];
        if ($authMode === 'builtin' && !empty($googleOAuthConfig['enabled'])) {
            GoogleOAuthRoutes::register($router, $googleOAuthConfig);
        }

        // Register subscription routes if subscription config is present
        if ($subscriptionEnabled) {
            SubscriptionRoutes::register($router, array_merge(
                $subscriptionConfig,
                ['platform_code' => $authConfig['platform']['code'] ?? $env->PLATFORM_CODE ?? '']
            ));
        }

        // Merge plugin-contributed routes UNDER the platform's own routes.php — a
        // platform's explicit route for the same METHOD+path always wins (§
        // PluginInterface precedence). Empty $plugins (the default) is a no-op merge.
        $router->loadRoutes(self::mergePluginRoutes($appRoutes, $plugins));

        // Fail-fast wiring guard (7.0.0, strict single-mode): if protected routes exist
        // but the typed-auth middleware are not (fully) wired, refuse to boot rather
        // than serve a silently-unprotected route. An un-migrated (old-schema) platform
        // gets a CLEAR, ACTIONABLE migration error here — never a bare stack trace.
        try {
            \StoneScriptPHP\Auth\Middleware\AuthMiddlewareRegistrar::assertFullyWired(
                $router->getGlobalMiddleware(),
                $router->getRouteMeta()
            );
        } catch (\StoneScriptPHP\Auth\Middleware\AuthWiringException $e) {
            self::renderBootConfigError($e->getMessage());
            return;
        }

        $response = $router->dispatch();

        // Set HTTP status code from ApiResponse when provided.
        // Middleware/error handlers set their own codes via http_response_code() directly.
        // This covers route handlers returning res_error(msg, 400) / res_not_ok(msg, 422) etc.
        if ($response->httpStatusCode !== null) {
            http_response_code($response->httpStatusCode);
        }

        // RedirectResponse / HtmlResponse are ApiResponse subclasses (see their
        // docblocks) so they reach here unchanged by Router/IRouteHandler — only
        // the final output step needs to know about them, everything upstream
        // still sees a plain ApiResponse.
        if ($response instanceof RedirectResponse) {
            header('Location: ' . $response->location);
        } elseif ($response instanceof HtmlResponse) {
            header('Content-Type: text/html; charset=utf-8');
            echo $response->html;
        } else {
            header('Content-Type: application/json');
            echo $response->toJson();
        }

        // Log request to STDERR for Docker/Swarm
        self::logRequest();
    }

    /**
     * Define STDIN, STDOUT, STDERR for PHP-FPM compatibility
     *
     * CLI mode defines these automatically, but PHP-FPM does not.
     */
    /**
     * Emit a clean, controlled boot-configuration error (HTTP 500) instead of letting
     * an uncaught exception surface as a bare stack trace. Used when the typed-auth
     * wiring guard rejects an un-migrated / half-wired platform at boot: the full
     * actionable message is logged AND returned in the JSON body so the operator sees
     * exactly what to fix. This is a developer-facing boot misconfiguration, not a
     * per-request error — the guidance is safe (and intended) to surface.
     */
    private static function renderBootConfigError(string $message): void
    {
        error_log('[StoneScriptPHP boot] ' . $message);
        http_response_code(500);
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo (new ApiResponse(
            'error',
            $message,
            ['error' => 'auth_wiring_required'],
            500
        ))->toJson();
        self::logRequest();
    }

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
    /**
     * Resolve the auth service network URL (JWKS fetch / login-register proxy target).
     *
     * Precedence: explicit `$authConfig['server']['url']` > non-empty `AUTH_SERVICE_URL`
     * env var. NO localhost default — a platform running in 'external'/'hybrid' AUTH_MODE
     * (or with store_access enabled) that hasn't configured this is a genuine
     * misconfiguration, not something to paper over. Silently defaulting to
     * 'http://localhost:3139' was the root cause of every platform's auth calls failing
     * with "Failed to connect to localhost port 3139" the moment their config file
     * layout didn't match what an older framework version expected — the failure never
     * showed up until the very first live auth call, deep inside a curl error, instead
     * of at boot where a misconfiguration belongs.
     *
     * @param array  $authConfig Auth section of the config array
     * @param Env    $env        Framework Env instance
     * @param string $mode       Resolved AUTH_MODE (used only for the error message)
     * @throws \RuntimeException When neither source yields a non-empty URL
     */
    private static function resolveAuthServiceUrl(array $authConfig, Env $env, string $mode): string
    {
        $serverUrl = $authConfig['server']['url'] ?? (!empty($env->AUTH_SERVICE_URL) ? $env->AUTH_SERVICE_URL : null);

        if ($serverUrl === null || trim($serverUrl) === '') {
            throw new \RuntimeException(
                "AUTH_SERVICE_URL is required when AUTH_MODE is '{$mode}' (or when store_access is "
                . "enabled) but is not set or empty. Set the AUTH_SERVICE_URL env var to the auth "
                . "service's network address (e.g. http://auth:3139 inside Docker), or pass "
                . "auth.server.url explicitly in the config array passed to Application::run(). "
                . "There is no default — silently falling back to localhost was the root cause of "
                . "a fleet-wide auth outage (auth calls failing with 'Failed to connect to localhost "
                . "port 3139')."
            );
        }

        return $serverUrl;
    }

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
        $serverUrl = self::resolveAuthServiceUrl($authConfig, $env, $mode);

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
        // Note: don't re-derive mode from $env->AUTH_MODE here (buildJwtHandler already
        // did that upstream in run()) — this is only used for the exception message text.
        $serverUrl  = self::resolveAuthServiceUrl($authConfig, $env, $authConfig['mode'] ?? 'external');
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

    /**
     * Validate and normalize `$config['plugins']` into a clean list of
     * PluginInterface instances.
     *
     * Non-fatal on bad input: a plugins.php that returns something malformed
     * (wrong type, non-PluginInterface object, etc.) logs a warning and drops
     * that entry rather than throwing — a boot-time crash here would take down
     * the whole platform over what is, for now, an entirely optional feature.
     *
     * @param mixed $rawPlugins Whatever `$config['plugins']` was (normally an array).
     * @return PluginInterface[]
     */
    private static function normalizePlugins(mixed $rawPlugins): array
    {
        if (!is_array($rawPlugins)) {
            return [];
        }

        $plugins = [];
        foreach ($rawPlugins as $plugin) {
            if ($plugin instanceof PluginInterface) {
                $plugins[] = $plugin;
            } else {
                log_warning('Application::run(): ignoring invalid plugins[] entry — expected a '
                    . 'PluginInterface instance, got ' . (is_object($plugin) ? get_class($plugin) : gettype($plugin)));
            }
        }

        return $plugins;
    }

    /**
     * Resolve the active TenancyStrategyInterface, or null (GatewayTenantMiddleware
     * defaults to NoTenantStrategy on null — today's exact behavior).
     *
     * Precedence: explicit `$config['tenancy']['strategy']` wins over any
     * plugin-supplied strategy. Among plugins, the first one (in registration
     * order) that returns a non-null strategy wins — plugins are expected to
     * coordinate if more than one wants to own tenancy resolution.
     *
     * @param array $tenancyConfig The 'tenancy' section of the config array.
     * @param PluginInterface[] $plugins
     * @return TenancyStrategyInterface|null
     */
    private static function resolveTenancyStrategy(array $tenancyConfig, array $plugins): ?TenancyStrategyInterface
    {
        if (isset($tenancyConfig['strategy']) && $tenancyConfig['strategy'] instanceof TenancyStrategyInterface) {
            return $tenancyConfig['strategy'];
        }

        foreach ($plugins as $plugin) {
            $strategy = $plugin->tenancyStrategy();
            if ($strategy instanceof TenancyStrategyInterface) {
                return $strategy;
            }
        }

        return null;
    }

    /**
     * Merge plugin-contributed routes underneath the platform's own $appRoutes,
     * per METHOD+path, so an explicit platform route always wins over a plugin
     * default. With no plugins (the default), returns $appRoutes unchanged.
     *
     * @param array $appRoutes The platform's own routes.php array.
     * @param PluginInterface[] $plugins
     * @return array
     */
    private static function mergePluginRoutes(array $appRoutes, array $plugins): array
    {
        if (empty($plugins)) {
            return $appRoutes;
        }

        $merged = $appRoutes;
        foreach ($plugins as $plugin) {
            foreach ($plugin->routes() as $method => $pluginMethodRoutes) {
                if (!is_array($pluginMethodRoutes)) {
                    continue;
                }
                // Plugin routes go UNDER the platform's own (array_merge: later args win),
                // so an existing platform-defined path for this method is never overridden.
                $merged[$method] = array_merge($pluginMethodRoutes, $merged[$method] ?? []);
            }
        }

        return $merged;
    }
}
