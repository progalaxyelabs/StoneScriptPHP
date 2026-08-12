<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Database;
use StoneScriptPHP\Env;

/**
 * Database::isGatewayMode() — added 2026-08-01 alongside the DB_MODE-aware
 * middleware guards (GatewayTenantMiddleware, SubscriptionMiddleware,
 * StoreAccessMiddleware). Those middleware only skip gateway-specific
 * operations correctly if this method itself correctly reports true/false
 * for every DB_MODE + fake-mode combination — this file pins that contract
 * directly, independent of any middleware.
 *
 * Mirrors DatabaseDbModeTest.php's singleton-reset discipline: both Env and
 * Database are process-wide singletons, reset via reflection in tearDown()
 * so tests here don't leak DB_MODE state into unrelated tests elsewhere in
 * the suite.
 */
final class DatabaseIsGatewayModeTest extends TestCase
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

        Database::clearFakeMode();
        $this->resetSingleton(Env::class);
        $this->resetSingleton(Database::class);

        parent::tearDown();
    }

    private function setEnv(string $key, string $value): void
    {
        putenv("$key=$value");
        $_ENV[$key] = $value;
        $this->touchedEnvKeys[] = $key;
    }

    private function resetSingleton(string $class): void
    {
        $prop = new \ReflectionProperty($class, '_instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    public function test_true_for_gateway_mode_with_valid_config(): void
    {
        $this->setEnv('DB_MODE', 'gateway');
        $this->setEnv('DB_GATEWAY_URL', 'http://gateway.invalid:9000');
        $this->setEnv('DB_GATEWAY_PLATFORM', 'testplatform');
        $this->setEnv('DB_GATEWAY_SCHEMA_NAME', 'main');
        $this->resetSingleton(Env::class);
        $this->resetSingleton(Database::class);

        // Constructing a GatewayTransport/GatewayClient does no network I/O
        // (confirmed: GatewayClient's constructor only assigns fields; only
        // callFunction()/isConnected() curl out) — this genuinely exercises
        // the `instanceof GatewayTransport` branch, not a mocked stand-in.
        $this->assertTrue(Database::isGatewayMode());
    }

    public function test_false_for_direct_mode(): void
    {
        $this->setEnv('DB_MODE', 'direct');
        $this->setEnv('DB_NAME', 'testdb');
        $this->resetSingleton(Env::class);
        $this->resetSingleton(Database::class);

        $this->assertFalse(Database::isGatewayMode());
    }

    public function test_false_for_pgandroid_mode(): void
    {
        if (function_exists('androidserver_db_exec')) {
            $this->markTestSkipped('androidserver_db_exec() is unexpectedly registered in this process.');
        }

        $this->setEnv('DB_MODE', 'pgandroid');
        $this->resetSingleton(Env::class);
        $this->resetSingleton(Database::class);

        $this->assertFalse(Database::isGatewayMode());
    }

    public function test_false_when_fake_mode_active_even_with_default_gateway_db_mode(): void
    {
        // Database::fake() short-circuits before the transport is ever
        // resolved — DB_MODE is left at its 'gateway' default, but
        // isGatewayMode() must still report false, per its own docblock
        // ("Database::fake() is not active" is part of the contract).
        Database::fake(['get_user' => []]);

        $this->assertFalse(Database::isGatewayMode());
    }
}
