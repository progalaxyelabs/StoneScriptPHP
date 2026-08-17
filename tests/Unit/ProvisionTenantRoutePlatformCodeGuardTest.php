<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\AuthContext;
use StoneScriptPHP\Auth\AuthenticatedUser;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthConfig;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthServiceClient;
use StoneScriptPHP\Auth\ExternalAuth\Routes\ProvisionTenantRoute;
use StoneScriptPHP\Env;
use StoneScriptPHP\Tenancy\TenantProvisioner;

/**
 * SECURITY — cross-platform token rejection on ProvisionTenantRoute.
 *
 * The framework's shared auth issuer means a signature/exp-valid identity
 * token minted for platform A is cryptographically valid at platform B too.
 * Before this fix, ProvisionTenantRoute::process() never compared the
 * token's `platform_code` claim to this server's own configured
 * `PLATFORM_CODE` — it only checked `auth()->user_id`, then stamped the new
 * membership with $this->config->platformCode (the server's OWN code),
 * ignoring the token's claim entirely. That let an identity holding a token
 * minted for one platform create a tenant/membership on a DIFFERENT,
 * supposedly-closed platform.
 *
 * See {@see \StoneScriptPHP\Auth\PlatformCodeGuard} for the shared rule and
 * decision table this test suite pins.
 */
final class ProvisionTenantRoutePlatformCodeGuardTest extends TestCase
{
    private array $envVarsSetByThisTest = [];

    protected function setUp(): void
    {
        $this->setEnvIfEmpty('DB_GATEWAY_URL', 'http://localhost:9000');
        $this->setEnvIfEmpty('DB_GATEWAY_PLATFORM', 'test-platform');
        $this->setEnvIfEmpty('AUTH_SERVICE_URL', 'http://localhost:3139');
        $this->setEnvIfEmpty('AUTH_ISSUER', 'http://localhost:3139');
        $this->resetEnvSingleton();

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer test-raw-token';
    }

    protected function tearDown(): void
    {
        $this->resetEnvSingleton();
        AuthContext::clear();
        unset($_SERVER['HTTP_AUTHORIZATION']);

        foreach ($this->envVarsSetByThisTest as $name) {
            putenv($name);
            unset($_ENV[$name]);
        }
        $this->envVarsSetByThisTest = [];
    }

