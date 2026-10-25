<?php

declare(strict_types=1);

namespace StoneScriptPHP\Support;

/**
 * Refuses to boot on a PHP release below the patched floor for its minor line.
 *
 * Why this exists in addition to composer.json "php": Composer's generated
 * vendor/composer/platform_check.php only enforces the LOWEST bound of the "php" constraint
 * (here >= 8.2.35), so PHP 8.3.6 or 8.5.4 would pass it. This guard enforces the per-minor floors at
 * every boot (web, FPM, CLI, `php stone`, queue workers: anything that loads vendor/autoload.php).
 *
 * Floors (unauthenticated PHP RCE "PHP2Shell", CVE-2026-109618 / CVE-2026-109619):
 * 8.2.35, 8.3.36, 8.4.27, 8.5.12; any later minor line (8.6+, 9+) is accepted.
 *
 * Distro builds that backport fixes but keep an old version string (Debian/Ubuntu "8.3.6-0ubuntu...")
 * cannot be told apart from vulnerable builds by PHP_VERSION, so they are refused. Use upstream
 * (packages.sury.org / ondrej PPA) packages, whose version number is truthful. See UPGRADING-13.2.1.md.
 *
 * Escape hatch: CLI only (phpunit on a developer workstation), via STONESCRIPT_ALLOW_UNPATCHED_PHP=1.
 * It is NEVER honoured under a web SAPI (fpm, cgi, apache, embed...), and prints a warning each time.
 */
final class PhpRuntimeFloor
{
    /** minor line (PHP_MAJOR_VERSION * 100 + PHP_MINOR_VERSION) => lowest patched PHP_VERSION_ID */
    public const FLOORS = [
        802 => 80235,
        803 => 80336,
        804 => 80427,
        805 => 80512,
    ];

    /** Returns null when the runtime is acceptable, otherwise a precise operator-facing message. */
    public static function violation(int $versionId = PHP_VERSION_ID, string $versionString = PHP_VERSION): ?string
    {
        $line = intdiv($versionId, 100); // 80305 -> 803
        $floor = self::FLOORS[$line] ?? null;
        if ($floor === null) {
            if ($line < 802) {
                return "PHP $versionString is not supported (8.2 or newer, patched, is required).";
            }
            return null; // a minor line newer than every known floor (8.6+, 9+)
        }
        if ($versionId < $floor) {
            return "PHP $versionString is below the patched floor for the " . intdiv($line, 100) . '.' . ($line % 100)
                . ' line (' . self::floorString($floor) . ') and is exposed to the unauthenticated PHP RCE '
                . 'CVE-2026-109618 / CVE-2026-109619. Upgrade to an upstream PHP build (not a distro backport that '
                . 'keeps an old version number); see UPGRADING-13.2.1.md.';
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

    /**
     * Called from the autoload bootstrap. Exits (never returns) when the runtime is below the floor.
     * The parameters exist so a subprocess test can simulate an old version / a web SAPI; the bootstrap passes none.
     */
    public static function enforce(int $versionId = PHP_VERSION_ID, string $versionString = PHP_VERSION, string $sapi = PHP_SAPI, mixed $env = null): void
    {
        $problem = self::violation($versionId, $versionString);
        if ($problem === null) {
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
