<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\E2E;

use PHPUnit\Framework\TestCase;

/**
 * Base for the real-gateway end-to-end suite.
 *
 * These tests drive client + framework through a REAL StoneScriptDB gateway in front of a REAL PostgreSQL. They
 * need docker and a built gateway binary, so they only run when the stack is up (tests/E2E/run-e2e.sh does that).
 * Without it they SKIP LOUDLY: a banner on STDERR plus a skip message that says E2E did not run. Set
 * STONESCRIPT_E2E_REQUIRE=1 (CI jobs that are supposed to have docker) to turn a missing stack into a hard FAILURE.
 */
abstract class RealGatewayTestCase extends TestCase
{
    /** Customer-grade values planted in every failing call. */
    protected const EMAIL = 'jane.sentinel@e2e.test';
    protected const PHONE = '+919876500123';
    protected const HASH = '$2y$10$sentinelhashvalue';

    /** Strings that must never appear in anything a developer, a log or a terminal can see. */
    protected const FORBIDDEN = [
        'jane.sentinel', '919876500123', 'sentinelhashvalue', 'seed.sentinel', 'mig.sentinel',
        'Key (', 'DETAIL', 'HINT', 'Failing row', 'is not present in table',
        'smuggled_key', 'smuggled_col',
    ];

    protected static string $url = '';
    protected static string $adminToken = '';
    protected static string $gatewayLogDir = '';
    /** @var array<string,string> */
    private static array $platforms = [];

    public static function setUpBeforeClass(): void
    {
        self::$url = (string) getenv('STONESCRIPT_E2E_GATEWAY_URL');
        self::$adminToken = (string) getenv('STONESCRIPT_E2E_ADMIN_TOKEN');
        self::$gatewayLogDir = (string) getenv('STONESCRIPT_E2E_GATEWAY_LOG_DIR');
        if (self::$url === '' || self::$adminToken === '') {
            $msg = 'E2E NOT RUN: no real gateway stack (set STONESCRIPT_E2E_GATEWAY_URL and STONESCRIPT_E2E_ADMIN_TOKEN; '
                . 'run tests/E2E/run-e2e.sh). The identifier-only error contract is NOT verified against a real gateway in this run.';
            fwrite(STDERR, "\n!!!!!! " . $msg . " !!!!!!\n");
            if (getenv('STONESCRIPT_E2E_REQUIRE') === '1') {
                self::fail($msg . ' (STONESCRIPT_E2E_REQUIRE=1)');
            }
            self::markTestSkipped($msg);
        }
    }

    protected static function frameworkRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @param list<string> $forbidden */
    protected function assertClean(string $text, string $where, array $forbidden = self::FORBIDDEN): void
    {
        foreach ($forbidden as $s) {
            $at = strpos($text, $s);
            if ($at !== false) {
                // short excerpt only: never dump a large haystack into a failure message
                $this->fail("'$s' leaked via $where: ..." . substr($text, max(0, $at - 40), 120) . '...');
            }
            $this->addToAssertionCount(1);
        }
    }

    /**
     * Run a framework CLI script against a project root.
     *
     * @param list<string> $args
     * @param array<string,string> $env
     * @return array{0:string,1:string,2:int}
     */
    protected function runCli(string $script, string $projectRoot, string $platform, array $args = [], array $env = []): array
    {
        return $this->runPhp(
            array_merge([__DIR__ . '/run_cli.php', $script], $args),
            array_merge([
                'E2E_PROJECT_ROOT' => $projectRoot,
                'DB_GATEWAY_URL' => self::$url,
                'DB_GATEWAY_PLATFORM' => $platform,
                'DB_GATEWAY_SCHEMA_NAME' => 'main',
                'DB_GATEWAY_ADMIN_TOKEN' => self::$adminToken,
            ], $env)
        );
    }

    /**
     * @param list<string> $argv
     * @param array<string,string> $env
     * @return array{0:string,1:string,2:int}
     */
    protected function runPhp(array $argv, array $env = []): array
    {
        $p = proc_open(
            array_merge([PHP_BINARY], $argv),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            array_merge(['PATH' => getenv('PATH') ?: '/usr/bin'], $env)
        );
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [(string) $out, (string) $err, proc_close($p)];
    }

    /** A fresh copy of the fixture project (tables, functions, no seeders/migrations), plus optional extra files. */
    protected function makeProject(array $extraFiles = []): string
    {
        $dir = sys_get_temp_dir() . '/stonescript-e2e-' . bin2hex(random_bytes(5));
        $this->copyDir(__DIR__ . '/fixtures/app', $dir);
        foreach ($extraFiles as $rel => $content) {
            $path = $dir . '/src/postgresql/main/postgresql/' . $rel;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            file_put_contents($path, $content);
        }
        return $dir;
    }

    private function copyDir(string $from, string $to): void
    {
        mkdir($to, 0775, true);
        foreach (scandir($from) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            is_dir("$from/$f") ? $this->copyDir("$from/$f", "$to/$f") : copy("$from/$f", "$to/$f");
        }
    }

    protected function newPlatformName(string $prefix = 'e2e'): string
    {
        return $prefix . bin2hex(random_bytes(4));
    }

    /**
     * Registers (once per test class) a platform from the fixture project through the real CLI and returns its name.
     */
    protected function sharedPlatform(): string
    {
        $fromEnv = (string) getenv('STONESCRIPT_E2E_PLATFORM');
        if ($fromEnv !== '') {
            return $fromEnv;
        }
        if (!isset(self::$platforms['shared'])) {
            $platform = $this->newPlatformName();
            $project = $this->makeProject();
            [$out, $err, $code] = $this->runCli('gateway-register-main.php', $project, $platform, ['--retry=1', '--delay=0']);
            $this->assertSame(0, $code, "shared platform registration failed:\n$out\n$err");
            self::$platforms['shared'] = $platform;
        }
        return self::$platforms['shared'];
    }

    /** Everything written by the gateway process so far. */
    protected function gatewayLogs(): string
    {
        $all = '';
        foreach (glob(self::$gatewayLogDir . '/*') ?: [] as $f) {
            if (is_file($f)) {
                $all .= (string) file_get_contents($f);
            }
        }
        return $all;
    }
}
