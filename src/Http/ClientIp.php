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
 *  - `TRUSTED_PROXIES` (read through StoneScriptPHP\Env::secret(), so it honours
 *    .env, real env, `TRUSTED_PROXIES_FILE` and /run/secrets/trusted_proxies, and
 *    is immune to php-fpm `clear_env`): comma/space separated IPs, CIDRs or the
 *    keyword `private`.
 *  - or `trusted_proxies` (array|string) in the Application::run() config, which
 *    wins over the env var.
 *  - Empty / unset (the default) = trust nobody = REMOTE_ADDR only.
 *  - Resolution is LAZY (first use), so it always sees the fully loaded .env.
 *  - Precedence: configure() > config `trusted_proxies` > TRUSTED_PROXIES >
 *    legacy trust_proxy/TRUST_PROXY (=> `private`, deprecated). A legacy switch
 *    never overrides an explicit TRUSTED_PROXIES.
 *
 * WARNING about the `private` keyword
 * -----------------------------------
 * `private` is only safe when the PHP/nginx backend is reachable ONLY from the
 * proxy. With published docker ports, the docker userland-proxy (which makes
 * every public client arrive from the bridge gateway), shared docker bridges, or
 * any other path that lets a public client reach the backend from a private
 * source address, `private` makes every public client "trusted" and the
 * spoofing protection is void. Prefer explicit CIDRs (the specific bridge/VNet
 * range of YOUR proxy). `private` exists for convenience and legacy mapping.
 *
 * Validation of trust entries
 * ---------------------------
 * `*`, `/0` and any prefix shorter than public IPv4 /12 or IPv6 /32 are REJECTED.
 * A trust entry names proxies, not other people's networks. /12 is the broadest
 * public IPv4 block a CDN publishes (Cloudflare's smallest published range is
 * /12), so a floor of /12 admits every real CDN list while refusing public /8s
 * and /9-/11s that no proxy fleet owns; /32 is a typical IPv6 ISP/organisation
 * allocation. Private-space blocks (10/8, fc00::/7, fe80::/10, ...) are exempt
 * because they are not internet-routable. IPv4-mapped IPv6 entries are
 * normalised to IPv4.
 *
 * Process model: the list is resolved once per PHP process and memoised. It is
 * correct for php-fpm (per-worker, restarted on reload) and for long-lived
 * workers (Swoole, RoadRunner, FrankenPHP worker mode) alike, but a changed
 * TRUSTED_PROXIES only takes effect after the worker restarts / reloads.
 *
 * The 'unknown' bucket
 * --------------------
 * No usable REMOTE_ADDR (CLI, odd SAPI) yields 'unknown'. All such requests
 * deliberately share ONE rate-limit bucket (fail closed: they are limited
 * together rather than bypassing the limit). Over HTTP this indicates a broken
 * SAPI and is logged (throttled).
 */
final class ClientIp
{
    /** @var string[]|null explicit override (configure()); null = read env */
    private static ?array $override = null;

    /** @var string[]|null from Application::run() config key `trusted_proxies` */
    private static ?array $configProxies = null;

    private static ?bool $configLegacy = null;

    /** @var string[]|null memoised effective list (only cached when env was readable) */
    private static ?array $effective = null;

    private static int $lastHintLog = 0;
    private static int $lastUnknownLog = 0;
    private static int $lastEnvErrLog = 0;

    /** @var array{key:string,list:string[]}|null last expanded trust list */
    private static ?array $expandMemo = null;
    private static bool $legacyNoticed = false;

    /** Smallest allowed trust-entry prefix (see class docblock). */
    private const MIN_BITS_V4 = 12;
    private const MIN_BITS_V6 = 32;

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
        $server = $_SERVER;
        $ip = self::resolve($server, $proxies);

