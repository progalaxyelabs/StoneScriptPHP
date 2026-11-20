<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit\Tenancy;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Env;
use StoneScriptPHP\Support\BootCheck;
use StoneScriptPHP\Tests\Unit\CliProjectHarness;

/**
 * A missing or blank PLATFORM_CODE is a hard boot error with an external auth service (it is the same check
 * Application::run() and `php stone env:check` run), so it is caught at deploy and not by users.
 *
 * @covers \StoneScriptPHP\Support\BootCheck
 */
final class BootCheckPlatformCodeTest extends TestCase
{
    /** @var list<string> */
    private array $touched = [];

    protected function setUp(): void
    {
        foreach (['DB_GATEWAY_URL' => 'http://gateway.invalid:9000', 'DB_GATEWAY_PLATFORM' => 'acme', 'DB_GATEWAY_SCHEMA_NAME' => 'main', 'AUTH_SERVICE_URL' => 'http://auth.invalid:3139', 'AUTH_ISSUER' => 'http://auth.invalid:3139'] as $k => $v) {
            $this->set($k, $v);
        }
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Env::class, '_instance'))->setValue(null, null);
        foreach ($this->touched as $name) {
            putenv($name);
            unset($_ENV[$name]);
        }
    }

    private function set(string $k, ?string $v): void
    {
        if ($v === null) {
            putenv($k);
            unset($_ENV[$k]);
        } else {
            putenv("$k=$v");
            $_ENV[$k] = $v;
        }
        $this->touched[] = $k;
    }

    /** @param array<string,mixed> $authConfig */
    private function check(string $mode, ?string $code, array $authConfig = []): ?string
    {
        $this->set('AUTH_MODE', $mode);
        $this->set('PLATFORM_CODE', $code);
        (new \ReflectionProperty(Env::class, '_instance'))->setValue(null, null);
        return BootCheck::platformCode(Env::get_instance(), $authConfig);
    }

    public function test_the_platform_code_may_come_from_env_or_from_the_auth_config_and_the_config_wins(): void
    {
        $this->assertNull($this->check('external', 'acme'), 'Env only');
        $this->assertNull($this->check('external', null, ['platform' => ['code' => 'acme']]), 'auth config only');
        $this->assertNull($this->check('external', '', ['platform' => ['code' => 'acme']]), 'blank Env, config set');
        $this->assertNotNull($this->check('external', 'acme', ['platform' => ['code' => '  ']]), 'config wins over Env (as buildAuthRouteOptions does): a blank config value is a blank code');
    }

    public function test_both_sources_missing_is_the_error_and_it_names_both(): void
    {
        foreach ([[null, []], ['', ['platform' => []]], ['  ', ['platform' => ['code' => '']]]] as [$env, $config]) {
            $failure = $this->check('external', $env, $config);

            $this->assertNotNull($failure);
            $this->assertStringContainsString('PLATFORM_CODE', $failure);
            $this->assertStringContainsString('auth.platform.code', $failure);
        }
    }

    public function test_the_auth_config_mode_decides_when_it_is_set(): void
    {
        $this->assertNotNull($this->check('builtin', null, ['mode' => 'external']), 'auth.mode=external needs a code even if AUTH_MODE is builtin');
        $this->assertNull($this->check('external', null, ['mode' => 'builtin']));
    }

    public function test_missing_or_blank_platform_code_with_external_or_hybrid_auth_is_a_boot_failure(): void
    {
        foreach (['external', 'hybrid'] as $mode) {
            foreach ([null, '', '   '] as $code) {
                $failure = $this->check($mode, $code);

                $this->assertNotNull($failure, "$mode / " . json_encode($code));
                $this->assertStringContainsString('PLATFORM_CODE', $failure);
                $this->assertStringContainsString('DB_GATEWAY_PLATFORM', $failure, 'the message says what the value must equal');
            }
        }
    }

    public function test_a_configured_platform_code_and_builtin_auth_boot_fine(): void
    {
        $this->assertNull($this->check('external', 'acme'));
        $this->assertNull($this->check('hybrid', 'acme'));
        $this->assertNull($this->check('builtin', null), 'standalone platforms have no external audience to check');
    }

    public function test_env_check_reports_the_failure_and_exits_non_zero(): void
    {
        $project = new CliProjectHarness();
        try {
            $project->write('.env', "DB_GATEWAY_URL=http://gateway.invalid:9000\nDB_GATEWAY_PLATFORM=acme\nDB_GATEWAY_SCHEMA_NAME=main\nAUTH_MODE=external\nAUTH_SERVICE_URL=http://auth.invalid:3139\nAUTH_ISSUER=http://auth.invalid:3139\n");

            [$status, $out] = $project->stone(['env:check']);
            $this->assertSame(1, $status, $out);
            $this->assertStringContainsString('PLATFORM_CODE', $out);

            $project->write('.env', "DB_GATEWAY_URL=http://gateway.invalid:9000\nDB_GATEWAY_PLATFORM=acme\nDB_GATEWAY_SCHEMA_NAME=main\nAUTH_MODE=external\nAUTH_SERVICE_URL=http://auth.invalid:3139\nAUTH_ISSUER=http://auth.invalid:3139\nPLATFORM_CODE=acme\n");
            [$status, $out] = $project->stone(['env:check']);
            $this->assertSame(0, $status, $out);

            // The code may also come from the app's auth config (auth.platform.code), as Application resolves it.
            $project->write('.env', "DB_GATEWAY_URL=http://gateway.invalid:9000\nDB_GATEWAY_PLATFORM=acme\nDB_GATEWAY_SCHEMA_NAME=main\nAUTH_MODE=external\nAUTH_SERVICE_URL=http://auth.invalid:3139\nAUTH_ISSUER=http://auth.invalid:3139\n");
            $project->write('config/auth.php', "<?php\nreturn ['platform' => ['code' => 'acme']];\n");
            [$status, $out] = $project->stone(['env:check']);
            $this->assertSame(0, $status, 'platform code from config/auth.php: ' . $out);
        } finally {
            $project->cleanup();
        }
    }
}
