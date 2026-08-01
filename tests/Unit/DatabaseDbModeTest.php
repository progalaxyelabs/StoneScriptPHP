<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Database;
use StoneScriptPHP\Env;
use StoneScriptPHP\TenantDatabaseUnavailableException;

/**
 * Integration-flavored unit tests for Database::fn()'s DB_MODE dispatch
 * (gateway | direct | pgandroid) added by the pluggable DbTransport refactor
 * (see src/Db/DbTransport.php).
 *
 * These go through the REAL (non-fake) Database::fn() path, so both the
 * Env and Database singletons get mutated. Both are reset via reflection in
 * tearDown() on every test in this class — Database has no fake-mode-style
 * public reset, and once initConnection() successfully resolves ANY
 * transport (even one that later fails its actual call), it is cached for
 * the rest of the PHP process; leaving that uncleared would leak into
 * unrelated tests elsewhere in the suite that expect the pristine
 * "no transport resolved yet" starting state.
 */
final class DatabaseDbModeTest extends TestCase
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

    private function unsetEnv(string $key): void
    {
        putenv($key);
        unset($_ENV[$key]);
    }

    private function resetSingleton(string $class): void
    {
        $prop = new \ReflectionProperty($class, '_instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    public function test_direct_mode_missing_db_name_fails_loud_before_any_query(): void
    {
        $this->setEnv('DB_MODE', 'direct');
        $this->unsetEnv('DB_NAME');
        $this->resetSingleton(Env::class);
        $this->resetSingleton(Database::class);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/DB_NAME is required for DB_MODE=direct/');

        Database::fn('get_user', []);
    }

    public function test_direct_mode_unreachable_host_maps_to_tenant_unavailable_503(): void
    {
        $this->setEnv('DB_MODE', 'direct');
        // Loopback + a privileged/unused port -> ECONNREFUSED immediately,
        // no hang, no real Postgres required.
        $this->setEnv('DB_HOST', '127.0.0.1');
        $this->setEnv('DB_PORT', '1');
        $this->setEnv('DB_NAME', 'nonexistent_test_db');
        $this->resetSingleton(Env::class);
        $this->resetSingleton(Database::class);

        $this->expectException(TenantDatabaseUnavailableException::class);

        Database::fn('get_user', []);
    }

    public function test_pgandroid_mode_missing_bridge_maps_to_tenant_unavailable_503(): void
    {
        if (function_exists('androidserver_db_exec')) {
            $this->markTestSkipped('androidserver_db_exec() is unexpectedly registered in this process.');
        }

        $this->setEnv('DB_MODE', 'pgandroid');
        $this->resetSingleton(Env::class);
        $this->resetSingleton(Database::class);

        $this->expectException(TenantDatabaseUnavailableException::class);

        Database::fn('get_user', []);
    }

    public function test_unknown_db_mode_fails_loud_at_boot_not_at_call(): void
    {
        $this->setEnv('DB_MODE', 'not-a-real-mode');
        $this->resetSingleton(Env::class);
        $this->resetSingleton(Database::class);

        // Env::__construct() itself throws before Database ever gets involved.
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Invalid DB_MODE/');

        Database::fn('get_user', []);
    }

    public function test_gateway_mode_unchanged_when_db_mode_explicitly_set(): void
    {
        // Regression guard: DB_MODE=gateway (explicit, not just default) must
        // behave identically to the unset-default case — same required-config
        // errors (raised eagerly by Env::__construct(), same as pre-refactor;
        // Database::buildGatewayTransport()'s own DB_GATEWAY_URL check is
        // never reached because Env already fails first), same exception type.
        $this->setEnv('DB_MODE', 'gateway');
        $this->unsetEnv('DB_GATEWAY_URL');
        $this->unsetEnv('DB_GATEWAY_PLATFORM');
        $this->resetSingleton(Env::class);
        $this->resetSingleton(Database::class);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches("/Required configuration 'DB_GATEWAY_URL' is missing or empty/");

        Database::fn('get_user', []);
    }
}