        if ($ip === 'unknown') {
            self::maybeLogUnknown();
        } elseif ($proxies === []) {
            self::maybeHintMisconfig($ip, $server);
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

        $trusted = self::expandMemo($trustedProxies);
        if ($trusted === [] || !self::isTrusted($peer, $trusted)) {
            return $peer;
        }

        $xff = trim((string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($xff === '') {
            return $peer;
        }

        $hops = array_map('trim', explode(',', $xff));
        for ($i = count($hops) - 1; $i >= 0; $i--) {
            $ip = self::parseHop($hops[$i]);
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
     * The effective trusted-proxy list, resolved lazily on first use so it sees the
     * fully loaded .env. Invalid / catch-all entries are dropped (and logged once).
     *
     * @return string[]
     */
    public static function trustedProxies(): array
    {
        if (self::$effective !== null) {
            return self::$effective;
        }
        if (self::$override !== null) {
            return self::$effective = self::$override;
        }
        if (self::$configProxies !== null) {
            return self::$effective = self::$configProxies;
        }

        $raw = self::env('TRUSTED_PROXIES');
        if ($raw === null) {
            return []; // Env not readable yet (boot error): trust nobody, do not cache
        }
        if ($raw !== '') {
            return self::$effective = self::sanitize(self::split($raw)); // explicit: never shadowed
        }

        $legacy = self::$configLegacy;
        if ($legacy === null) {
            $envLegacy = self::env('TRUST_PROXY');
            if ($envLegacy === null) {
                return [];
            }
            $legacy = $envLegacy !== '' && !in_array(strtolower($envLegacy), ['false', '0', 'no', 'off'], true);
        }
        if ($legacy) {
            if (!self::$legacyNoticed) {
                self::$legacyNoticed = true;
                error_log('[StoneScriptPHP] DEPRECATED: trust_proxy / TRUST_PROXY is replaced by TRUSTED_PROXIES. '
                    . 'Treating it as TRUSTED_PROXIES=private (unsafe if the backend port is reachable by '
                    . 'anything but the proxy). Set TRUSTED_PROXIES explicitly, preferably to the proxy CIDR.');
            }
            return self::$effective = ['private'];
        }
        return self::$effective = [];
    }

    /**
     * Set the trusted-proxy list explicitly (array or comma-separated string).
     * Pass null to go back to config/env resolution.
     *
     * @param string[]|string|null $proxies
     */
    public static function configure(array|string|null $proxies): void
    {
        self::$effective = null;
        if ($proxies === null) {
            self::$override = null;
            return;
        }
        self::$override = self::sanitize(is_string($proxies) ? self::split($proxies) : array_map('strval', $proxies));
    }

    /**
     * Called by Application::run() with the full app config. Only RECORDS config:
     * nothing is read from the environment here (that happens lazily, after .env
     * is loaded), so call order relative to Env can never matter.
     *
     * @param array<string,mixed> $config
     */
    public static function bootstrap(array $config): void
    {
        self::$effective = null;
        self::$configProxies = null;
        self::$configLegacy = null;

        if (array_key_exists('trusted_proxies', $config)) {
            $v = $config['trusted_proxies'];
            self::$configProxies = self::sanitize(
                is_string($v) ? self::split($v) : (is_array($v) ? array_map('strval', $v) : [])
            );
        }
        $legacy = $config['request_logging']['trust_proxy'] ?? null;
        self::$configLegacy = $legacy === null ? null : (bool) $legacy;
    }

    /** @internal test hook */
    public static function reset(): void
    {
        self::$override = null;
        self::$configProxies = null;
        self::$configLegacy = null;
        self::$effective = null;
        self::$lastHintLog = 0;
        self::$lastUnknownLog = 0;
        self::$lastEnvErrLog = 0;
        self::$expandMemo = null;
        self::$legacyNoticed = false;
    }

    /**
     * Network prefix used where a fingerprint should survive small address changes
     * (CSRF): IPv4 -> first three octets ("a.b.c"), IPv6 -> its /64, IPv4-mapped
     * IPv6 -> as IPv4, anything unusable -> 'unknown'.
     */
    public static function networkPrefix(string $ip): string
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return 'unknown';
        }
        if (strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
            $bin = substr($bin, 12);
        }
        if (strlen($bin) === 4) {
            return ord($bin[0]) . '.' . ord($bin[1]) . '.' . ord($bin[2]);
        }
        return (string) inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
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
    /** @param array<string,mixed> $server */
    private static function maybeHintMisconfig(string $ip, array $server, ?int $now = null): void
    {
        if ($ip === 'unknown' || !self::isInternal($ip)) {
            return;
        }
        if (empty($server['HTTP_X_FORWARDED_FOR']) && empty($server['HTTP_X_REAL_IP'])) {
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

    /**
     * Read a config value through the framework Env accessor (.env, env,
     * KEY_FILE, /run/secrets). null = Env could not be built (boot error).
     */
    private static function env(string $key): ?string
    {
        try {
            return trim((string) \StoneScriptPHP\Env::secret($key));
        } catch (\Throwable $e) {
            // Never silent: an unreadable Env means the trust list is empty (safe, but a
            // proxied platform will see one shared bucket). Throttled to once a minute.
            $now = time();
            if (self::$lastEnvErrLog === 0 || $now - self::$lastEnvErrLog >= 60) {
                self::$lastEnvErrLog = $now;
                error_log('[StoneScriptPHP] client_ip(): could not read ' . $key . ' (' . get_class($e) . ': '
                    . $e->getMessage() . '); trusting no proxy (REMOTE_ADDR only) until Env is readable.');
            }
            return null;
        }
    }

    private static function maybeLogUnknown(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        $now = time();
        if (self::$lastUnknownLog !== 0 && $now - self::$lastUnknownLog < 60) {
            return;
        }
        self::$lastUnknownLog = $now;
        error_log('[StoneScriptPHP] client_ip(): no usable REMOTE_ADDR on an HTTP request; all such requests '
            . 'share one "unknown" rate-limit bucket (fail closed). Check the web server / SAPI configuration.');
    }

    /** @return string[] */
    private static function split(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        return $parts === false ? [] : $parts;
    }

    /**
     * @param string[] $entries
     * @return string[] canonical entries (invalid/catch-all dropped + logged)
     */
    private static function sanitize(array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            $norm = self::normalizeEntry($entry);
            if ($norm === null) {
                error_log('[StoneScriptPHP] TRUSTED_PROXIES: ignoring invalid, catch-all or too-broad entry "'
                    . $entry . '" (min prefix: public IPv4 /' . self::MIN_BITS_V4 . ', IPv6 /' . self::MIN_BITS_V6 . ')');
                continue;
            }
            $out[] = $norm;
        }
        return array_values(array_unique($out));
    }

    /**
     * Validate and canonicalise one trust entry. Returns null if invalid, a
     * catch-all, or broader than the prefix floor (unless inside private space).
     * IPv4-mapped IPv6 entries are converted to IPv4.
     */
    private static function normalizeEntry(string $entry): ?string
    {
        $entry = trim($entry);
        if (strtolower($entry) === 'private') {
            return 'private';
        }
        [$net, $bits] = array_pad(explode('/', $entry, 2), 2, null);
        $bin = @inet_pton($net);
        if ($bin === false) {
            return null;
        }
        $max = strlen($bin) * 8;
        if ($bits !== null && (!ctype_digit($bits) || strlen($bits) > 3)) {
            return null;
        }
        $b = $bits === null ? $max : (int) $bits;

        // IPv4-mapped IPv6 entry -> IPv4 (so it can match the normalised peer/hops).
        if (strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
            if ($b < 96) {
                return null;
            }
            $bin = substr($bin, 12);
            $b -= 96;
            $max = 32;
        }
        if ($b < 1 || $b > $max) {
            return null;
        }
        $floor = $max === 32 ? self::MIN_BITS_V4 : self::MIN_BITS_V6;
        $canon = (string) inet_ntop($bin);
        if ($b < $floor && !self::insidePrivate($canon, $b)) {
            return null;
        }
        return $b === $max ? $canon : $canon . '/' . $b;
    }

    /** Is network $ip/$bits wholly inside one of the private ranges? */
    private static function insidePrivate(string $ip, int $bits): bool
    {
        foreach (self::PRIVATE_RANGES as $range) {
            [, $rb] = explode('/', $range, 2);
            if ($bits >= (int) $rb && self::inCidr($ip, $range)) {
                return true;
            }
        }
        return false;
    }

    /**
     * expand() memoised on the exact input (the list is identical on every request of a process).
     *
     * @param string[] $entries
     * @return string[]
     */
    private static function expandMemo(array $entries): array
    {
        $key = implode("\n", array_map('strval', $entries));
        if (self::$expandMemo === null || self::$expandMemo['key'] !== $key) {
            self::$expandMemo = ['key' => $key, 'list' => self::expand($entries)];
        }
        return self::$expandMemo['list'];
    }

    /**
     * @param string[] $entries
     * @return string[] concrete IP/CIDR entries (keyword expanded, invalid dropped)
     */
    private static function expand(array $entries): array
    {
        $out = [];
        foreach ($entries as $e) {
            $n = self::normalizeEntry((string) $e);
            if ($n === null) {
                continue;
            }
            if ($n === 'private') {
                array_push($out, ...self::PRIVATE_RANGES);
            } else {
                $out[] = $n;
            }
        }
        return $out;
    }

    /**
     * One X-Forwarded-For hop: plain IP, "ipv4:port", "[ipv6]" or "[ipv6]:port"
     * (some load balancers append ports). Zone ids and anything else => null.
     */
    private static function parseHop(string $hop): ?string
    {
        $hop = trim($hop);
        if (preg_match('/^\[([^\]]+)\](?::\d{1,5})?$/', $hop, $m) === 1) {
            return self::valid($m[1]);
        }
        if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d{1,5}$/', $hop, $m) === 1) {
            return self::valid($m[1]);
        }
        return self::valid($hop);
    }

    /** Canonical spelling of an IP (lowercase compressed IPv6; IPv4-mapped IPv6 -> IPv4), or null if not an IP. */
    public static function canonical(string $ip): ?string
    {
        return self::valid($ip);
    }

    /** Validate + canonicalise (lowercase compressed IPv6; IPv4-mapped IPv6 -> IPv4). */
    private static function valid(string $ip): ?string
    {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $bin = inet_pton($ip);
        if ($bin === false) {
            return null;
        }
        if (strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
            $bin = substr($bin, 12);
        }
        return (string) inet_ntop($bin);
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
