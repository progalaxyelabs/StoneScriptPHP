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
            '8.2.35 no longer supported' => [80235, '8.2.35', false],
            '8.3.36 no longer supported' => [80336, '8.3.36', false],
            '8.4.27 no longer supported' => [80427, '8.4.27', false],
            '8.5.4' => [80504, '8.5.4', false],
            '8.5.11' => [80511, '8.5.11', false],
            '8.5.12 floor' => [80512, '8.5.12', true],
            '8.5.13' => [80513, '8.5.13', true],
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
        $m = (string) PhpRuntimeFloor::violation(80504, '8.5.4');
        $this->assertStringContainsString('8.5.12', $m);
        $this->assertStringContainsString('upstream', $m);
        $old = (string) PhpRuntimeFloor::violation(80336, '8.3.36');
        $this->assertStringContainsString('requires PHP 8.5.12', $old);
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
        $this->assertSame('>=8.5', $c['require']['php'], 'composer admits distro builds; the runtime guard is the authority');
        $this->assertSame('src/php_floor.php', $c['autoload']['files'][0], 'the guard must load first');
        $this->assertSame('8.5.12', PhpRuntimeFloor::floorString(PhpRuntimeFloor::FLOORS[805]), 'upstream floor unchanged');
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
        [$rc, $out, $err] = $this->runEnforce('80512, ' . var_export('8.5.12', true) . ", 'fpm-fcgi'");
        $this->assertSame(0, $rc);
        $this->assertSame('BOOTED', $out);
        $this->assertSame('', $err);
    }

    private const CVE_ACK = 'CVE-2026-109618,CVE-2026-109619';

    private function probe(?string $distro, ?string $have, bool|null $atLeast = true): array
    {
        return ['distro' => $distro, 'version' => fn (string $p) => $have, 'atLeast' => fn (string $a, string $b) => $atLeast];
    }

    public function test_refuses_8_5_4_upstream_and_with_no_info(): void
    {
        $this->assertNotNull(PhpRuntimeFloor::violation(80504, '8.5.4'));
        $this->assertNull(PhpRuntimeFloor::distroAcceptance(80504, '8.5.4', '', $this->probe(null, null)));
    }

    public function test_accepts_8_5_4_with_the_ubuntu_row(): void
    {
        $r = PhpRuntimeFloor::distroAcceptance(80504, '8.5.4', '', $this->probe('ubuntu:26.04', '8.5.4-0ubuntu1.5'));
        $this->assertSame('distro', $r['kind']);
        $this->assertStringContainsString('distro-patched build php8.5-common 8.5.4-0ubuntu1.5', $r['message']);
        $this->assertStringContainsString('CVE-2026-109618', $r['message']);
    }

    public function test_refuses_below_minimum_unknown_distro_unknown_package_and_foreign_upstream(): void
    {
        $this->assertNull(PhpRuntimeFloor::distroAcceptance(80504, '8.5.4', '', $this->probe('ubuntu:26.04', '8.5.4-0ubuntu1.4', false)));
        $this->assertNull(PhpRuntimeFloor::distroAcceptance(80504, '8.5.4', '', $this->probe('ubuntu:24.04', '8.5.4-0ubuntu1.5')), 'no row');
        $this->assertNull(PhpRuntimeFloor::distroAcceptance(80504, '8.5.4', '', $this->probe('freebsd:14', '8.5.4-0ubuntu1.5')), 'unknown distro');
        $this->assertNull(PhpRuntimeFloor::distroAcceptance(80504, '8.5.4', '', $this->probe('ubuntu:26.04', null)), 'package not installed');
        $this->assertNull(PhpRuntimeFloor::distroAcceptance(80504, '8.5.4', '', $this->probe('ubuntu:26.04', '8.5.4-0ubuntu1.5', null)), 'undecidable compare');
        $this->assertNull(PhpRuntimeFloor::distroAcceptance(80504, '8.5.4', '', $this->probe('ubuntu:26.04', '8.5.9-0ubuntu1.5')), 'running binary is not that package (self-compiled)');
    }

    public function test_unsupported_lines_are_never_rescued(): void
    {
        $this->assertNull(PhpRuntimeFloor::distroAcceptance(80306, '8.3.6', self::CVE_ACK . ';php8.3-common=8.3.6-1', $this->probe('ubuntu:26.04', '8.3.6-1')));
    }

    public function test_ack_matches_only_the_exact_package_version_and_all_cves(): void
    {
        $p = $this->probe('debian:13', '8.5.4-1+deb13u2');
        $ok = self::CVE_ACK . ';php8.5-common=8.5.4-1+deb13u2';
        $r = PhpRuntimeFloor::distroAcceptance(80504, '8.5.4', $ok, $p);
        $this->assertSame('ack', $r['kind']);
        $this->assertStringContainsString('OPERATOR ACKNOWLEDGEMENT', $r['message']);
        $this->assertNull(PhpRuntimeFloor::distroAcceptance(80504, '8.5.4', self::CVE_ACK . ';php8.5-common=8.5.4-1+deb13u1', $p), 'package version mismatch');
        $this->assertNull(PhpRuntimeFloor::distroAcceptance(80504, '8.5.4', 'CVE-2026-109618;php8.5-common=8.5.4-1+deb13u2', $p), 'missing CVE');
        $this->assertNull(PhpRuntimeFloor::distroAcceptance(80504, '8.5.4', self::CVE_ACK, $p), 'no version binding');
        $this->assertNull(PhpRuntimeFloor::distroAcceptance(80504, '8.5.4', 'garbage; rm -rf /=1', $p));
        $this->assertNull(PhpRuntimeFloor::distroAcceptance(80504, '8.5.4', $ok, $this->probe('debian:13', null)), 'package gone');
    }

    public function test_package_name_and_version_are_validated_before_exec(): void
    {
        $this->assertNull(PhpRuntimeFloor::installedPackageVersion('php; id'));
        $this->assertNull(PhpRuntimeFloor::dpkgAtLeast('1; id', '2'));
    }

    public function test_dpkg_comparator_and_reader_on_this_host_if_dpkg_exists(): void
    {
        if (!is_executable('/usr/bin/dpkg')) {
            $this->markTestSkipped('no dpkg');
        }
        PhpRuntimeFloor::resetCache();
        $this->assertTrue(PhpRuntimeFloor::dpkgAtLeast('8.5.4-0ubuntu1.5', '8.5.4-0ubuntu1.5'));
        $this->assertTrue(PhpRuntimeFloor::dpkgAtLeast('8.5.4-0ubuntu1.10', '8.5.4-0ubuntu1.5'));
        $this->assertFalse(PhpRuntimeFloor::dpkgAtLeast('8.5.4-0ubuntu1.4', '8.5.4-0ubuntu1.5'));
        $this->assertNull(PhpRuntimeFloor::installedPackageVersion('no-such-package-xyz'));
    }

    public function test_enforce_boots_logs_one_line_and_cli_and_web_refuse_otherwise(): void
    {
        $probe = "['distro'=>'ubuntu:26.04','version'=>fn(\$p)=>'8.5.4-0ubuntu1.5','atLeast'=>fn(\$a,\$b)=>true]";
        [$rc, $out, $err] = $this->runEnforce("80504, '8.5.4', 'fpm-fcgi', null, '', $probe", ['X' => '1']);
        $this->assertSame(0, $rc);
        $this->assertSame('BOOTED', $out);
        // error_log is disabled in the child (log_errors=0); acceptance must still not refuse.
        [$rc, $out] = $this->runEnforce("80504, '8.5.4', 'fpm-fcgi', null, '', ['distro'=>'unknown:1','version'=>fn(\$p)=>null,'atLeast'=>fn(\$a,\$b)=>null]");
        $this->assertSame(1, $rc);
        $this->assertStringContainsString('service_unavailable', $out);
        [$rc, , $err] = $this->runEnforce("80504, '8.5.4', 'cli', null, '', ['distro'=>'unknown:1','version'=>fn(\$p)=>null,'atLeast'=>fn(\$a,\$b)=>null]");
        $this->assertSame(1, $rc);
        $this->assertStringContainsString('not provably patched', $err);
        $this->assertStringContainsString('STONESCRIPT_DISTRO_PATCH_ACK', $err);
    }
}
