<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Support\PhpRuntimeFloor;

final class PhpRuntimeFloorTest extends TestCase
{
    public static function versions(): array
    {
        return [
            '8.1.99 unsupported' => [80199, '8.1.99', false],
            '8.2.34 vulnerable' => [80234, '8.2.34', false],
            '8.2.35 floor' => [80235, '8.2.35', true],
            '8.2.40' => [80240, '8.2.40', true],
            '8.3.6 distro-style old number' => [80306, '8.3.6', false],
            '8.3.35' => [80335, '8.3.35', false],
            '8.3.36 floor' => [80336, '8.3.36', true],
            '8.4.26' => [80426, '8.4.26', false],
            '8.4.27 floor' => [80427, '8.4.27', true],
            '8.5.4' => [80504, '8.5.4', false],
            '8.5.11' => [80511, '8.5.11', false],
            '8.5.12 floor' => [80512, '8.5.12', true],
            '8.6.0 newer line' => [80600, '8.6.0', true],
            '9.0.0' => [90000, '9.0.0', true],
        ];
    }

    #[DataProvider('versions')]
    public function test_floor_per_minor_line(int $id, string $str, bool $ok): void
    {
        $v = PhpRuntimeFloor::violation($id, $str);
        $ok ? $this->assertNull($v) : $this->assertStringContainsString($str, (string) $v);
    }

    public function test_message_names_the_fix(): void
    {
        $m = (string) PhpRuntimeFloor::violation(80306, '8.3.6');
        $this->assertStringContainsString('8.3.36', $m);
        $this->assertStringContainsString('upstream', $m);
    }

    public function test_override_is_cli_only_and_needs_exactly_1(): void
    {
        $this->assertTrue(PhpRuntimeFloor::overrideActive('cli', '1'));
        $this->assertTrue(PhpRuntimeFloor::overrideActive('phpdbg', '1'));
        foreach (['fpm-fcgi', 'cgi-fcgi', 'apache2handler', 'cli-server', 'embed', 'litespeed'] as $sapi) {
            $this->assertFalse(PhpRuntimeFloor::overrideActive($sapi, '1'), $sapi);
        }
        $this->assertFalse(PhpRuntimeFloor::overrideActive('cli', '0'));
        $this->assertFalse(PhpRuntimeFloor::overrideActive('cli', 'true'));
        $this->assertFalse(PhpRuntimeFloor::overrideActive('cli', false));
    }

    public function test_composer_json_constraint_matches_the_floors(): void
    {
        $c = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true);
        $this->assertSame('~8.2.35 || ~8.3.36 || ~8.4.27 || >=8.5.12', $c['require']['php']);
        $this->assertSame('src/php_floor.php', $c['autoload']['files'][0], 'the guard must load first');
        foreach (PhpRuntimeFloor::FLOORS as $line => $id) {
            $this->assertStringContainsString(PhpRuntimeFloor::floorString($id), $c['require']['php'] . ' >=8.5.12');
        }
    }

    /** @return array{0:int,1:string,2:string} exit code, stdout, stderr of a child that really runs enforce() */
    private function runEnforce(string $callArgs, array $env = []): array
    {
        $file = dirname(__DIR__, 2) . '/src/Support/PhpRuntimeFloor.php';
        $code = 'require ' . var_export($file, true) . '; \\StoneScriptPHP\\Support\\PhpRuntimeFloor::enforce(' . $callArgs . '); echo "BOOTED";';
        $p = proc_open([PHP_BINARY, '-d', 'log_errors=0', '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + ['PATH' => (string) getenv('PATH')]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        return [proc_close($p), $out, $err];
    }

    public function test_enforce_web_path_is_a_generic_503_body_with_no_php_security_or_version_text(): void
    {
        [$rc, $out] = $this->runEnforce("80306, '8.3.6', 'fpm-fcgi'");
        $this->assertSame(1, $rc);
        $this->assertSame(['status' => 'error', 'message' => 'Service temporarily unavailable', 'error_code' => 'service_unavailable'], json_decode($out, true));
        foreach (['PHP', 'php', 'security', 'CVE', '8.3', 'version', 'patch', 'runtime'] as $needle) {
            $this->assertStringNotContainsString($needle, $out);
        }
    }

    public function test_enforce_web_path_ignores_the_override(): void
    {
        [$rc, $out] = $this->runEnforce("80306, '8.3.6', 'fpm-fcgi', '1'", ['STONESCRIPT_ALLOW_UNPATCHED_PHP' => '1']);
        $this->assertSame(1, $rc);
        $this->assertStringNotContainsString('BOOTED', $out);
    }

    public function test_enforce_cli_path_refuses_with_the_reason_on_stderr(): void
    {
        [$rc, $out, $err] = $this->runEnforce("80306, '8.3.6', 'cli'");
        $this->assertSame(1, $rc);
        $this->assertSame('', $out);
        $this->assertStringContainsString('REFUSING TO START', $err);
        $this->assertStringContainsString('8.3.6', $err);
    }

    public function test_enforce_cli_path_honours_the_override_with_a_warning(): void
    {
        [$rc, $out, $err] = $this->runEnforce("80306, '8.3.6', 'cli', '1'");
        $this->assertSame(0, $rc);
        $this->assertSame('BOOTED', $out);
        $this->assertStringContainsString('WARNING', $err);
    }

    public function test_enforce_returns_silently_on_a_patched_version(): void
    {
        [$rc, $out, $err] = $this->runEnforce('80336, ' . var_export('8.3.36', true) . ", 'fpm-fcgi'");
        $this->assertSame(0, $rc);
        $this->assertSame('BOOTED', $out);
        $this->assertSame('', $err);
    }
}
