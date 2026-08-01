<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Env;

/**
 * Unit tests for Env::$DB_MODE (gateway | direct | pgandroid) — boot-time
 * mode selection + validation added alongside the pluggable DbTransport
 * refactor (see src/Db/DbTransport.php).
 *
 * Uses the same singleton-reset-via-reflection pattern as
 * EnvSecretResolutionTest so these tests don't leak state into (or inherit
 * state from) other test classes sharing the PHPUnit process.
 */
final class EnvDbModeTest extends TestCase
{
    /** @var list<string> env keys to clean up after each test */
    private array $touchedEnvKeys = [];

    protected function tearDown(): void
    {
        foreach ($this->touchedEnvKeys as $key) {
            putenv($key);
            unset($_ENV[$key]);
        }
        $this->touchedEnvKeys = [];
        $this->resetSingleton();
        parent::tearDown();
    }

    private function setEnv(string $key, string $value): void
    {
        putenv("$key=$value");
        $_ENV[$key] = $value;
        $this->touchedEnvKeys[] = $key;
    }

    private function unsetEnv(string $key): void
    {
        putenv($key);
        unset($_ENV[$key]);
    }

    private function resetSingleton(): void
    {
        $prop = new \ReflectionProperty(Env::class, '_instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    public function test_db_mode_defaults_to_gateway_when_unset(): void
    {
        $this->unsetEnv('DB_MODE');
        $this->setEnv('DB_GATEWAY_URL', 'http://gateway:9000');
        $this->setEnv('DB_GATEWAY_PLATFORM', 'testplatform');
        $this->resetSingleton();

        $env = Env::get_instance();

        $this->assertSame('gateway', $env->DB_MODE);
    }

    public function test_db_mode_direct_boots_without_gateway_secrets(): void
    {
        $this->setEnv('DB_MODE', 'direct');
        $this->unsetEnv('DB_GATEWAY_URL');
        $this->unsetEnv('DB_GATEWAY_PLATFORM');
        $this->resetSingleton();

        // Must NOT throw — direct mode has no eager gateway requirement.
        $env = Env::get_instance();

        $this->assertSame('direct', $env->DB_MODE);
    }

    public function test_db_mode_pgandroid_boots_without_gateway_or_db_secrets(): void
    {
        $this->setEnv('DB_MODE', 'pgandroid');
        $this->unsetEnv('DB_GATEWAY_URL');
        $this->unsetEnv('DB_GATEWAY_PLATFORM');
        $this->resetSingleton();

        // Must NOT throw — pgandroid has no eager requirement at all (the
        // bridge is host-provided, validated lazily on first real call).
        $env = Env::get_instance();

        $this->assertSame('pgandroid', $env->DB_MODE);
    }

    public function test_unknown_db_mode_fails_loud_at_boot(): void
    {
        $this->setEnv('DB_MODE', 'bogus-mode');
        $this->resetSingleton();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches("/Invalid DB_MODE 'bogus-mode'.*gateway, direct, pgandroid/");

        Env::get_instance();
    }

    public function test_direct_mode_connection_vars_resolve_from_env(): void
    {
        $this->setEnv('DB_MODE', 'direct');
        $this->setEnv('DB_HOST', 'db.internal');
        $this->setEnv('DB_PORT', '5433');
        $this->setEnv('DB_NAME', 'myapp_main');
        $this->setEnv('DB_USER', 'myapp_user');
        $this->setEnv('DB_PASSWORD', 'secret');
        $this->resetSingleton();

        $env = Env::get_instance();

        $this->assertSame('db.internal', $env->DB_HOST);
        $this->assertSame(5433, $env->DB_PORT);
        $this->assertSame('myapp_main', $env->DB_NAME);
        $this->assertSame('myapp_user', $env->DB_USER);
        $this->assertSame('secret', $env->DB_PASSWORD);
    }

    public function test_direct_mode_connection_vars_have_sane_defaults(): void
    {
        $this->setEnv('DB_MODE', 'direct');
        $this->unsetEnv('DB_HOST');
        $this->unsetEnv('DB_PORT');
        $this->unsetEnv('DB_NAME');
        $this->unsetEnv('DB_USER');
        $this->unsetEnv('DB_PASSWORD');
        $this->resetSingleton();

        $env = Env::get_instance();

        $this->assertSame('localhost', $env->DB_HOST);
        $this->assertSame(5432, $env->DB_PORT);
        $this->assertSame('', $env->DB_NAME);
        $this->assertSame('postgres', $env->DB_USER);
        $this->assertNull($env->DB_PASSWORD);
    }
}
