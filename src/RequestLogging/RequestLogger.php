<?php

declare(strict_types=1);

namespace StoneScriptPHP\RequestLogging;

use StoneScriptPHP\Auth\AuthContext;
use StoneScriptDB\GatewayClient;

/**
 * Platform-level request logger.
 *
 * Persists one self-sufficient row to {platform}_main.request_logs on every
 * request — regardless of success, uncaught exception, or fatal error.
 *
 * §1  Armed via register_shutdown_function() as the FIRST action in Application::run().
 * §2  Duration from INDEX_START_TIME; falls back to the start time passed to arm().
 * §3  Row assembled from $_SERVER + AuthContext (no Traefik dependency).
 * §4  Defensive client-IP derivation (trust_proxy-aware).
 * §5  Error class/message from RequestContext (stamped by ExceptionHandler).
 * §6  fastcgi_finish_request() before DB write; entire write is fail-open.
 * §7  Config-gated: request_logging.enabled (defaults true).
 * §9  X-Request-Id captured if present, else UUIDv4 generated.
 *
 * @package StoneScriptPHP\RequestLogging
 */
class RequestLogger
{
    // ---- per-request static state, set by arm() ----

    private static bool   $armed        = false;
    private static bool   $enabled      = true;
    private static float  $startTime    = 0.0;
    private static bool   $trustProxy   = false;
    private static string $platformCode = '';

    // Gateway config captured at arm-time so the shutdown function can use them
    // without relying on env superglobals still being available.
    private static string $gatewayUrl      = '';
    private static string $gatewayPlatform = '';
    private static string $gatewaySchema   = 'main';

    /**
     * For testing: when set, called instead of the real GatewayClient write.
     * The closure receives the assembled row array for assertion.
     *
     * @var \Closure|null
     * @internal
     */
    private static ?\Closure $writeAdapter = null;

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Arm the logger — MUST be the FIRST action in Application::run().
     *
     * Registers the shutdown hook and captures immutable config so the hook
     * has everything it needs even if PHP globals are cleaned up at shutdown.
     *
     * §1 — registers the shutdown function.
     * §7 — reads request_logging.enabled and trust_proxy from config.
     *
     * @param array $config  The full Application::run() config array.
     * @param float $runStart microtime(true) captured at the top of run(); used
     *                        as fallback when INDEX_START_TIME is not defined.
     */
    public static function arm(array $config, float $runStart): void
    {
        $loggingConfig = $config['request_logging'] ?? [];

        self::$enabled = (bool)($loggingConfig['enabled'] ?? true);
        if (!self::$enabled) {
            return;
        }

        // §2 — prefer INDEX_START_TIME (defined in skeleton public/index.php)
        self::$startTime = defined('INDEX_START_TIME')
            ? (float) INDEX_START_TIME
            : $runStart;

        // §4 / §7 — trust_proxy: explicit config key > TRUST_PROXY env > false
        if (array_key_exists('trust_proxy', $loggingConfig)) {
            self::$trustProxy = (bool) $loggingConfig['trust_proxy'];
        } else {
            $envVal = $_ENV['TRUST_PROXY'] ?? $_SERVER['TRUST_PROXY'] ?? null;
            self::$trustProxy = ($envVal !== null
                && strtolower((string) $envVal) !== 'false'
                && $envVal !== '0'
                && $envVal !== '');
        }

        // Platform code: config > env superglobals
        self::$platformCode = (string)(
            $config['auth']['platform']['code']
            ?? $_ENV['PLATFORM_CODE']
            ?? $_SERVER['PLATFORM_CODE']
            ?? ''
        );

        // Gateway connection config — captured now so the shutdown fn is self-sufficient
        self::$gatewayUrl      = (string)($_ENV['DB_GATEWAY_URL']           ?? $_SERVER['DB_GATEWAY_URL']           ?? '');
        self::$gatewayPlatform = (string)($_ENV['DB_GATEWAY_PLATFORM']       ?? $_SERVER['DB_GATEWAY_PLATFORM']       ?? '');
        self::$gatewaySchema   = (string)($_ENV['DB_GATEWAY_SCHEMA_NAME']    ?? $_SERVER['DB_GATEWAY_SCHEMA_NAME']    ?? 'main');

        self::$armed = true;

        // §1 — shutdown function is the ONLY hook that survives success, exception, AND fatal
        register_shutdown_function([self::class, 'persistRequestLog']);
    }

