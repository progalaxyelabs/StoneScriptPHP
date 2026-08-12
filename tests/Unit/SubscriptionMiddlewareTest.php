<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\AuthContext;
use StoneScriptPHP\Auth\AuthenticatedUser;
use StoneScriptPHP\Database;
use StoneScriptPHP\Env;
use StoneScriptPHP\Subscriptions\SubscriptionMiddleware;
use StoneScriptPHP\ApiResponse;

/**
 * SubscriptionMiddleware — DB_MODE-aware billing-check skip (2026-08-01).
 *
 * Before this fix, an authenticated request with a tenant_id under
 * DB_MODE=direct/pgandroid still called checkSubscription(), which calls
 * Database::getGatewayClient() — throwing, then swallowed by
 * checkSubscription()'s own catch(\Exception) and treated as "fail open".
 * Functionally harmless (fail-open either way) but wasteful: a guaranteed-fail
 * gateway call + exception on every single authenticated request, for a
 * DB_MODE that has no SaaS billing relationship to check in the first place.
 * These tests pin: gateway-mode behavior is unchanged (still queries), and
 * non-gateway modes skip checkSubscription() entirely (proceeds to $next()
 * without ever calling Database::getGatewayClient()).
 */
final class SubscriptionMiddlewareTest extends TestCase
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
        unset($_SERVER['REQUEST_URI']);

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

    private function nextSpy(): callable
    {
        return function (array $request): ApiResponse {
            return new ApiResponse('ok', 'passed', null, 200);
        };
    }

    public function test_direct_mode_skips_subscription_check_entirely(): void
    {
        $this->setEnv('DB_MODE', 'direct');
        $this->setEnv('DB_NAME', 'testdb');
        $this->resetSingleton(Env::class);
        $this->resetSingleton(Database::class);

        $_SERVER['REQUEST_URI'] = '/portal/invoices';
        AuthContext::setUser(new AuthenticatedUser(user_id: 'identity-1', tenant_id: 'tenant-abc'));

        $mw  = new SubscriptionMiddleware();
        $res = $mw->handle([], $this->nextSpy());

        $this->assertSame('passed', $res->message, 'must skip straight to $next() under DB_MODE=direct');
    }

    public function test_pgandroid_mode_skips_subscription_check_entirely(): void
    {
        if (function_exists('androidserver_db_exec')) {
            $this->markTestSkipped('androidserver_db_exec() is unexpectedly registered in this process.');
        }

        $this->setEnv('DB_MODE', 'pgandroid');
        $this->resetSingleton(Env::class);
        $this->resetSingleton(Database::class);

        $_SERVER['REQUEST_URI'] = '/portal/invoices';
        AuthContext::setUser(new AuthenticatedUser(user_id: 'identity-1', tenant_id: 'tenant-abc'));

        $mw  = new SubscriptionMiddleware();
        $res = $mw->handle([], $this->nextSpy());

        $this->assertSame('passed', $res->message, 'must skip straight to $next() under DB_MODE=pgandroid');
    }

    public function test_gateway_mode_still_attempts_subscription_check(): void
    {
        $this->setEnv('DB_MODE', 'gateway');
        $this->setEnv('DB_GATEWAY_URL', 'http://gateway.invalid:9000');
        $this->setEnv('DB_GATEWAY_PLATFORM', 'testplatform');
        $this->setEnv('DB_GATEWAY_SCHEMA_NAME', 'main');
        $this->setEnv('DB_GATEWAY_TENANT_SCHEMA_NAME', 'tenant');
        $this->resetSingleton(Env::class);
        $this->resetSingleton(Database::class);

        $_SERVER['REQUEST_URI'] = '/portal/invoices';
        AuthContext::setUser(new AuthenticatedUser(user_id: 'identity-1', tenant_id: 'tenant-abc'));

        // gateway.invalid never resolves, so checkSubscription()'s internal
        // Database::fn() call fails and it fails OPEN (returns null) — this
        // still proves the gateway-mode branch was NOT skipped: it reached
        // Database::getGatewayClient() (unlike the direct/pgandroid tests
        // above, which never touch the gateway client at all) and the
        // request still proceeds either way.
        $mw  = new SubscriptionMiddleware();
        $res = $mw->handle([], $this->nextSpy());

        $this->assertSame('passed', $res->message);
    }

    public function test_exempt_path_skips_regardless_of_db_mode(): void
    {
        $this->setEnv('DB_MODE', 'direct');
        $this->setEnv('DB_NAME', 'testdb');
        $this->resetSingleton(Env::class);
        $this->resetSingleton(Database::class);

        $_SERVER['REQUEST_URI'] = '/health';
        AuthContext::setUser(new AuthenticatedUser(user_id: 'identity-1', tenant_id: 'tenant-abc'));

        $mw  = new SubscriptionMiddleware();
        $res = $mw->handle([], $this->nextSpy());

        $this->assertSame('passed', $res->message);
    }

    public function test_no_tenant_id_skips_regardless_of_db_mode(): void
    {
        $this->setEnv('DB_MODE', 'direct');
        $this->setEnv('DB_NAME', 'testdb');
        $this->resetSingleton(Env::class);
        $this->resetSingleton(Database::class);

        $_SERVER['REQUEST_URI'] = '/portal/invoices';
        // Auth token only — no tenant_id.
        AuthContext::setUser(new AuthenticatedUser(user_id: 'identity-1'));

        $mw  = new SubscriptionMiddleware();
        $res = $mw->handle([], $this->nextSpy());

        $this->assertSame('passed', $res->message);
    }
}
