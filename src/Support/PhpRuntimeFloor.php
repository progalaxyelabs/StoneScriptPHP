<?php

declare(strict_types=1);

namespace StoneScriptPHP\Support;

/**
 * Refuses to boot on a PHP release below the patched floor (v14: PHP 8.5.12).
 *
 * Why this exists in addition to composer.json "php": `composer install --ignore-platform-reqs`, a base image
 * swapped after the install, or a downgraded php-fpm package would skip the install-time check. This guard
 * re-checks at every boot (web, FPM, CLI, `php stone`, queue workers: anything that loads vendor/autoload.php).
 *
 * Floor (unauthenticated PHP RCE "PHP2Shell", CVE-2026-109618 / CVE-2026-109619): 8.5.12.
 * Any later release or minor line (8.5.13+, 8.6+, 9+) is accepted. PHP 8.2 - 8.4 are not supported by v14 at all.
 *
 * Distro builds that backport fixes but keep an old version string (Debian/Ubuntu "8.5.4-0ubuntu1.5") cannot be told
 * apart from vulnerable builds by PHP_VERSION alone. Two safe ways in (see docs/DISTRO-PATCHED-PHP.md):
 *   (a) the installed PHP package version (read from dpkg) is >= a per-distro minimum-patched version in DISTRO_PATCHED;
 *       an unknown distro / package / row, or any probe failure, REFUSES (fail closed);
 *   (b) an operator acknowledgement (STONESCRIPT_DISTRO_PATCH_ACK) naming every CVE id and the exact installed package
 *       version; it stops matching the moment the package changes.
 * Upstream builds (sury / ondrej / official docker image) are judged by PHP_VERSION as before.
 *
 * Escape hatch: CLI only (phpunit on a developer workstation), via STONESCRIPT_ALLOW_UNPATCHED_PHP=1.
 * It is NEVER honoured under a web SAPI (fpm, cgi, apache, embed...), and prints a warning each time.
 */
final class PhpRuntimeFloor
{
    /** minor line (PHP_MAJOR_VERSION * 100 + PHP_MINOR_VERSION) => lowest patched PHP_VERSION_ID */
    public const FLOORS = [
        805 => 80512,
    ];

    /** CVE ids the floor protects. An operator ack must list ALL of them (a CVE added later invalidates old acks). */
    public const CVES = ['CVE-2026-109618', 'CVE-2026-109619'];

    /**
     * Distro rows: "<os-release ID>:<VERSION_ID>" => [PHP minor line => [package, minimum patched package version]].
     * The package is one installed by every SAPI flavour (cli, fpm, apache) so the same row serves all of them.
     * A row is added ONLY after reading the distro's own security record for these CVEs; cite the source here.
     * No row = refuse. Do not add rows from memory.
     */
    public const DISTRO_PATCHED = [
        // Ubuntu 26.04 "resolute": php8.5 8.5.4-0ubuntu1.5 (resolute-security) "SECURITY UPDATE: Remote RCE via state
        // corruption issue", patches CVE-2026-109618_109619*.patch. Source: the package changelog
        // (/usr/share/doc/php8.5-common/changelog.Debian.gz, verified on a 26.04.1 host 2026-11-06) and
        // https://ubuntu.com/security/CVE-2026-109618 , https://ubuntu.com/security/CVE-2026-109619
        'ubuntu:26.04' => [
            805 => ['php8.5-common', '8.5.4-0ubuntu1.5'],
        ],
    ];

    /** Returns null when the runtime is acceptable, otherwise a precise operator-facing message. */
    public static function violation(int $versionId = PHP_VERSION_ID, string $versionString = PHP_VERSION): ?string
    {
        $line = intdiv($versionId, 100); // 80305 -> 803
        $floor = self::FLOORS[$line] ?? null;
        if ($floor === null) {
            if ($line < 805) {
                return "PHP $versionString is not supported: StoneScriptPHP 14 requires PHP 8.5.12 or newer; see docs/UPGRADING-14.0.md.";
            }
            return null; // a minor line newer than every known floor (8.6+, 9+)
        }
        if ($versionId < $floor) {
            return "PHP $versionString is below the patched floor for the " . intdiv($line, 100) . '.' . ($line % 100)
                . ' line (' . self::floorString($floor) . ') and is not provably patched against the '
                . 'unauthenticated PHP RCE CVE-2026-109618 / CVE-2026-109619. Upgrade to an upstream PHP build, or use a '
                . 'distro package listed as patched, or (distro backport) set STONESCRIPT_DISTRO_PATCH_ACK after '
                . 'checking your distro changelog; see docs/DISTRO-PATCHED-PHP.md.';
        }
        return null;
    }

