<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Support\DeprecationNotice;

final class DeprecationNoticeTest extends TestCase
{
    protected function tearDown(): void
    {
        DeprecationNotice::reset();
        restore_error_handler();
    }

    public function test_never_throws_even_when_errors_become_exceptions(): void
    {
        DeprecationNotice::reset(false);
        set_error_handler(static function (int $no, string $msg): never {
            throw new \ErrorException($msg, 0, $no);
        });
        $prev = ini_set('error_log', '/dev/null');
        DeprecationNotice::emit('k1', 'boom');
        ini_set('error_log', (string) $prev);
        $this->addToAssertionCount(1); // reaching here = nothing escaped
    }

    public function test_once_per_key_per_process(): void
    {
        DeprecationNotice::reset(false);
        $n = 0;
        set_error_handler(function () use (&$n): bool {
            $n++;
            return true;
        }, E_USER_DEPRECATED);
        DeprecationNotice::emit('a', 'x');
        DeprecationNotice::emit('a', 'x');
        DeprecationNotice::emit('b', 'x');
        $this->assertSame(2, $n);
    }

    public function test_cross_request_limit_uses_a_stamp_file_for_an_hour(): void
    {
        $dir = sys_get_temp_dir() . '/ssp-depr-' . uniqid();
        mkdir($dir);
        $n = 0;
        set_error_handler(function () use (&$n): bool {
            $n++;
            return true;
        }, E_USER_DEPRECATED);

        DeprecationNotice::reset(true, $dir);
        DeprecationNotice::emit('k', 'x');
        $this->assertSame(1, $n);

        DeprecationNotice::reset(true, $dir); // a "new request"
        DeprecationNotice::emit('k', 'x');
        $this->assertSame(1, $n, 'suppressed within the hour');

        $private = $dir . '/ssp-depr-' . (function_exists('posix_geteuid') ? posix_geteuid() : getmyuid());
        $this->assertDirectoryExists($private, 'stamps live in a private per-uid directory, not directly in the shared temp dir');
        $this->assertSame(0700, fileperms($private) & 0777);
        $this->assertSame([], glob($dir . '/*.stamp') ?: [], 'no stamp file at a predictable shared path');
        foreach (glob($private . '/*.stamp') as $stamp) {
            touch($stamp, time() - 7200);
        }
        DeprecationNotice::reset(true, $dir);
        DeprecationNotice::emit('k', 'x');
        $this->assertSame(2, $n, 'emitted again after the interval');

        array_map('unlink', glob($private . '/*') ?: []);
        rmdir($private);
        rmdir($dir);
    }

    public function test_a_foreign_owned_or_symlinked_stamp_dir_is_not_trusted(): void
    {
        $dir = sys_get_temp_dir() . '/ssp-depr-' . uniqid();
        mkdir($dir);
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $target = $dir . '/elsewhere';
        mkdir($target);
        symlink($target, $dir . '/ssp-depr-' . $uid); // an attacker-planted symlink at the predictable name
        $n = 0;
        set_error_handler(function () use (&$n): bool {
            $n++;
            return true;
        }, E_USER_DEPRECATED);
        DeprecationNotice::reset(true, $dir);
        DeprecationNotice::emit('k', 'x');
        $this->assertSame(1, $n, 'still emits (per-process limit) instead of writing through the symlink');
        $this->assertSame([], glob($target . '/*') ?: [], 'nothing written through the symlink');
        unlink($dir . '/ssp-depr-' . $uid);
        rmdir($target);
        rmdir($dir);
    }
}
