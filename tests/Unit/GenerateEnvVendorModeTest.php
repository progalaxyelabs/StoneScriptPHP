<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * generate-env.php must find the PROJECT root (and its vendor/autoload.php) when the framework
 * is installed as a dependency, and must write a .env that phpdotenv can load.
 */
final class GenerateEnvVendorModeTest extends TestCase
{
    private string $project = '';

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ssp_genenv_' . bin2hex(random_bytes(6));
        mkdir($this->project . '/vendor/progalaxyelabs/stonescriptphp/cli', 0700, true);
        file_put_contents(
            $this->project . '/vendor/autoload.php',
            "<?php\nrequire " . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ";\n"
        );
        copy(
            dirname(__DIR__, 2) . '/cli/generate-env.php',
            $this->project . '/vendor/progalaxyelabs/stonescriptphp/cli/generate-env.php'
        );
    }

    protected function tearDown(): void
    {
        $paths = [
            '.env',
            'vendor/autoload.php',
            'vendor/progalaxyelabs/stonescriptphp/cli/generate-env.php',
        ];
        foreach ($paths as $p) {
            if (is_file($this->project . '/' . $p)) {
                unlink($this->project . '/' . $p);
            }
        }
        rmdir($this->project . '/vendor/progalaxyelabs/stonescriptphp/cli');
        rmdir($this->project . '/vendor/progalaxyelabs/stonescriptphp');
        rmdir($this->project . '/vendor/progalaxyelabs');
        rmdir($this->project . '/vendor');
        rmdir($this->project);
    }

    public function test_vendor_mode_finds_project_autoload_and_writes_dotenv_valid_file(): void
    {
        $script = $this->project . '/vendor/progalaxyelabs/stonescriptphp/cli/generate-env.php';
        // Direct invocation (no ROOT_PATH): this is the case that used to look inside the package dir.
        $proc = proc_open(
            [PHP_BINARY, $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->project,
            ['PATH' => (string) getenv('PATH')]
        );
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        $this->assertSame(0, $code, $out);
        $this->assertStringNotContainsString('vendor/autoload.php not found', $out);
        $envFile = $this->project . '/.env';
        $this->assertFileExists($envFile);
        $parsed = \Dotenv\Dotenv::parse((string) file_get_contents($envFile)); // throws on ';' comments etc.
        $this->assertArrayHasKey('AUTH_MODE', $parsed);
    }

    public function test_root_resolution(): void
    {
        if (!defined('STONESCRIPTPHP_ENV_GENERATOR_LIB_ONLY')) {
            define('STONESCRIPTPHP_ENV_GENERATOR_LIB_ONLY', true);
        }
        require_once dirname(__DIR__, 2) . '/cli/generate-env.php';

        $this->assertSame('/p', resolveEnvGeneratorRoot('/x/y', '/p/'));
        $this->assertSame('/proj', resolveEnvGeneratorRoot('/proj/vendor/progalaxyelabs/stonescriptphp/cli'));
        $this->assertSame('/repo', resolveEnvGeneratorRoot('/repo/cli'));
    }
}