    private function resetEnvSingleton(): void
    {
        $ref = new \ReflectionClass(Env::class);
        $prop = $ref->getProperty('_instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    private function setEnvIfEmpty(string $name, string $value): void
    {
        if (empty(getenv($name))) {
            putenv("{$name}={$value}");
            $this->envVarsSetByThisTest[] = $name;
        }
    }

    private function config(string $platformCode = 'testapp'): ExternalAuthConfig
    {
        return new ExternalAuthConfig([
            'provision_tenant' => true,
            'platform_secret'  => 'test-secret',
            'platform_code'    => $platformCode,
        ]);
    }

    private function route(ExternalAuthConfig $config, ?FakeClient $client = null, ?SpyProvisioner $provisioner = null): ProvisionTenantRoute
    {
        $client = $client ?? new FakeClient();
        $provisioner = $provisioner ?? new SpyProvisioner();
        return new ProvisionTenantRoute($client, $config->hooks, $config, $provisioner);
    }

    private function authenticatedUser(?string $platformCode): void
    {
        AuthContext::setUser(new AuthenticatedUser(
            user_id: 'identity-1',
            email: 'owner@example.com',
            platform_code: $platformCode
        ));
    }

    public function test_same_platform_token_is_admitted_and_provisions(): void
    {
        $this->authenticatedUser('testapp');
        $provisioner = new SpyProvisioner();
        $route = $this->route($this->config('testapp'), null, $provisioner);
        $route->tenant_name = 'Acme Store';
        $route->idempotency_key = 'idem-1';

        $res = $route->process();

        $this->assertSame('ok', $res->status);
        $this->assertTrue($provisioner->provisionCalled, 'same-platform traffic must pass unchanged');
    }

    public function test_cross_platform_token_is_rejected_403_before_any_write(): void
    {
        // Token was minted at 'otherapp'; this server is 'testapp'.
        $this->authenticatedUser('otherapp');
        $provisioner = new SpyProvisioner();
        $route = $this->route($this->config('testapp'), null, $provisioner);
        $route->tenant_name = 'Acme Store';
        $route->idempotency_key = 'idem-1';

        $res = $route->process();

        $this->assertSame('error', $res->status);
        $this->assertSame(403, $res->httpStatusCode);
        $this->assertSame('wrong_platform', $res->data['error'] ?? null);
        $this->assertFalse($provisioner->provisionCalled, 'must reject before any tenant/database provisioning');
    }

    public function test_missing_platform_code_claim_is_rejected_when_platform_is_configured(): void
    {
        // No platform_code claim on the token at all — unprovable, fail closed.
        $this->authenticatedUser(null);
        $provisioner = new SpyProvisioner();
        $route = $this->route($this->config('testapp'), null, $provisioner);
        $route->tenant_name = 'Acme Store';
        $route->idempotency_key = 'idem-1';

        $res = $route->process();

        $this->assertSame('error', $res->status);
        $this->assertSame(403, $res->httpStatusCode);
        $this->assertSame('wrong_platform', $res->data['error'] ?? null);
        $this->assertFalse($provisioner->provisionCalled);
    }

    public function test_cross_platform_token_never_calls_create_membership(): void
    {
        $this->authenticatedUser('otherapp');
        $client = new FakeClient();
        $provisioner = new SpyProvisioner();
        $route = $this->route($this->config('testapp'), $client, $provisioner);
        $route->tenant_name = 'Acme Store';
        $route->idempotency_key = 'idem-1';

        $route->process();

        $this->assertNull($client->lastCreateMembershipData, 'no server-to-server membership call on a rejected cross-platform token');
    }

    public function test_unconfigured_platform_code_admits_any_token_platform_code(): void
    {
        // This server has NOT set PLATFORM_CODE — deliberate backward-compat
        // fail-open (see PlatformCodeGuard's class docblock): a deployment
        // that never opted into platform-scoped tokens must not be bricked.
        $this->authenticatedUser('some-other-platform');
        $provisioner = new SpyProvisioner();
        $route = $this->route($this->config(''), null, $provisioner);
        $route->tenant_name = 'Acme Store';
        $route->idempotency_key = 'idem-1';

        $res = $route->process();

        $this->assertSame('ok', $res->status, 'unconfigured PLATFORM_CODE must fail OPEN');
        $this->assertTrue($provisioner->provisionCalled);
    }
}

/** Minimal fake — no network, only the methods ProvisionTenantRoute calls. */
final class FakeClient extends ExternalAuthServiceClient
{
    public array $membershipsResponse = ['memberships' => []];
    public ?array $lastCreateMembershipData = null;

    public function __construct()
    {
        parent::__construct('http://auth.invalid', 'testapp');
    }

    public function getMemberships(?string $authToken = null): array
    {
        return $this->membershipsResponse;
    }

    public function createMembership(array $data, string $platformSecret): array
    {
        $this->lastCreateMembershipData = $data;
        return [
            'membership_id'    => 'mem-1',
            'tenant_id'        => $data['tenant_id'],
            'tenant_db_schema' => $data['tenant_db_schema'],
            'is_new_tenant'    => true,
            'access_token'     => 'fake-access-token',
        ];
    }
}

/** Minimal spy provisioner — no real gateway calls. */
final class SpyProvisioner extends TenantProvisioner
{
    public bool $provisionCalled = false;
    public ?array $lastData = null;

    public function __construct()
    {
        parent::__construct('testapp', 'tenant', 'http://gateway.invalid', 'admin-token');
    }

    protected function createTenantRecord(array $data): array
    {
        return $data;
    }

    protected function createDatabase(array $data): void
    {
        $this->provisionCalled = true;
        $this->lastData = $data;
    }

    protected function seedData(array $data): void
    {
    }
}