    /**
     * Shutdown hook — persists the request log row.
     *
     * Called automatically at request end (PHP shutdown phase).
     * §1:  Fires on success, uncaught exception, AND fatal error.
     * §5:  Picks up fatal via error_get_last() when ExceptionHandler did not run first.
     * §6:  fastcgi_finish_request() before the DB write; entire write is fail-open.
     */
    public static function persistRequestLog(): void
    {
        if (!self::$armed) {
            return;
        }

        // §5 safety net: if no exception was captured by ExceptionHandler, check
        // for a fatal error that bypassed it (e.g. OOM, parse error in autoloader).
        if (RequestContext::getErrorClass() === null) {
            $last = error_get_last();
            if ($last !== null && in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                RequestContext::captureFatalError($last);
            }
        }

        // §6 — release the client connection before the (potentially slow) DB write
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        try {
            self::writeLog();
        } catch (\Throwable $e) {
            // §6 — fail-open: swallow all errors, never become a new failure mode
            error_log('[RequestLogger] DB write failed (fail-open): ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // IP resolution (§4) — public for unit tests
    // -------------------------------------------------------------------------

    /**
     * Defensive client-IP derivation.
     *
     * trust_proxy = true  → X-Real-IP (set by edge proxy); falls back to the
     *                        rightmost entry in X-Forwarded-For, then REMOTE_ADDR.
     * trust_proxy = false → REMOTE_ADDR only (safe for standalone installs where
     *                        XFF headers can be spoofed by the client).
     *
     * §4.
     */
    public static function resolveClientIp(bool $trustProxy): string
    {
        if (!$trustProxy) {
            return $_SERVER['REMOTE_ADDR'] ?? '';
        }

        // X-Real-IP: single trusted IP set by the edge proxy (Nginx / Traefik)
        $realIp = trim($_SERVER['HTTP_X_REAL_IP'] ?? '');
        if ($realIp !== '') {
            return $realIp;
        }

        // X-Forwarded-For: "client, proxy1, proxy2"
        // Rightmost entry is added by the most-recently-trusted proxy.
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff !== '') {
            $parts = array_map('trim', explode(',', $xff));
            $rightmost = end($parts);
            if ($rightmost !== false && $rightmost !== '') {
                return $rightmost;
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '';
    }

    // -------------------------------------------------------------------------
    // UUID generation (§9) — public for unit tests
    // -------------------------------------------------------------------------

    /**
     * Generate a RFC-4122 version-4 UUID.
     */
    public static function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    // -------------------------------------------------------------------------
    // Test helpers
    // -------------------------------------------------------------------------

    /**
     * Inject a write adapter (for unit tests).
     *
     * The closure receives the assembled row array and can throw to simulate
     * gateway failures. When null, the real GatewayClient is used.
     *
     * @param \Closure|null $adapter fn(array $row): void
     * @internal
     */
    public static function setWriteAdapter(?\Closure $adapter): void
    {
        self::$writeAdapter = $adapter;
    }

    /**
     * Reset all static state between tests.
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$armed         = false;
        self::$enabled       = true;
        self::$startTime     = 0.0;
        self::$trustProxy    = false;
        self::$platformCode  = '';
        self::$gatewayUrl    = '';
        self::$gatewayPlatform = '';
        self::$gatewaySchema = 'main';
        self::$writeAdapter  = null;
    }

    /** @internal */
    public static function isArmed(): bool        { return self::$armed; }
    /** @internal */
    public static function isEnabled(): bool      { return self::$enabled; }
    /** @internal */
    public static function isTrustProxy(): bool   { return self::$trustProxy; }
    /** @internal */
    public static function getPlatformCode(): string { return self::$platformCode; }

    // -------------------------------------------------------------------------
    // Private — row assembly and DB write
    // -------------------------------------------------------------------------

    /**
     * Assemble the row and write it. Called only from persistRequestLog().
     *
     * §3 — self-sufficient row (no Traefik dependency).
     * §6 — table-missing / gateway-down is caught by the caller's try/catch.
     */
    private static function writeLog(): void
    {
        $startTime  = self::$startTime > 0.0 ? self::$startTime : microtime(true);
        $durationMs = round((microtime(true) - $startTime) * 1000, 2);

        // §9 — request_id: use edge-injected header if present, else generate
        $requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? self::generateUuid();

        // §3 — status: http_response_code() returns false before any code is set
        $statusRaw = http_response_code();
        $status    = ($statusRaw === false || $statusRaw === 0)
            ? (RequestContext::getErrorClass() !== null ? 500 : 200)
            : (int) $statusRaw;

        // §3 — path without query string
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        // §2 — occurred_at with microsecond precision, UTC
        $occurred_at = self::formatTimestamp($startTime);

        // §3 — identity: NEVER required; null for unauthenticated requests
        $identityId = null;
        $tenantId   = null;
        $role       = null;
        if (AuthContext::check()) {
            $user       = AuthContext::getUser();
            $identityId = ($user?->user_id !== '') ? ($user?->user_id ?? null) : null;
            $tenantId   = $user?->tenant_id ?? null;
            $role       = $user?->role_id ?? $user?->user_role ?? null;
        }

        $row = [
            'request_id'    => (string) $requestId,
            'occurred_at'   => $occurred_at,
            'platform_code' => self::$platformCode,
            'host'          => substr($_SERVER['HTTP_HOST'] ?? '', 0, 253),
            'method'        => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
            'path'          => substr((string) $path, 0, 2048),
            'status'        => $status,
            'duration_ms'   => $durationMs,
            'client_ip'     => self::resolveClientIp(self::$trustProxy),
            'identity_id'   => $identityId,
            'tenant_id'     => $tenantId,
            'role'          => $role !== null ? substr($role, 0, 100) : null,
            'error_class'   => RequestContext::getErrorClass(),
            'error_message' => RequestContext::getErrorMessage(),
            'user_agent'    => isset($_SERVER['HTTP_USER_AGENT'])
                ? substr($_SERVER['HTTP_USER_AGENT'], 0, 1000) : null,
            'referer'       => isset($_SERVER['HTTP_REFERER'])
                ? substr($_SERVER['HTTP_REFERER'], 0, 2048) : null,
        ];

        // Test injection path: bypass real gateway write
        if (self::$writeAdapter !== null) {
            (self::$writeAdapter)($row);
            return;
        }

        // §6 — table-missing guard: if gateway is not configured, fail-open to STDERR
        if (empty(self::$gatewayUrl) || empty(self::$gatewayPlatform)) {
            error_log('[RequestLogger] Gateway not configured — request log skipped');
            return;
        }

        // Direct GatewayClient for the MAIN schema (not tenant)
        $client = new GatewayClient(
            self::$gatewayUrl,
            self::$gatewayPlatform,
            self::$gatewaySchema,
            null,   // no tenant schema for main-schema writes
            null    // no UUID override
        );

        // §6 — if the function / table does not exist yet (platform not migrated),
        // GatewayClient throws and the caller's try/catch fails-open. No DDL here.
        $client->callFunction('rl_insert_request_log', $row);
    }

    /**
     * Format a microtime(true) float as a UTC timestamptz string with microseconds.
     *
     * @param float $ts microtime(true) value
     * @return string e.g. "2026-06-29 14:35:22.123456+00:00"
     */
    private static function formatTimestamp(float $ts): string
    {
        $secs  = (int) $ts;
        $usecs = (int) (($ts - $secs) * 1_000_000);
        return gmdate('Y-m-d H:i:s', $secs)
            . '.'
            . sprintf('%06d', $usecs)
            . '+00:00';
    }
}
