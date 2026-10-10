<?php

declare(strict_types=1);

namespace StoneScriptPHP\Support;

/**
 * The ONE way the framework emits a deprecation notice from a request path.
 *
 *  - Never throws: an error-to-exception handler (Symfony/Laravel/PHPUnit style) turns E_USER_DEPRECATED
 *    into an exception; that must never be able to turn a sign-in or a query into an outage.
 *  - Rate-limited: at most once per key per process, and under a long-lived SAPI (php-fpm) at most once
 *    per key per hour ACROSS requests and workers (APCu when available, otherwise a small stamp file
 *    in a private per-uid 0700 directory under sys_get_temp_dir(), never a shared predictable path), so production logs are not flooded. CLI processes are one-shot, so the
 *    per-process limit suffices there.
 */
final class DeprecationNotice
{
    private const INTERVAL_SECONDS = 3600;

    /** @var array<string, true> */
    private static array $seen = [];

    private static ?bool $crossRequestOverride = null;
    private static ?string $stampDirOverride = null;

    public static function emit(string $key, string $message): void
    {
        try {
            if (isset(self::$seen[$key])) {
                return;
            }
            self::$seen[$key] = true;
            if (!self::shouldEmitAcrossRequests($key)) {
                return;
            }
            trigger_error($message, E_USER_DEPRECATED);
        } catch (\Throwable $e) {
            // Deliberately swallowed: a notice must never break the request it is attached to.
            try {
                error_log('[StoneScriptPHP] deprecation notice (' . $key . ') could not be raised: ' . \StoneScriptPHP\Persistence\LogSanitizer::describe($e));
            } catch (\Throwable) {
            }
        }
    }

    /** Test seam: forget per-process state; optionally force the cross-request limiter on/off and its stamp dir. */
    public static function reset(?bool $crossRequest = null, ?string $stampDir = null): void
    {
        self::$seen = [];
        self::$crossRequestOverride = $crossRequest;
        self::$stampDirOverride = $stampDir;
    }

    /**
     * A per-uid directory (0700) under $base, never a predictable shared path: another local user could otherwise
     * pre-create the stamp file or a symlink at it. Returns null when the directory is not ours / not private.
     */
    private static function privateStampDir(string $base): ?string
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : (int) (getmyuid() ?: 0);
        $dir = rtrim($base, '/') . '/ssp-depr-' . $uid;
        if (is_link($dir)) {
            return null;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0700) && !is_dir($dir)) {
            return null;
        }
        $owner = @fileowner($dir);
        $perms = @fileperms($dir);
        if ($owner === false || $perms === false || $owner !== $uid) {
            return null;
        }
        if (($perms & 0077) !== 0 && !@chmod($dir, 0700)) {
            return null;
        }
        return $dir;
    }

    private static function shouldEmitAcrossRequests(string $key): bool
    {
        $limit = self::$crossRequestOverride ?? (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg');
        if (!$limit) {
            return true;
        }

        $id = 'ssp_depr_' . md5($key);
        if (self::$stampDirOverride === null && function_exists('apcu_add') && filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOLEAN)) {
            // apcu_add is atomic: false = someone already emitted within the TTL.
            return (bool) apcu_add($id, 1, self::INTERVAL_SECONDS);
        }

        $dir = self::privateStampDir(self::$stampDirOverride ?? sys_get_temp_dir());
        if ($dir === null) {
            return true; // no safe place for a stamp: emit (per-process limit still applies) rather than trust a shared path
        }
        $file = $dir . '/' . $id . '.stamp';
        $now = time();
        $mtime = @filemtime($file);
        if ($mtime !== false && $now - $mtime < self::INTERVAL_SECONDS) {
            return false;
        }
        @touch($file);
        return true;
    }
}
