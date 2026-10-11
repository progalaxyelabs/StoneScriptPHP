<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * `php stone env` runs against a project's REAL .env. It must never lose a setting: keys outside the Env
 * schema (read through Env::secret()), quoted values with trailing comments, `export` lines and comments
 * all survive; --force is defined and safe; writes are atomic and keep the file mode.
 */
final class GenerateEnvPreservationTest extends TestCase
{
    private const ORIGINAL = <<<'ENV'
# my production settings
export GOOGLE_CLIENT_ID=abc.apps.googleusercontent.com
JWT_PRIVATE_KEY="line1\nline2" # inline comment
PAYMENT_SECRET='s3cr$t\value'
ZOHO_TOKEN="has \"quotes\" and $dollar"
APP_NAME="My Real App"
DB_GATEWAY_URL=http://gw:9000
DB_GATEWAY_PLATFORM=realplat

ENV;

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ssp_genenv2_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->dir) as $f) {
            if ($f !== '.' && $f !== '..') {
                unlink($this->dir . '/' . $f);
            }
        }
        rmdir($this->dir);
    }

    /** @return array{0:string,1:int} */
    private function generate(string ...$args): array
    {
        $code = 'define("ROOT_PATH", ' . var_export($this->dir . DIRECTORY_SEPARATOR, true) . ');'
            . 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
            . '$argv = array_merge(["generate-env"], ' . var_export($args, true) . ');'
            . 'require ' . var_export(dirname(__DIR__, 2) . '/cli/generate-env.php', true) . ';';
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=1', '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => (string) getenv('PATH')]
        );
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [$out, proc_close($proc)];
    }

    private function parsed(string $file): array
    {
        return \Dotenv\Dotenv::parse((string) file_get_contents($file));
    }

    public function test_default_run_keeps_the_existing_file_byte_for_byte_and_only_appends(): void
    {
        file_put_contents($this->dir . '/.env', self::ORIGINAL);
        chmod($this->dir . '/.env', 0600);
        [$out, $code] = $this->generate();

        $this->assertSame(0, $code, $out);
        $after = (string) file_get_contents($this->dir . '/.env');
        $this->assertStringStartsWith(rtrim(self::ORIGINAL, "\n"), $after, 'original bytes (comments, export, quoting) untouched');
        $before = \Dotenv\Dotenv::parse(self::ORIGINAL);
        $now = \Dotenv\Dotenv::parse($after);
        foreach ($before as $k => $v) {
            $this->assertSame($v, $now[$k], "$k changed");
        }
        $this->assertSame(0600, fileperms($this->dir . '/.env') & 0777, 'mode preserved');
    }

    public function test_force_resets_schema_keys_keeps_every_other_key_and_writes_a_private_backup(): void
    {
        file_put_contents($this->dir . '/.env', self::ORIGINAL);
        chmod($this->dir . '/.env', 0640);
        [$out, $code] = $this->generate('--force');

        $this->assertSame(0, $code, $out);
        $now = $this->parsed($this->dir . '/.env');
        $before = \Dotenv\Dotenv::parse(self::ORIGINAL);

        // non-schema keys: exact values survive
        foreach (['GOOGLE_CLIENT_ID', 'JWT_PRIVATE_KEY', 'PAYMENT_SECRET', 'ZOHO_TOKEN'] as $k) {
            $this->assertSame($before[$k], $now[$k], "$k lost or altered by --force");
        }
        // schema key reset to its default
        $this->assertSame('My API', $now['APP_NAME']);
        $this->assertSame(0640, fileperms($this->dir . '/.env') & 0777);

        $backups = array_values(array_filter(scandir($this->dir), static fn($f) => str_starts_with($f, '.env.bak.')));
        $this->assertCount(1, $backups);
        $this->assertSame(self::ORIGINAL, file_get_contents($this->dir . '/' . $backups[0]));
        $this->assertSame(0600, fileperms($this->dir . '/' . $backups[0]) & 0777);
        $this->assertSame([], array_filter(scandir($this->dir), static fn($f) => str_contains($f, '.tmp.')), 'no temp file left behind');
    }

    public function test_invalid_existing_env_is_refused_and_left_untouched(): void
    {
        $bad = "GOOD=1\nSECRET_TOKEN=hunter2 x\n";
        file_put_contents($this->dir . '/.env', $bad);
        foreach ([[], ['--force']] as $args) {
            [$out, $code] = $this->generate(...$args);
            $this->assertSame(1, $code, $out);
            $this->assertStringNotContainsString('hunter2', $out);
            $this->assertSame($bad, file_get_contents($this->dir . '/.env'));
        }
        $this->assertSame(['.env'], array_values(array_diff(scandir($this->dir), ['.', '..'])), 'no backup/temp files for a refused run');
    }

    public function test_a_symlinked_env_is_updated_through_the_link_and_stays_a_link(): void
    {
        file_put_contents($this->dir . '/real.env', self::ORIGINAL);
        chmod($this->dir . '/real.env', 0600);
        symlink('real.env', $this->dir . '/.env');

        [$out, $code] = $this->generate();
        $this->assertSame(0, $code, $out);
        $this->assertTrue(is_link($this->dir . '/.env'), 'the symlink must not be replaced by a regular file');
        $this->assertStringStartsWith(rtrim(self::ORIGINAL, "\n"), (string) file_get_contents($this->dir . '/real.env'));
        $this->assertStringContainsString('Added by php stone env', (string) file_get_contents($this->dir . '/real.env'));
        $this->assertSame(0600, fileperms($this->dir . '/real.env') & 0777);

        [$out, $code] = $this->generate('--force');
        $this->assertSame(0, $code, $out);
        $this->assertTrue(is_link($this->dir . '/.env'));
        $now = \Dotenv\Dotenv::parse((string) file_get_contents($this->dir . '/real.env'));
        $this->assertSame('abc.apps.googleusercontent.com', $now['GOOGLE_CLIENT_ID']);
        $this->assertSame('My API', $now['APP_NAME']);
    }

    public function test_a_dangling_symlink_is_refused(): void
    {
        symlink('missing.env', $this->dir . '/.env');
        [$out, $code] = $this->generate();
        // A dangling .env is "not a file": file_exists() is false, so the generator would create a new one through
        // the link; writeFileAtomic must refuse instead of replacing the link.
        $this->assertTrue(is_link($this->dir . '/.env'));
        $this->assertFileDoesNotExist($this->dir . '/missing.env');
    }

    public function test_new_env_is_created_private_and_loadable(): void
    {
        [$out, $code] = $this->generate();
        $this->assertSame(0, $code, $out);
        $this->assertSame(0640, fileperms($this->dir . '/.env') & 0777);
        $this->assertArrayHasKey('AUTH_MODE', $this->parsed($this->dir . '/.env'));
    }

    public function test_escape_round_trips_awkward_values_through_phpdotenv(): void
    {
        if (!defined('STONESCRIPTPHP_ENV_GENERATOR_LIB_ONLY')) {
            define('STONESCRIPTPHP_ENV_GENERATOR_LIB_ONLY', true);
        }
        require_once dirname(__DIR__, 2) . '/cli/generate-env.php';

        $values = [
            'plain', 'a b', 'p@ss\\word', 'cost$5', '${HOME}x', 'q"uote', "it's", '#hash', "nl\nx", 'back\\"mix',
            "tab\tx", '$', '\\', 'trail\\', 'a=b', 'multi \\n literal', 'x#y', '"', "'", '',
        ];
        foreach ($values as $v) {
            $parsed = \Dotenv\Dotenv::parse('K=' . escapeEnvValue($v) . "\nAFTER=1\n");
            $this->assertSame($v, $parsed['K'], 'round trip failed for ' . json_encode($v));
            $this->assertSame('1', $parsed['AFTER']);
        }
    }
}
