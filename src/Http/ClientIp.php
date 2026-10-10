<?php

declare(strict_types=1);

namespace StoneScriptPHP\Http;

/**
 * ClientIp - the one place the framework decides "what IP is this request from?".
 *
 * Every IP-keyed protection (RateLimiter, RateLimitMiddleware, CSRF, hCaptcha,
 * ProofOfWork, RefreshRoute, analytics, request logging) reaches this through
 * the client_ip() helper.
 *
 * Safe by default
 * ---------------
 *  - REMOTE_ADDR (the TCP peer, set by the web server, NOT client-controllable)
 *    is the answer unless the peer is a configured trusted proxy.
 *  - Forwarding headers are consulted ONLY when the peer is a trusted proxy.
 *    X-Forwarded-For is walked RIGHT-to-LEFT, skipping trusted proxies; the first
 *    untrusted hop is the client. Entries a client prepends (to the left) never
 *    matter. This is the Symfony / Laravel TrustedProxies algorithm.
 *  - X-Real-IP and every other single-value header is NOT consulted: a proxy
 *    that forgets to overwrite it would hand the client control of its own IP.
 *  - A malformed hop in the chain is never guessed at: we fall back to the peer.
 *
 * Configuration (the trusted-proxy list)
 * --------------------------------------
 *  - `TRUSTED_PROXIES` env var: comma/space separated list of IPs, CIDRs or the
 *    keyword `private` (loopback + RFC1918 + CGNAT + IPv6 ULA/link-local).
 *  - or `trusted_proxies` (array|string) in the Application::run() config,
 *    which wins over the env var.
 *  - Empty / unset (the default) = trust nobody = REMOTE_ADDR only.
 *  - `*`, `0.0.0.0/0` and `::/0` are REJECTED (trusting everybody is the bug
 *    this class exists to prevent) and logged.
 */
final class ClientIp
{
    /** @var string[]|null explicit override (configure()); null = read env */
    private static ?array $override = null;

    /** @var array{raw:string,list:string[]}|null parsed-env cache */
    private static ?array $envCache = null;

    private static int $lastHintLog = 0;

    /** CIDRs behind the `private` keyword. */
    private const PRIVATE_RANGES = [
        '127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16',
        '100.64.0.0/10', '169.254.0.0/16',
        '::1/128', 'fc00::/7', 'fe80::/10',
    ];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /** The client IP of the current request ($_SERVER + configured proxies). */
    public static function current(): string
    {
        $proxies = self::trustedProxies();
        $ip = self::resolve($_SERVER, $proxies);

        if ($proxies === []) {
            self::maybeHintMisconfig($ip);
        }
        return $ip;
    }

    /**
     * Pure resolution - no globals, no config. Returns 'unknown' when there is
     * no usable REMOTE_ADDR (e.g. CLI).
     *
     * @param array<string,mixed> $server
     * @param string[]            $trustedProxies IPs, CIDRs or `private`
     */
    public static function resolve(array $server, array $trustedProxies = []): string
    {
        $peer = self::valid((string) ($server['REMOTE_ADDR'] ?? ''));
        if ($peer === null) {
            return 'unknown';
        }

        $trusted = self::expand($trustedProxies);
        if ($trusted === [] || !self::isTrusted($peer, $trusted)) {
            return $peer;
        }

        $xff = trim((string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($xff === '') {
            return $peer;
        }

        $hops = array_map('trim', explode(',', $xff));
        for ($i = count($hops) - 1; $i >= 0; $i--) {
            $ip = self::valid($hops[$i]);
            if ($ip === null) {
                return $peer; // garbage in the chain: do not guess
            }
            if (!self::isTrusted($ip, $trusted)) {
                return $ip;
            }
        }
        return $peer; // every hop is a trusted proxy
    }

    /**
     * Rate-limit bucket identity: IPv4 as is; IPv4-mapped IPv6 as its IPv4; any
     * other IPv6 collapsed to its /64 (a client is routinely handed a whole /64
     * and could otherwise rotate addresses past every limit).
     */
    public static function rateKey(string $ip): string
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return $ip;
        }
        if (strlen($bin) === 16) {
            if (str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
                return (string) inet_ntop(substr($bin, 12));
            }
            return (string) inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
        }
        return $ip;
    }

    /** Loopback / private / link-local / unresolved: a visitor is never legitimately this. */
    public static function isInternal(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true;
        }
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /**
     * The effective trusted-proxy list (explicit configure() > env). Entries are
     * validated; invalid or catch-all entries are dropped (and logged once).
     *
     * @return string[]
     */
    public static function trustedProxies(): array
    {
        if (self::$override !== null) {
            return self::$override;
        }
        $raw = self::envValue('TRUSTED_PROXIES');
        if (self::$envCache === null || self::$envCache['raw'] !== $raw) {
            self::$envCache = ['raw' => $raw, 'list' => self::sanitize(self::split($raw))];
        }
        return self::$envCache['list'];
    }

