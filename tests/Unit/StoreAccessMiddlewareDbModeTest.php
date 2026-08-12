<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\AuthContext;
use StoneScriptPHP\Auth\AuthenticatedUser;
use StoneScriptPHP\Database;
use StoneScriptPHP\Env;
use StoneScriptPHP\Routing\Middleware\StoreAccessMiddleware;
use StoneScriptPHP\ApiResponse;

/**
 * StoreAccessMiddleware — DB_MODE-aware setTenantId() guard (2026-08-01),
 * covering the post-membership-check call site (line ~142) that
 * StoreAccessMiddlewareTest.php's own docblock explicitly says is NOT
 * reached by its cases ("covered by integration/E2E, not here").
 *
 * Before this fix, a SUCCESSFUL membership check under DB_MODE=direct/pgandroid
 * still called Database::getGatewayClient()->setTenantId() unconditionally and
 * threw — turning a legitimate, authorized request into an uncaught exception
 * right after access was correctly granted. Same root cause and same fix
 * shape as GatewayTenantMiddleware/SubscriptionMiddleware (see
 * Database::isGatewayMode()'s docblock, which already named this class as a
 * beneficiary before this file's fix actually landed it).
 *
 * Uses a test subclass that overrides the protected fetchMemberships() seam
 * instead of hitting a real auth service — same pattern as
 * AasaanworkProvisionTenantRoute's getAuthClient()/getProvisioner() overrides.
 */
final class StoreAccessMiddlewareDbModeTest extends TestCase
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

    /** @return array{route: array{pattern: string}, params: array{tenantId: string}, headers: array{Authorization: string}} */
    private function tenantScopedRequest(string $tenantId): array
    {
        return [
            'route'   => ['pattern' => '/portal/tenant/{tenantId}/profile', 'service' => 'portal'],
            'params'  => ['tenantId' => $tenantId],
            'headers' => ['Authorization' => 'Bearer test-token'],
        ];
    }

    private function grantingMiddleware(string $tenantId): StoreAccessMiddleware
    {
        return new class ($tenantId) extends StoreAccessMiddleware {
            public function __construct(private string $activeTenantId)
            {
                parent::__construct(['auth_service_url' => 'http://auth.invalid', 'platform_code' => 'testplatform']);
            }

            protected function fetchMemberships(string $bearerToken): array
            {
                return [['tenant_id' => $this->activeTenantId, 'role' => 'owner', 'status' => 'active']];
            }
        };
    }

    public function test_gateway_mode_sets_tenant_id_after_membership_granted(): void
    {
        $this->setEnv('DB_MODE', 'gateway');
        $this->setEnv('DB_GATEWAY_URL', 'http://gateway.invalid:9000');
        $this->setEnv('DB_GATEWAY_PLATFORM', 'testplatform');
        $this->setEnv('DB_GATEWAY_SCHEMA_NAME', 'main');
        $this->setEnv('DB_GATEWAY_TENANT_SCHEMA_NAME', 'tenant');
        $this->resetSingleton(Env::class);
        $this->resetSingleton(Database::class);

        AuthContext::setUser(new AuthenticatedUser(user_id: 'identity-1', tenant_id: 'tenant-abc'));

        $mw  = $this->grantingMiddleware('tenant-abc');
        $res = $mw->handle($this->tenantScopedRequest('tenant-abc'), function () {
            return new ApiResponse('ok', 'passed', null, 200);
        });

        $this->assertSame('passed', $res->message);
        $this->assertSame(
            'tenant-abc',
            Database::getGatewayClient()->getTenantId(),
            'gateway mode must still call setTenantId() after a granted membership check'
        );
    }

    public function test_direct_mode_skips_set_tenant_id_and_still_grants_access(): void
    {
        $this->setEnv('DB_MODE', 'direct');
        $this->setEnv('DB_NAME', 'testdb');
        $this->resetSingleton(Env::class);
        $this->resetSingleton(Database::class);

        AuthContext::setUser(new AuthenticatedUser(user_id: 'identity-1', tenant_id: 'tenant-abc'));

        $mw = $this->grantingMiddleware('tenant-abc');

        // Regression guard: pre-fix, a GRANTED membership check under
        // DB_MODE=direct still threw right here trying to set gateway
        // tenant context — turning "access granted" into a 500.
        $res = $mw->handle($this->tenantScopedRequest('tenant-abc'), function () {
            return new ApiResponse('ok', 'passed', null, 200);
        });

        $this->assertSame('passed', $res->message, 'must grant access without throwing under DB_MODE=direct');
    }

    public function test_pgandroid_mode_skips_set_tenant_id_and_still_grants_access(): void
    {
        if (function_exists('androidserver_db_exec')) {
            $this->markTestSkipped('androidserver_db_exec() is unexpectedly registered in this process.');
        }

        $this->setEnv('DB_MODE', 'pgandroid');
        $this->resetSingleton(Env::class);
        $this->resetSingleton(Database::class);

        AuthContext::setUser(new AuthenticatedUser(user_id: 'identity-1', tenant_id: 'tenant-abc'));

        $mw = $this->grantingMiddleware('tenant-abc');
        $res = $mw->handle($this->tenantScopedRequest('tenant-abc'), function () {
            return new ApiResponse('ok', 'passed', null, 200);
        });

        $this->assertSame('passed', $res->message, 'must grant access without throwing under DB_MODE=pgandroid');
    }
}
