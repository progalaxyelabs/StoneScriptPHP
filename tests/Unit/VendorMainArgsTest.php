<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** gateway:migrate-vendor-main argument handling must fail closed: a bad --only never means "apply everything". */
final class VendorMainArgsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/cli/helpers/vendor-schema-sync.php';
    }

    /** @return array<string, array{0: list<string>}> */
    public static function rejected(): array
    {
        return [
            'empty value'          => [['x', '--only=']],
            'only commas'          => [['x', '--only=,,']],
            'bare --only'          => [['x', '--only']],
            'space form'           => [['x', '--only', 'RefreshTokens']],
            'unknown flag'         => [['x', '--onyl=RefreshTokens']],
            'positional'           => [['x', 'RefreshTokens']],
            'empty beside valid'   => [['x', '--only=RefreshTokens', '--only=']],
        ];
    }

    /** @dataProvider rejected */
    #[\PHPUnit\Framework\Attributes\DataProvider('rejected')]
    public function test_malformed_selection_is_rejected(array $argv): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ssp_parse_vendor_main_args($argv);
    }

    public function test_valid_selection_and_known_gateway_flags_are_accepted(): void
    {
        $r = ssp_parse_vendor_main_args(['x', '--only=RefreshTokens, Analytics', '--quiet', '--retry=2', '--allow-drop-table', '--main-schema-name=main']);
        $this->assertSame(['RefreshTokens', 'Analytics'], $r['only']);
        $this->assertFalse($r['list']);
        $this->assertSame([], ssp_parse_vendor_main_args(['x'])['only'], 'no --only still means the unchanged default');
        $this->assertTrue(ssp_parse_vendor_main_args(['x', '--list'])['list']);
    }

    /** @return array<string, array{0: list<string>}> */
    public static function cliRejected(): array
    {
        return [
            'empty' => [['--only=']],
            'space' => [['--only', 'RefreshTokens']],
            'bogus' => [['--bogus']],
        ];
    }

    /** @dataProvider cliRejected */
    #[\PHPUnit\Framework\Attributes\DataProvider('cliRejected')]
    public function test_the_command_exits_1_before_doing_anything(array $args): void
    {
        $dir = sys_get_temp_dir() . '/ssp-vmain-' . bin2hex(random_bytes(5));
        mkdir($dir, 0700, true);
        try {
            $code = 'define("ROOT_PATH", ' . var_export($dir . '/', true) . ');'
                . 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
                . '$argv = $_SERVER["argv"] = array_merge(["vendor-main", "gateway:migrate-vendor-main"], ' . var_export($args, true) . ');'
                . 'require ' . var_export(dirname(__DIR__, 2) . '/cli/gateway-migrate-vendor-main.php', true) . ';';
            $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PATH' => (string) getenv('PATH')]);
            $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($proc);
        } finally {
            rmdir($dir);
        }
        $this->assertSame(1, $exit, $out);
        $this->assertStringContainsString('ERROR:', $out);
        $this->assertStringNotContainsString('Building schema archive', $out);
    }
}