    /**
     * Set the trusted-proxy list explicitly (array or comma-separated string).
     * Pass null to go back to the TRUSTED_PROXIES env var.
     *
     * @param string[]|string|null $proxies
     */
    public static function configure(array|string|null $proxies): void
    {
        if ($proxies === null) {
            self::$override = null;
            return;
        }
        self::$override = self::sanitize(is_string($proxies) ? self::split($proxies) : array_map('strval', $proxies));
    }

    /**
     * Called by Application::run() with the full app config.
     *
     * Order: config['trusted_proxies'] > TRUSTED_PROXIES env. Legacy
     * `request_logging.trust_proxy` / TRUST_PROXY=true (the old unsafe
     * "trust X-Real-IP" switch) maps to trusting the `private` ranges only, with
     * a deprecation notice - so a platform behind a local/private proxy keeps
     * working, but a public client can no longer forge its IP.
     *
     * @param array<string,mixed> $config
     */
    public static function bootstrap(array $config): void
    {
        if (array_key_exists('trusted_proxies', $config)) {
            $v = $config['trusted_proxies'];
            self::configure(is_array($v) || is_string($v) ? $v : []);
            return;
        }

        self::configure(null);
        if (self::trustedProxies() !== []) {
            return;
        }

        $legacy = $config['request_logging']['trust_proxy'] ?? null;
        if ($legacy === null) {
            $env = self::envValue('TRUST_PROXY');
            $legacy = $env !== '' && !in_array(strtolower($env), ['false', '0', 'no', 'off'], true);
        }
        if ((bool) $legacy) {
            error_log('[StoneScriptPHP] DEPRECATED: trust_proxy / TRUST_PROXY is replaced by TRUSTED_PROXIES. '
                . 'Treating it as TRUSTED_PROXIES=private. Set TRUSTED_PROXIES explicitly.');
            self::configure(['private']);
        }
    }

    /** @internal test hook */
    public static function reset(): void
    {
        self::$override = null;
        self::$envCache = null;
        self::$lastHintLog = 0;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Operator hint: the peer is a private/loopback address AND the request carries
     * forwarding headers AND no trusted proxies are configured. That is the exact
     * signature of "behind a proxy but TRUSTED_PROXIES not set" - every visitor
     * would share one rate-limit bucket. Throttled to once a minute per process.
     */
    private static function maybeHintMisconfig(string $ip, ?int $now = null): void
    {
        if ($ip === 'unknown' || !self::isInternal($ip)) {
            return;
        }
        if (empty($_SERVER['HTTP_X_FORWARDED_FOR']) && empty($_SERVER['HTTP_X_REAL_IP'])) {
            return;
        }
        $now ??= time();
        if (self::$lastHintLog !== 0 && $now - self::$lastHintLog < 60) {
            return;
        }
        self::$lastHintLog = $now;
        error_log('[StoneScriptPHP] client_ip(): request from internal peer ' . $ip
            . ' carries X-Forwarded-For but TRUSTED_PROXIES is not set; all visitors share this IP. '
            . 'Set TRUSTED_PROXIES to your reverse proxy address/CIDR (or `private`).');
    }

    private static function envValue(string $key): string
    {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        return is_string($v) ? trim($v) : '';
    }

    /** @return string[] */
    private static function split(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        return $parts === false ? [] : $parts;
    }

    /**
     * @param string[] $entries
     * @return string[]
     */
    private static function sanitize(array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (strtolower($entry) === 'private') {
                $out[] = 'private';
                continue;
            }
            if (!self::validEntry($entry)) {
                error_log('[StoneScriptPHP] TRUSTED_PROXIES: ignoring invalid or catch-all entry "' . $entry . '"');
                continue;
            }
            $out[] = $entry;
        }
        return array_values(array_unique($out));
    }

    private static function validEntry(string $entry): bool
    {
        [$net, $bits] = array_pad(explode('/', $entry, 2), 2, null);
        $bin = @inet_pton($net);
        if ($bin === false) {
            return false;
        }
        if ($bits === null) {
            return true;
        }
        if (!ctype_digit($bits)) {
            return false;
        }
        $b = (int) $bits;
        // /0 would trust the whole internet.
        return $b >= 1 && $b <= strlen($bin) * 8;
    }

    /**
     * @param string[] $entries
     * @return string[] concrete IP/CIDR entries (keyword expanded)
     */
    private static function expand(array $entries): array
    {
        $out = [];
        foreach ($entries as $e) {
            if (strtolower(trim($e)) === 'private') {
                array_push($out, ...self::PRIVATE_RANGES);
            } elseif (self::validEntry(trim($e))) {
                $out[] = trim($e);
            }
        }
        return $out;
    }

    /** Validate + normalise (IPv4-mapped IPv6 -> IPv4). */
    private static function valid(string $ip): ?string
    {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $bin = inet_pton($ip);
        if ($bin !== false && strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
            return (string) inet_ntop(substr($bin, 12));
        }
        return $ip;
    }

    /** @param string[] $list expanded concrete entries */
    private static function isTrusted(string $ip, array $list): bool
    {
        foreach ($list as $entry) {
            if (self::inCidr($ip, $entry)) {
                return true;
            }
        }
        return false;
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton((string) $net);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $max = strlen($ipBin) * 8;
        $bits = $bits === null ? $max : (int) $bits;
        if ($bits < 1 || $bits > $max) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }
}
