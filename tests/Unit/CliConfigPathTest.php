<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The CLI must resolve CONFIG_PATH to the same directory the runtime uses (src/config/),
 * so a pre-flight can never disagree with the running app about what is configured.
 */
final class CliConfigPathTest extends TestCase
{
    private string $repo;
    private string $project;

    protected function setUp(): void
    {
        $this->repo = dirname(__DIR__, 2);
        $this->project = sys_get_temp_dir() . '/ssp-cfgpath-' . bin2hex(random_bytes(4));
        mkdir($this->project . '/src/config', 0777, true);
        mkdir($this->project . '/vendor/progalaxyelabs', 0777, true);
        file_put_contents($this->project . '/vendor/autoload.php', "<?php\nrequire " . var_export($this->repo . '/vendor/autoload.php', true) . ";\n");
        symlink($this->repo, $this->project . '/vendor/progalaxyelabs/stonescriptphp');
        copy($this->repo . '/stone', $this->project . '/stone');
    }

    protected function tearDown(): void
    {
        foreach (['/src/config/allowed-origins.php', '/vendor/autoload.php', '/stone'] as $f) {
            @unlink($this->project . $f);
        }
        @unlink($this->project . '/vendor/progalaxyelabs/stonescriptphp');
        @rmdir($this->project . '/vendor/progalaxyelabs');
        @rmdir($this->project . '/vendor');
        @rmdir($this->project . '/src/config');
        @rmdir($this->project . '/src');
        @rmdir($this->project);
    }

    public function test_check_origins_reads_the_runtime_config_location(): void
    {
        file_put_contents($this->project . '/src/config/allowed-origins.php',
            "<?php\nreturn ['https://app.example.com'];\n");

        $cmd = 'cd ' . escapeshellarg($this->project) . ' && env -u ALLOWED_ORIGINS php stone auth:check-origins 2>&1';
        exec($cmd, $out, $code);

        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('https://app.example.com', implode("\n", $out));
    }

    public function test_no_cli_entry_point_defines_config_path_under_app_config(): void
    {
        $files = array_merge([$this->repo . '/stone'], glob($this->repo . '/cli/*.php') ?: []);
        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression(
                "/define\\(\\s*'CONFIG_PATH'[^;]*'App'/",
                $src,
                basename($file) . ' defines CONFIG_PATH under src/App/Config; the runtime uses src/config/'
            );
        }
    }

    public function test_runtime_and_cli_agree_on_config_path(): void
    {
        $bootstrap = (string) file_get_contents($this->repo . '/src/bootstrap.php');
        $stone = (string) file_get_contents($this->repo . '/stone');
        $needle = "SRC_PATH . 'config' . DIRECTORY_SEPARATOR";
        $this->assertStringContainsString($needle, $bootstrap);
        $this->assertStringContainsString($needle, $stone);
    }
}
