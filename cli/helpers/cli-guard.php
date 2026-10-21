<?php

declare(strict_types=1);

/**
 * Makes a CLI process safe WITHOUT relying on the framework bootstrap or autoloader being present:
 *  - pg_* E_WARNINGs (they carry the full PostgreSQL message, DETAIL included) become a value-free summary on STDERR;
 *  - exception arguments are dropped from traces;
 *  - when no exception handler is installed, an uncaught exception prints one value-free line.
 * Idempotent. Call it first thing in any CLI script that touches the database.
 */
if (!function_exists('cli_install_guard')) {
    function cli_install_guard(): void
    {
        static $installed = false;
        if ($installed) {
            return;
        }
        $installed = true;
        @ini_set('zend.exception_ignore_args', '1');

        $summarize = static function (string $prefix, string $raw): string {
            if (class_exists(\StoneScriptPHP\Persistence\SafeDbContext::class)) {
                return \StoneScriptPHP\Persistence\SafeDbContext::summarize($prefix, $raw);
            }
            return $prefix . ' (details withheld)';
        };

        set_error_handler(static function (int $no, string $str) use ($summarize): bool {
            if (preg_match('/^(pg_[a-z_]+)\(\)/', $str, $m) === 1) {
                fwrite(STDERR, $summarize('Database warning in ' . $m[1] . '()', $str) . "\n");
                return true;
            }
            return false;
        });

        $prev = set_exception_handler(null);
        if ($prev !== null) {
            set_exception_handler($prev);
            return;
        }
        set_exception_handler(static function (\Throwable $e) use ($summarize): never {
            $msg = class_exists(\StoneScriptPHP\Persistence\SafeDbContext::class)
                ? \StoneScriptPHP\Persistence\SafeDbContext::message($e)
                : '(details withheld)';
            fwrite(STDERR, (new \ReflectionClass($e))->getShortName() . ': ' . $msg . "\n");
            exit(1);
        });
    }
}
