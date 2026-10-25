<?php

declare(strict_types=1);

namespace StoneScriptPHP\RequestLogging;

use StoneScriptPHP\Auth\AuthContext;
use StoneScriptDB\GatewayClient;

/**
 * Platform-level request logger.
 *
 * Persists one self-sufficient row to {platform}_main.request_logs per request —
 * regardless of success, uncaught exception, or fatal error — except health/monitor
 * probes that succeeded (§10). Rows are purged by `php stone request-logs:purge`.
 *
 * §1  Armed via register_shutdown_function() as the FIRST action in Application::run().
 * §2  Duration from INDEX_START_TIME; falls back to the start time passed to arm().
 * §3  Row assembled from $_SERVER + AuthContext (no Traefik dependency).
 * §4  Defensive client-IP derivation (trust_proxy-aware).
 * §5  Error class/message from RequestContext (stamped by ExceptionHandler).
 * §6  fastcgi_finish_request() before DB write; entire write is fail-open.
 * §7  Config-gated: request_logging.enabled (defaults true).
 * §9  X-Request-Id captured if present, else UUIDv4 generated.
 * §10 Probe skipping: successful requests to request_logging.skip_paths (default /health, /healthz)
 *     and successful HEAD requests are not logged; any failure (status >= 400, a recorded error) always is.
 * §11 The write is bounded: connect <= request_logging.connect_timeout_seconds (default 1), total <=
 *     request_logging.write_timeout_seconds (default 2). After 3 CONSECUTIVE failed writes this worker skips
 *     success rows for request_logging.failure_cooldown_seconds (default 30) - failure rows are still attempted
 *     (one probe per 5 s) - and reports ONE summarized error line per minute,
 *     so a slow or dead gateway can neither pin php-fpm workers nor flood the log.
 * Personal data held per row: see docs/REQUEST-LOGGING.md.
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

    public const DEFAULT_SKIP_PATHS        = ['/health', '/healthz'];
    public const DEFAULT_CONNECT_TIMEOUT_S = 1;
    public const DEFAULT_WRITE_TIMEOUT_S   = 2;
    public const DEFAULT_COOLDOWN_S        = 30;
    private const REPORT_INTERVAL_S        = 60;
    private const BREAKER_THRESHOLD        = 3;
    private const PROBE_INTERVAL_S         = 5;

    /** @var string[] */
    private static array $skipPaths = self::DEFAULT_SKIP_PATHS;
    private static int   $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT_S;
    private static int   $writeTimeout   = self::DEFAULT_WRITE_TIMEOUT_S;
    private static int   $cooldown       = self::DEFAULT_COOLDOWN_S;

    // Per-php-fpm-worker failure state (statics live as long as the worker).
    private static float  $suspendedUntil = 0.0;
    private static float  $nextProbeAt    = 0.0;
    private static int    $consecutiveFailures = 0;
    private static float  $lastReport     = 0.0;
    private static int    $failedSinceReport = 0;
    private static int    $skippedSinceReport = 0;

    /**
     * For testing: when set, called instead of the real GatewayClient write.
     * The closure receives the assembled row array for assertion.
     *
     * @var \Closure|null
     * @internal
     */
    private static ?\Closure $writeAdapter = null;

    /** For testing: receives the summarized failure line instead of log_error(). @internal */
    private static ?\Closure $failureSink = null;

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

        if (isset($loggingConfig['path_scrub_patterns']) && is_array($loggingConfig['path_scrub_patterns'])) {
            PathScrubber::setPatterns($loggingConfig['path_scrub_patterns']);
        }
        self::$skipPaths      = self::resolveSkipPaths($loggingConfig['skip_paths'] ?? null);
        self::$connectTimeout = self::clampSeconds($loggingConfig['connect_timeout_seconds'] ?? self::envValue('REQUEST_LOG_CONNECT_TIMEOUT'), self::DEFAULT_CONNECT_TIMEOUT_S);
        self::$writeTimeout   = max(self::$connectTimeout, self::clampSeconds($loggingConfig['write_timeout_seconds'] ?? self::envValue('REQUEST_LOG_WRITE_TIMEOUT'), self::DEFAULT_WRITE_TIMEOUT_S));
        self::$cooldown       = max(0, min(600, (int) ($loggingConfig['failure_cooldown_seconds'] ?? self::envValue('REQUEST_LOG_FAILURE_COOLDOWN') ?? self::DEFAULT_COOLDOWN_S)));

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
        if (!RequestContext::hasError()) {
            $last = error_get_last();
            if ($last !== null && in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                RequestContext::captureFatalError($last);
            }
        }

        // §10 — successful probes are noise (and a 1 Hz monitor would otherwise write 86k rows/day/endpoint)
        if (self::shouldSkip(
            (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
            (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'),
            self::currentStatus(),
            RequestContext::hasError() || RequestContext::getHandled() !== [],
            self::$skipPaths
        )) {
            return;
        }

        // §6 — release the client connection before the (potentially slow) DB write
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // §11 — breaker. It opens only after BREAKER_THRESHOLD CONSECUTIVE failed writes (one transient timeout
        // never costs a failure row). While open, success rows are skipped; failure rows (status >= 400 or a
        // recorded error) are still attempted, at most one per PROBE_INTERVAL_S, with the short timeout.
        $now = microtime(true);
        if (self::$suspendedUntil > $now) {
            $isFailure = self::currentStatus() >= 400 || RequestContext::hasError() || RequestContext::getHandled() !== [];
            if (!$isFailure || $now < self::$nextProbeAt) {
                self::$skippedSinceReport++;
                return;
            }
            self::$nextProbeAt = $now + self::PROBE_INTERVAL_S;
        }

        try {
            self::writeLog();
            self::$consecutiveFailures = 0;
            self::$suspendedUntil = 0.0;
        } catch (\Throwable $e) {
            // §6 — fail-open: swallow all errors, never become a new failure mode
            if (++self::$consecutiveFailures >= self::BREAKER_THRESHOLD) {
                self::$suspendedUntil = microtime(true) + self::$cooldown;
            }
            self::noteFailure(\StoneScriptPHP\Persistence\LogSanitizer::describe($e));
        }
    }

    // -------------------------------------------------------------------------
    // §10 probe skipping, §11 failure reporting — public/internal for unit tests
    // -------------------------------------------------------------------------

    /**
     * True when this request must NOT be logged: it succeeded (status < 400, no recorded error or handled
     * failure) AND it is a HEAD request or its path equals / sits under one of $skipPaths.
     *
     * @param string[] $skipPaths
     */
    public static function shouldSkip(string $method, string $path, int $status, bool $hadError, array $skipPaths): bool
    {
        if ($hadError || $status >= 400) {
            return false; // error capture is never skipped
        }
        if (strtoupper($method) === 'HEAD') {
            return true;
        }
        $path = '/' . trim($path, '/');
        foreach ($skipPaths as $skip) {
            if ($path === $skip || str_starts_with($path, $skip . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * skip_paths from config (array or comma list) else REQUEST_LOG_SKIP_PATHS else the defaults.
     * An explicit empty list means "log every path".
     *
     * @return string[] normalised: leading slash, no trailing slash
     */
    public static function resolveSkipPaths(mixed $configured): array
    {
        $raw = $configured ?? self::envValue('REQUEST_LOG_SKIP_PATHS');
        if ($raw === null) {
            return self::DEFAULT_SKIP_PATHS;
        }
        $items = is_array($raw) ? $raw : explode(',', (string) $raw);
        $out = [];
        foreach ($items as $item) {
            $item = trim((string) $item);
            if ($item !== '' && $item !== '/') {
                $out[] = '/' . trim($item, '/');
            }
        }
        return array_values(array_unique($out));
    }

    private static function currentStatus(): int
    {
        $raw = http_response_code();
        return ($raw === false || $raw === 0) ? (RequestContext::hasError() ? 500 : 200) : (int) $raw;
    }

    private static function envValue(string $name): ?string
    {
        $v = $_ENV[$name] ?? $_SERVER[$name] ?? null;
        return ($v === null || $v === '') ? null : (string) $v;
    }

    /** Whole seconds in 1..10; anything else -> $default. */
    private static function clampSeconds(mixed $v, int $default): int
    {
        if ($v === null || !is_numeric($v) || (int) $v < 1) {
            return $default;
        }
        return min(10, (int) $v);
    }

    /**
     * ONE summarized error line per REPORT_INTERVAL_S per worker, however many writes failed or were skipped.
     */
    private static function noteFailure(string $summary): void
    {
        self::$failedSinceReport++;
        $now = microtime(true);
        if ($now - self::$lastReport < self::REPORT_INTERVAL_S && self::$lastReport > 0.0) {
            return;
        }
        $line = sprintf(
            '[RequestLogger] request-log write failed (fail-open): %s; %d failed, %d skipped during cooldown since last report',
            $summary,
            self::$failedSinceReport,
            self::$skippedSinceReport
        );
        self::$failureSink !== null ? (self::$failureSink)($line) : log_error($line);
        self::$lastReport = $now;
        self::$failedSinceReport = 0;
        self::$skippedSinceReport = 0;
    }

    // -------------------------------------------------------------------------
    // IP resolution (§4) — public for unit tests
    // -------------------------------------------------------------------------

    /**
     * Client-IP derivation.
     *
     * @deprecated Use StoneScriptPHP\Http\ClientIp::current(). Kept for BC:
     *   trust_proxy=false -> REMOTE_ADDR only;
     *   trust_proxy=true  -> ClientIp::resolve() with the configured
     *                        TRUSTED_PROXIES, or the `private` ranges if none.
     * X-Real-IP is no longer honoured: it is a single client-forgeable header.
     */
    public static function resolveClientIp(bool $trustProxy): string
    {
        if (!$trustProxy) {
            return $_SERVER['REMOTE_ADDR'] ?? '';
        }
        $proxies = \StoneScriptPHP\Http\ClientIp::trustedProxies();
        $ip = \StoneScriptPHP\Http\ClientIp::resolve($_SERVER, $proxies !== [] ? $proxies : ['private']);
        return $ip === 'unknown' ? '' : $ip;
    }

    /** Same spoof-safe IP the rate limiters see; '' when unavailable. */
    private static function loggedClientIp(): string
    {
        $ip = \StoneScriptPHP\Http\ClientIp::current();
        return $ip === 'unknown' ? '' : $ip;
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
     * Receive the summarized failure line instead of log_error() (for unit tests).
     *
     * @internal
     */
    public static function setFailureSink(?\Closure $sink): void
    {
        self::$failureSink = $sink;
    }

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
        self::$failureSink   = null;
        PathScrubber::reset();
        self::$skipPaths     = self::DEFAULT_SKIP_PATHS;
        self::$connectTimeout = self::DEFAULT_CONNECT_TIMEOUT_S;
        self::$writeTimeout  = self::DEFAULT_WRITE_TIMEOUT_S;
        self::$cooldown      = self::DEFAULT_COOLDOWN_S;
        self::$suspendedUntil = 0.0;
        self::$nextProbeAt   = 0.0;
        self::$consecutiveFailures = 0;
        self::$lastReport    = 0.0;
        self::$failedSinceReport = 0;
        self::$skippedSinceReport = 0;
    }

    /** @internal */
    public static function isArmed(): bool        { return self::$armed; }
    /** @internal */
    public static function isEnabled(): bool      { return self::$enabled; }
    /** @internal */
    public static function isTrustProxy(): bool   { return self::$trustProxy; }
    /** @internal */
    public static function getPlatformCode(): string { return self::$platformCode; }
    /** @internal */
    public static function getWriteTimeouts(): array { return ['connect' => self::$connectTimeout, 'total' => self::$writeTimeout]; }
    /** @internal @return string[] */
    public static function getSkipPaths(): array  { return self::$skipPaths; }

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
            ? (RequestContext::hasError() ? 500 : 200)
            : (int) $statusRaw;

        // §3 — path without query string
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        $path = FailureText::path((string) $uri); // query dropped, secret-looking segments masked (PathScrubber)

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
            'host'          => FailureText::cut($_SERVER['HTTP_HOST'] ?? '', 253),
            'method'        => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
            'path'          => $path,
            'status'        => $status,
            'duration_ms'   => $durationMs,
            'client_ip'     => self::loggedClientIp(),
            'identity_id'   => $identityId,
            'tenant_id'     => $tenantId,
            'role'          => $role !== null ? FailureText::cut((string) $role, 100) : null,
            'error_class'   => RequestContext::getErrorClass(),
            'error_message' => RequestContext::getErrorMessage(),
            'user_agent'    => isset($_SERVER['HTTP_USER_AGENT'])
                ? FailureText::cut($_SERVER['HTTP_USER_AGENT'], 1000) : null,
            'referer'       => isset($_SERVER['HTTP_REFERER'])
                ? FailureText::cut(FailureText::path($_SERVER['HTTP_REFERER']), 2048) : null, // query string dropped (personal data)
        ];

        // A 4xx/5xx with NO recorded cause is a gap, never silence: say so explicitly so it can be queried and fixed.
        // (A request that only carries a handled-failure note keeps that class and message: it is a recorded cause.)
        if ($status >= 400 && !RequestContext::hasError() && RequestContext::getHandled() === []) {
            $row['error_class']   = RequestContext::CLASS_UNRECORDED;
            $row['error_message'] = 'HTTP ' . $status . ' returned without a recorded cause';
        }

        // Test injection path: bypass real gateway write
        if (self::$writeAdapter !== null) {
            (self::$writeAdapter)($row);
            return;
        }

        // §6 — table-missing guard: if gateway is not configured, fail-open to STDERR
        if (empty(self::$gatewayUrl) || empty(self::$gatewayPlatform)) {
            throw new \RuntimeException('gateway not configured (DB_GATEWAY_URL / DB_GATEWAY_PLATFORM)');
        }

        // Direct GatewayClient for the MAIN schema (not tenant)
        $client = new GatewayClient(
            self::$gatewayUrl,
            self::$gatewayPlatform,
            self::$gatewaySchema,
            null,   // no tenant schema for main-schema writes
            null    // no UUID override
        );

        // §11 — bounded: a slow gateway must never hold this worker for the client default (30 s / 10 s)
        $client->setConnectTimeout(self::$connectTimeout);
        $client->setTimeout(self::$writeTimeout);

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
