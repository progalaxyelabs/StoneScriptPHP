<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\AuthContext;
use StoneScriptPHP\Auth\AuthenticatedUser;
use StoneScriptPHP\Database;
use StoneScriptPHP\Env;
use StoneScriptPHP\Routing\Middleware\GatewayTenantMiddleware;
use StoneScriptPHP\ApiResponse;

/**
 * GatewayTenantMiddleware — DB_MODE-aware setTenantId() guard (2026-08-01).
 *
 * Before this fix, `handle()` called `Database::getGatewayClient()->setTenantId()`
 * unconditionally whenever a tenant_id was present on the API token — which threw
 * ("only usable when DB_MODE=gateway") for every authenticated request under
 * DB_MODE=direct/pgandroid, found live by the android-server manual-build-v2 pass.
 * These tests pin both branches: the pre-existing gateway-mode behavior is
 * byte-identical (still calls setTenantId()), and the new non-gateway branch no
 * longer throws — it skips the gateway-specific call and still proceeds to $next().
 */
final class GatewayTenantMiddlewareTest extends TestCase
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

        AuthContext::clear();
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

    private function userWithTenant(string $tenantId): AuthenticatedUser
    {
        return new AuthenticatedUser(
            user_id: 'identity-1',
            email: 'owner@example.com',
            tenant_id: $tenantId,
        );
    }

    private function nextSpy(): callable
    {
        return function (array $request): ApiResponse {
            return new ApiResponse('ok', 'passed', null, 200);
        };
    }

    public function test_gateway_mode_sets_tenant_id_on_gateway_client(): void
    {
        $this->setEnv('DB_MODE', 'gateway');
        $this->setEnv('DB_GATEWAY_URL', 'http://gateway.invalid:9000');
        $this->setEnv('DB_GATEWAY_PLATFORM', 'testplatform');
        $this->setEnv('DB_GATEWAY_SCHEMA_NAME', 'main');
        $this->setEnv('DB_GATEWAY_TENANT_SCHEMA_NAME', 'tenant');
        $this->resetSingleton(Env::class);
        $this->resetSingleton(Database::class);

        AuthContext::setUser($this->userWithTenant('tenant-abc'));

        $mw  = new GatewayTenantMiddleware();
        $res = $mw->handle(['params' => []], $this->nextSpy());

        $this->assertSame('passed', $res->message, 'still proceeds to $next()');
        $this->assertSame(
            'tenant-abc',
            Database::getGatewayClient()->getTenantId(),
            'gateway mode must still call setTenantId() — pre-existing behavior unchanged'
        );
    }

    public function test_direct_mode_skips_set_tenant_id_and_still_proceeds(): void
    {
        $this->setEnv('DB_MODE', 'direct');
        $this->setEnv('DB_NAME', 'testdb');
        $this->resetSingleton(Env::class);
        $this->resetSingleton(Database::class);

        AuthContext::setUser($this->userWithTenant('tenant-abc'));

        $mw  = new GatewayTenantMiddleware();
        // Regression guard: pre-fix, this threw
        // "Database::getGatewayClient() is only usable when DB_MODE=gateway"
        // for every authenticated request under DB_MODE=direct.
        $res = $mw->handle(['params' => []], $this->nextSpy());

        $this->assertSame('passed', $res->message, 'must proceed to $next() instead of throwing');
    }

    public function test_pgandroid_mode_skips_set_tenant_id_and_still_proceeds(): void
    {
        if (function_exists('androidserver_db_exec')) {
            $this->markTestSkipped('androidserver_db_exec() is unexpectedly registered in this process.');
        }

        $this->setEnv('DB_MODE', 'pgandroid');
        $this->resetSingleton(Env::class);
        $this->resetSingleton(Database::class);

        AuthContext::setUser($this->userWithTenant('tenant-abc'));

        $mw  = new GatewayTenantMiddleware();
        $res = $mw->handle(['params' => []], $this->nextSpy());

        $this->assertSame('passed', $res->message, 'must proceed to $next() instead of throwing');
    }

    public function test_no_tenant_id_never_touches_transport_regardless_of_mode(): void
    {
        $this->setEnv('DB_MODE', 'direct');
        $this->setEnv('DB_NAME', 'testdb');
        $this->resetSingleton(Env::class);
        $this->resetSingleton(Database::class);

        // Auth token (no tenant_id) — e.g. an identity JWT, not yet exchanged.
        AuthContext::setUser(new AuthenticatedUser(user_id: 'identity-1'));

        $mw  = new GatewayTenantMiddleware();
        $res = $mw->handle(['params' => []], $this->nextSpy());

        $this->assertSame('passed', $res->message);
    }
}