    public static function floorString(int $floorId): string
    {
        return intdiv($floorId, 10000) . '.' . intdiv($floorId % 10000, 100) . '.' . ($floorId % 100);
    }

    /** True when the CLI-only developer override applies. */
    public static function overrideActive(string $sapi = PHP_SAPI, mixed $env = null): bool
    {
        if ($sapi !== 'cli' && $sapi !== 'phpdbg') {
            return false;
        }
        $v = $env ?? getenv('STONESCRIPT_ALLOW_UNPATCHED_PHP');
        return $v === '1' || $v === 1;
    }

    /** Debian/Ubuntu installed package version via an argv-array exec (no shell); null on any failure. */
    private static function run(array $argv): ?array
    {
        if (!function_exists('proc_open')) {
            return null;
        }
        $p = @proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin', 'LC_ALL' => 'C']);
        if (!is_resource($p)) {
            return null;
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $deadline = microtime(true) + 3.0; // bounded
        while (microtime(true) < $deadline) {
            $out .= (string) stream_get_contents($pipes[1], 4096);
            $st = proc_get_status($p);
            if (!$st['running']) {
                $out .= (string) stream_get_contents($pipes[1], 4096);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $rc = $st['exitcode'];
                proc_close($p);
                return [$rc, trim($out)];
            }
            usleep(10000);
        }
        proc_terminate($p, 9);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($p);
        return null;
    }

    /** @var array<string,?string> per-process cache */
    private static array $cache = [];

    /** "ubuntu:26.04" from /etc/os-release, or null. */
    public static function distroKey(): ?string
    {
        return self::$cache['distro'] ??= (static function (): ?string {
            $raw = @file_get_contents('/etc/os-release');
            if ($raw === false || !preg_match('/^ID=("?)([a-z0-9._-]+)\1$/m', $raw, $i) || !preg_match('/^VERSION_ID=("?)([0-9A-Za-z._-]+)\1$/m', $raw, $v)) {
                return null;
            }
            return $i[2] . ':' . $v[2];
        })();
    }

    /** Installed dpkg version of $package, or null (not installed / no dpkg / blocked). Package name is validated. */
    public static function installedPackageVersion(string $package): ?string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9+.-]*$/', $package)) {
            return null;
        }
        $key = 'pkg:' . $package;
        if (!array_key_exists($key, self::$cache)) {
            $r = self::run(['dpkg-query', '-W', '-f=${db:Status-Abbrev}|${Version}', $package]);
            $v = null;
            if ($r !== null && $r[0] === 0 && preg_match('/^ii \|([^\s|]+)$/', $r[1], $m)) {
                $v = $m[1];
            }
            self::$cache[$key] = $v;
        }
        return self::$cache[$key];
    }

    /** dpkg version comparison ($a >= $b); null when it cannot be decided. */
    public static function dpkgAtLeast(string $a, string $b): ?bool
    {
        $re = '/^[0-9A-Za-z.+~:-]+$/';
        if (!preg_match($re, $a) || !preg_match($re, $b)) {
            return null;
        }
        $r = self::run(['dpkg', '--compare-versions', $a, 'ge', $b]);
        return $r === null || $r[0] > 1 ? null : $r[0] === 0;
    }

    /** Resets caches (tests). */
    public static function resetCache(): void
    {
        self::$cache = [];
    }

    /**
     * Parses STONESCRIPT_DISTRO_PATCH_ACK = "CVE-A,CVE-B;<package>=<package-version>".
     * @return array{cves:string[],package:string,version:string}|null
     */
    public static function parseAck(mixed $raw): ?array
    {
        if (!is_string($raw) || !preg_match('/^\s*([A-Za-z0-9,\- ]+);\s*([a-z0-9][a-z0-9+.-]*)=([0-9A-Za-z.+~:-]+)\s*$/', $raw, $m)) {
            return null;
        }
        $cves = array_values(array_filter(array_map('trim', explode(',', $m[1]))));
        return ['cves' => $cves, 'package' => $m[2], 'version' => $m[3]];
    }

    /**
     * For a runtime that failed violation(): returns an acceptance line (why) when the distro package is provably
     * patched (design a) or the operator ack matches the installed package version (design b); null = still refuse.
     * $probe (tests): ['distro'=>?string,'version'=>callable(string):?string,'atLeast'=>callable(string,string):?bool].
     * @return array{kind:string,message:string}|null
     */
    public static function distroAcceptance(int $versionId = PHP_VERSION_ID, string $versionString = PHP_VERSION, mixed $ackRaw = null, ?array $probe = null): ?array
    {
        $line = intdiv($versionId, 100);
        if (!isset(self::FLOORS[$line])) {
            return null; // unsupported line: never rescued
        }
        $getVersion = $probe['version'] ?? [self::class, 'installedPackageVersion'];
        $atLeast = $probe['atLeast'] ?? [self::class, 'dpkgAtLeast'];
        $distro = array_key_exists('distro', $probe ?? []) ? $probe['distro'] : self::distroKey();

        // The package's upstream version must be the running PHP_VERSION, so a self-compiled PHP living next to a
        // patched distro package is not mistaken for it.
        $upstreamMatches = static fn (string $pkgVer): bool => str_starts_with($pkgVer, $versionString . '-') || $pkgVer === $versionString;

        // (a) table
        $row = $distro !== null ? (self::DISTRO_PATCHED[$distro][$line] ?? null) : null;
        if ($row !== null) {
            [$pkg, $min] = $row;
            $have = $getVersion($pkg);
            if ($have !== null && $upstreamMatches($have) && $atLeast($have, $min) === true) {
                return ['kind' => 'distro', 'message' => "StoneScriptPHP: accepted distro-patched build $pkg $have (minimum patched $min on $distro) covering " . implode(' / ', self::CVES) . '.'];
            }
        }

        // (b) operator ack
        $ack = self::parseAck($ackRaw ?? getenv('STONESCRIPT_DISTRO_PATCH_ACK'));
        if ($ack !== null) {
            $listed = array_map('strtoupper', $ack['cves']);
            $missing = array_diff(self::CVES, $listed);
            if ($missing === []) {
                $have = $getVersion($ack['package']);
                if ($have !== null && $have === $ack['version'] && $upstreamMatches($have)) {
                    return ['kind' => 'ack', 'message' => 'StoneScriptPHP: WARNING OPERATOR ACKNOWLEDGEMENT in force: PHP ' . $versionString . ' (package ' . $ack['package'] . ' ' . $have
                        . ') is run on the operator\'s attestation that it is patched against ' . implode(' / ', self::CVES) . '. NOT verified by the framework; re-check after every PHP package change.'];
                }
            }
        }
        return null;
    }

    /**
     * Called from the autoload bootstrap. Exits (never returns) when the runtime is below the floor.
     * The parameters exist so a subprocess test can simulate an old version / a web SAPI; the bootstrap passes none.
     */
    public static function enforce(int $versionId = PHP_VERSION_ID, string $versionString = PHP_VERSION, string $sapi = PHP_SAPI, mixed $env = null, mixed $ack = null, ?array $probe = null): void
    {
        $problem = self::violation($versionId, $versionString);
        if ($problem === null) {
            return;
        }
        $accepted = self::distroAcceptance($versionId, $versionString, $ack, $probe);
        if ($accepted !== null) {
            error_log('[StoneScriptPHP boot] ' . $accepted['message']);
            return;
        }
        if (self::overrideActive($sapi, $env)) {
            fwrite(STDERR, "WARNING (STONESCRIPT_ALLOW_UNPATCHED_PHP=1, CLI only): $problem\n");
            return;
        }
        error_log('[StoneScriptPHP boot] REFUSING TO BOOT: ' . $problem);
        if ($sapi === 'cli' || $sapi === 'phpdbg') {
            fwrite(STDERR, "StoneScriptPHP: REFUSING TO START.\n$problem\n");
            exit(1);
        }
        // Web: generic 503. Never tell an unauthenticated visitor anything about PHP, security or versions;
        // the detail is in the server error log only.
        if (!headers_sent()) {
            http_response_code(503);
            header('Content-Type: application/json');
            header('Retry-After: 3600');
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') {
            echo json_encode([
                'status' => 'error',
                'message' => 'Service temporarily unavailable',
                'error_code' => 'service_unavailable',
            ]);
        }
        exit(1);
    }
}
