<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * `php stone setup` must write a .env the current framework actually boots with, derived from
 * the Env schema (no obsolete DATABASE_* keys, no placeholder issuer), and must read an existing
 * dotenv-syntax file without parse_ini_file() warnings.
 */
final class SetupCommandEnvTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ssp_setup_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (['.env', 'keys/jwt-private.pem', 'keys/jwt-public.pem'] as $f) {
            $path = $this->dir . '/' . $f;
            if (is_file($path)) {
                unlink($path);
            }
        }
        if (is_dir($this->dir . '/keys')) {
            rmdir($this->dir . '/keys');
        }
        rmdir($this->dir);
    }

    /** @param array<string,string> $envVars @return array{0:string,1:int} */
    private function child(string $body, array $envVars = []): array
    {
        $repo = dirname(__DIR__, 2);
        $code = 'define("ROOT_PATH", ' . var_export($this->dir . DIRECTORY_SEPARATOR, true) . ');'
            . 'require ' . var_export($repo . '/vendor/autoload.php', true) . ';' . $body;
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=-1', '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => (string) getenv('PATH')] + $envVars
        );
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [$out, proc_close($proc)];
    }

    private function setupQuiet(array $envVars = []): array
    {
        $script = var_export(dirname(__DIR__, 2) . '/cli/setup.php', true);
        return $this->child('$argv = ["setup", "--quiet"]; require ' . $script . ';', $envVars);
    }

    private function bootEnv(): string
    {
        [$out] = $this->child('$e = StoneScriptPHP\Env::get_instance(); echo "BOOT:" . $e->DB_GATEWAY_URL . "|" . $e->APP_NAME;');
        return $out;
    }

    public function test_quiet_setup_writes_an_env_the_framework_boots_with(): void
    {
        [$out, $code] = $this->setupQuiet([
            'DB_GATEWAY_URL' => 'http://gw.test:9000',
            'DB_GATEWAY_PLATFORM' => 'demo',
            'DB_GATEWAY_SCHEMA_NAME' => 'main',
            'APP_NAME' => 'My Demo API',
        ]);
        $this->assertSame(0, $code, $out);

        $env = (string) file_get_contents($this->dir . '/.env');
        $parsed = \Dotenv\Dotenv::parse($env); // throws if not valid dotenv
        $this->assertSame('http://gw.test:9000', $parsed['DB_GATEWAY_URL']);
        $this->assertSame('My Demo API', $parsed['APP_NAME']);
        $this->assertArrayNotHasKey('DATABASE_HOST', $parsed);
        $this->assertArrayNotHasKey('DATABASE_DBNAME', $parsed);
        $this->assertStringNotContainsString('example.com', $env);
        $this->assertFileExists($this->dir . '/keys/jwt-private.pem');
        // The file can hold secrets copied from the process environment: never world-readable.
        $this->assertSame(0640, fileperms($this->dir . '/.env') & 0777);

        $this->assertSame('BOOT:http://gw.test:9000|My Demo API', $this->bootEnv());
    }

    public function test_every_written_key_is_a_key_the_framework_reads(): void
    {
        $this->setupQuiet(['DB_GATEWAY_URL' => 'http://gw.test:9000', 'DB_GATEWAY_PLATFORM' => 'demo']);
        $known = array_keys(get_class_vars(\StoneScriptPHP\Env::class));
        foreach (array_keys(\Dotenv\Dotenv::parse((string) file_get_contents($this->dir . '/.env'))) as $key) {
            $this->assertContains($key, $known, "setup wrote '$key', which Env does not read");
        }
    }

    public function test_existing_dotenv_syntax_env_is_read_without_ini_warnings(): void
    {
        file_put_contents(
            $this->dir . '/.env',
            "# comment\nexport APP_NAME=\"My Demo\"\nDB_GATEWAY_URL=http://gw.test:9000\nDB_GATEWAY_PLATFORM=demo\nGREETING=\"hi \${APP_NAME}\"\n"
        );
        [$out, $code] = $this->setupQuiet();
        $this->assertSame(0, $code, $out);
        $this->assertStringNotContainsString('Warning', $out);
        $this->assertStringNotContainsString('Deprecated', $out);
        $this->assertStringContainsString('already exists', $out);
    }

    public function test_invalid_existing_env_stops_with_a_clear_message_not_a_secret(): void
    {
        file_put_contents($this->dir . '/.env', "SECRET=abc def ghi\n");
        [$out, $code] = $this->setupQuiet();
        $this->assertSame(1, $code);
        $this->assertStringContainsString('not a valid dotenv file', $out);
        $this->assertStringNotContainsString('abc def', $out);
    }
}
