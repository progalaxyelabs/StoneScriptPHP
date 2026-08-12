<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Auth\AuthContext;
use StoneScriptPHP\Auth\AuthenticatedUser;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthConfig;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthServiceClient;
use StoneScriptPHP\Auth\ExternalAuth\Routes\ProvisionTenantRoute;
use StoneScriptPHP\Env;
use StoneScriptPHP\Tenancy\TenantProvisioner;

/**
 * ProvisionTenantRoute (2026-08-12 investigation follow-up) — three real,
 * confirmed gaps fixed together:
 *
 *   #2 idempotency ordering: generateUuid() + provisioner->provision() ran
 *      UNCONDITIONALLY before idempotency_key was ever checked — a retry
 *      physically provisioned a new, orphaned tenant database every time.
 *      Fixed via findExistingTenantId() + the existing-tenant guard.
 *   #3 tenant_name: only store_name existed, contradicting AUTH-SPEC's own
 *      canonical field name — a spec-compliant client silently got a blank
 *      tenant name (reflection-based property injection drops unmatched
 *      keys with no error). Fixed by making tenant_name the only accepted
 *      field name (store_name later removed entirely).
 *   #5 oauth_state: missing entirely — a downstream platform needed it for
 *      its OAuth-signup-then-provision handoff (AUTH-SPEC §3d) and had to
 *      reimplement provision-tenant from scratch in part because of this.
 *      Fixed via ExternalAuthServiceClient::promoteOAuthConnection().
 *
 * Uses fake ExternalAuthServiceClient/TenantProvisioner subclasses (no
 * network) — same "test seam via protected/overridable methods" discipline
 * this whole investigation is about.
 */
final class ProvisionTenantRouteBugFixesTest extends TestCase
{
    private array $envVarsSetByThisTest = [];

    protected function setUp(): void
    {
        $this->setEnvIfEmpty('DB_GATEWAY_URL', 'http://localhost:9000');
        $this->setEnvIfEmpty('DB_GATEWAY_PLATFORM', 'test-platform');
        $this->setEnvIfEmpty('AUTH_SERVICE_URL', 'http://localhost:3139');
        $this->setEnvIfEmpty('AUTH_ISSUER', 'http://localhost:3139');
        $ref = new \ReflectionClass(Env::class);
        $prop = $ref->getProperty('_instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        // findExistingTenantId()/oauth promote both need a raw Bearer token
        // via BaseExternalAuthRoute::getBearerToken() — outside a real web
        // server, getallheaders() returns nothing, so this is the only
        // reachable fallback in a PHPUnit process.
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer test-raw-token';
    }

    protected function tearDown(): void
    {
        $ref = new \ReflectionClass(Env::class);
        $prop = $ref->getProperty('_instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        AuthContext::clear();
        unset($_SERVER['HTTP_AUTHORIZATION']);

        foreach ($this->envVarsSetByThisTest as $name) {
            putenv($name);
            unset($_ENV[$name]);
        }
        $this->envVarsSetByThisTest = [];
    }

    private function setEnvIfEmpty(string $name, string $value): void
    {
        if (empty(getenv($name))) {
            putenv("{$name}={$value}");
            $this->envVarsSetByThisTest[] = $name;
        }
    }

    private function config(): ExternalAuthConfig
    {
        return new ExternalAuthConfig([
            'provision_tenant' => true,
            'platform_secret'  => 'test-secret',
        ]);
    }

    private function route(FakeExternalAuthServiceClient $client, ?SpyTenantProvisioner $provisioner = null): ProvisionTenantRoute
    {
        $config = $this->config();
        return new ProvisionTenantRoute($client, $config->hooks, $config, $provisioner);
    }

    private function authenticatedUser(): void
    {
        AuthContext::setUser(new AuthenticatedUser(user_id: 'identity-1', email: 'owner@example.com'));
    }

    // ── #3 tenant_name ───────────────────────────────────────────────────

    public function test_accepts_tenant_name_field(): void
    {
        $this->authenticatedUser();
        $client = new FakeExternalAuthServiceClient();
        $provisioner = new SpyTenantProvisioner();
        $route = $this->route($client, $provisioner);
        $route->tenant_name = 'Acme Store';
        $route->idempotency_key = 'idem-1';

        $res = $route->process();

        $this->assertSame('ok', $res->status);
        $this->assertSame('Acme Store', $provisioner->lastData['tenant_name'] ?? null);
    }

    /**
     * store_name was removed entirely (2026-08-12) — see tenant_name's
     * property docblock. `required` enforcement now lives solely in
     * validation_rules() (checked by the Router against raw request input
     * BEFORE process() runs — see Router::executeHandler()), matching
     * idempotency_key's own pattern, not a manual guard inside process().
     * This is a router-edge concern, not testable via a direct process()
     * call — see test_validation_rules_requires_tenant_name() instead,
     * which pins the actual enforcement contract.
     */
    public function test_store_name_property_no_longer_exists(): void
    {
        $this->assertFalse(
            property_exists(ProvisionTenantRoute::class, 'store_name'),
            'store_name must be fully removed, not just deprecated — tenant_name is the only accepted field name'
        );
    }

    public function test_validation_rules_requires_tenant_name(): void
    {
        $route = $this->route(new FakeExternalAuthServiceClient());

        $rules = $route->validation_rules();

        $this->assertArrayHasKey('tenant_name', $rules);
        $this->assertStringContainsString('required', $rules['tenant_name']);
        $this->assertArrayNotHasKey('store_name', $rules);
    }

    // ── #2 existing-tenant guard ────────────────────────────────────────

    public function test_existing_tenant_same_name_replays_without_calling_provisioner(): void
    {
        $this->authenticatedUser();
        $client = new FakeExternalAuthServiceClient();
        $client->membershipsResponse = ['memberships' => [
            ['tenant_id' => 'existing-tenant-99', 'tenant_name' => 'Acme Store', 'status' => 'active'],
        ]];
        $provisioner = new SpyTenantProvisioner();
        $route = $this->route($client, $provisioner);
        $route->tenant_name = 'Acme Store';
        $route->idempotency_key = 'idem-1';

        $res = $route->process();

        $this->assertSame('ok', $res->status);
        $this->assertFalse($provisioner->provisionCalled, 'provisioner->provision() must NOT run on an existing-tenant replay');
        $this->assertSame('existing-tenant-99', $client->lastCreateMembershipData['tenant_id'] ?? null);
    }

    /**
     * Name comparison is normalized (trim + casefold) — a genuine retry can
     * have trivial whitespace/case drift between two rapid-fire submits and
     * must still replay, not false-409.
     */
    public function test_existing_tenant_name_matches_case_and_whitespace_insensitively(): void
    {
        $this->authenticatedUser();
        $client = new FakeExternalAuthServiceClient();
        $client->membershipsResponse = ['memberships' => [
            ['tenant_id' => 'existing-tenant-99', 'tenant_name' => '  Acme Store  ', 'status' => 'active'],
        ]];
        $provisioner = new SpyTenantProvisioner();
        $route = $this->route($client, $provisioner);
        $route->tenant_name = 'ACME STORE';
        $route->idempotency_key = 'idem-1';

        $res = $route->process();

        $this->assertSame('ok', $res->status);
        $this->assertFalse($provisioner->provisionCalled);
    }

    /**
     * Different name = not a retry — a genuine attempt to register a second,
     * differently-named tenant. Silently replaying the existing one would log
     * the identity into a store they didn't ask for with no indication their
     * real request was never created — so this returns 409, matching the
     * tenant_already_exists error code downstream platforms already use.
     */
    public function test_existing_tenant_different_name_returns_409_and_does_not_provision(): void
    {
        $this->authenticatedUser();
        $client = new FakeExternalAuthServiceClient();
        $client->membershipsResponse = ['memberships' => [
            ['tenant_id' => 'existing-tenant-99', 'tenant_name' => 'ABC Medicals', 'status' => 'active'],
        ]];
        $provisioner = new SpyTenantProvisioner();
        $route = $this->route($client, $provisioner);
        $route->tenant_name = 'XYZ Pharmacy';
        $route->idempotency_key = 'idem-1';

        $res = $route->process();

        $this->assertSame('error', $res->status);
        $this->assertSame(409, $res->httpStatusCode);
        $this->assertSame('tenant_already_exists', $res->data['error'] ?? null);
        $this->assertFalse($provisioner->provisionCalled, 'must not provision AND must not silently replay a differently-named existing tenant');
        $this->assertNull($client->lastCreateMembershipData, 'must not call createMembership() at all on the 409 path');
    }

    public function test_no_existing_tenant_runs_provisioner_normally(): void
    {
        $this->authenticatedUser();
        $client = new FakeExternalAuthServiceClient();
        $client->membershipsResponse = ['memberships' => []];
        $provisioner = new SpyTenantProvisioner();
        $route = $this->route($client, $provisioner);
        $route->tenant_name = 'Acme Store';
        $route->idempotency_key = 'idem-1';

        $route->process();

        $this->assertTrue($provisioner->provisionCalled, 'a genuinely new tenant must still run the provisioner');
    }

    public function test_allow_additional_tenant_bypasses_the_guard(): void
    {
        $this->authenticatedUser();
        $client = new FakeExternalAuthServiceClient();
        // Identity already has a tenant...
        $client->membershipsResponse = ['memberships' => [['tenant_id' => 'existing-tenant-99', 'status' => 'active']]];
        $provisioner = new SpyTenantProvisioner();
        $route = $this->route($client, $provisioner);
        $route->tenant_name = 'Second Business';
        $route->idempotency_key = 'idem-2';
        $route->allow_additional_tenant = true;

        $route->process();

        // ...but allow_additional_tenant=true means provision a genuinely NEW one anyway.
        $this->assertTrue($provisioner->provisionCalled, 'allow_additional_tenant=true must skip the existing-tenant guard');
        $this->assertNotSame('existing-tenant-99', $provisioner->lastData['tenant_id'] ?? null);
    }

    public function test_membership_lookup_failure_fails_open_and_still_provisions(): void
    {
        $this->authenticatedUser();
        $client = new FakeExternalAuthServiceClient();
        $client->throwOnGetMemberships = true;
        $provisioner = new SpyTenantProvisioner();
        $route = $this->route($client, $provisioner);
        $route->tenant_name = 'Acme Store';
        $route->idempotency_key = 'idem-1';

        $res = $route->process();

        $this->assertSame('ok', $res->status, 'a failed existing-tenant lookup must fail OPEN, not block provisioning');
        $this->assertTrue($provisioner->provisionCalled);
    }

    // ── #5 oauth_state / promote ────────────────────────────────────────

    public function test_oauth_state_calls_promote_before_provisioning(): void
    {
        $this->authenticatedUser();
        $client = new FakeExternalAuthServiceClient();
        $provisioner = new SpyTenantProvisioner();
        $route = $this->route($client, $provisioner);
        $route->tenant_name = 'Acme Store';
        $route->idempotency_key = 'idem-1';
        $route->oauth_state = 'oauth-state-token-abc';

        $route->process();

        $this->assertSame('oauth-state-token-abc', $client->lastPromoteOauthState);
        $this->assertTrue($provisioner->provisionCalled);
    }

    public function test_oauth_promote_failure_returns_500_and_never_provisions(): void
    {
        $this->authenticatedUser();
        $client = new FakeExternalAuthServiceClient();
        $client->throwOnPromote = true;
        $provisioner = new SpyTenantProvisioner();
        $route = $this->route($client, $provisioner);
        $route->tenant_name = 'Acme Store';
        $route->idempotency_key = 'idem-1';
        $route->oauth_state = 'oauth-state-token-abc';

        $res = $route->process();

        $this->assertSame('error', $res->status);
        $this->assertSame(500, $res->httpStatusCode);
        $this->assertFalse($provisioner->provisionCalled, 'must not provision a tenant when OAuth promote fails');
    }

    public function test_oauth_state_skipped_on_existing_tenant_replay(): void
    {
        $this->authenticatedUser();
        $client = new FakeExternalAuthServiceClient();
        // tenant_name MUST match the request below — otherwise this hits the
        // 409-different-name path instead of a genuine replay, which would
        // also never call promote but for the wrong reason (false-green risk).
        $client->membershipsResponse = ['memberships' => [
            ['tenant_id' => 'existing-tenant-99', 'tenant_name' => 'Acme Store', 'status' => 'active'],
        ]];
        $provisioner = new SpyTenantProvisioner();
        $route = $this->route($client, $provisioner);
        $route->tenant_name = 'Acme Store';
        $route->idempotency_key = 'idem-1';
        $route->oauth_state = 'oauth-state-token-abc';

        $res = $route->process();

        $this->assertSame('ok', $res->status, 'must be a genuine replay, not the 409-different-name path');
        $this->assertNull($client->lastPromoteOauthState, 'a replay already has its OAuth connection promoted from the original call');
    }

    public function test_no_oauth_state_never_calls_promote(): void
    {
        $this->authenticatedUser();
        $client = new FakeExternalAuthServiceClient();
        $provisioner = new SpyTenantProvisioner();
        $route = $this->route($client, $provisioner);
        $route->tenant_name = 'Acme Store';
        $route->idempotency_key = 'idem-1';

        $route->process();

        $this->assertNull($client->lastPromoteOauthState);
    }
}

/**
 * Fake ExternalAuthServiceClient — no network. Overrides the 3 methods
 * ProvisionTenantRoute actually calls (getMemberships, createMembership,
 * promoteOAuthConnection); everything else is unused by these tests.
 */
final class FakeExternalAuthServiceClient extends ExternalAuthServiceClient
{
    public array $membershipsResponse = ['memberships' => []];
    public bool $throwOnGetMemberships = false;
    public bool $throwOnPromote = false;

    public ?array $lastCreateMembershipData = null;
    public ?string $lastPromoteOauthState = null;

    public function __construct()
    {
        parent::__construct('http://auth.invalid', 'testplatform');
    }

    public function getMemberships(?string $authToken = null): array
    {
        if ($this->throwOnGetMemberships) {
            throw new \RuntimeException('auth service unreachable');
        }
        return $this->membershipsResponse;
    }

    public function createMembership(array $data, string $platformSecret): array
    {
        $this->lastCreateMembershipData = $data;
        return [
            'membership_id'  => 'mem-1',
            'tenant_id'      => $data['tenant_id'],
            'tenant_db_schema' => $data['tenant_db_schema'],
            'is_new_tenant'  => true,
            'access_token'   => 'fake-access-token',
        ];
    }

    public function promoteOAuthConnection(string $oauthState, string $authToken): array
    {
        $this->lastPromoteOauthState = $oauthState;
        if ($this->throwOnPromote) {
            throw new \RuntimeException('promote failed');
        }
        return ['success' => true];
    }
}

/**
 * Spy TenantProvisioner — records whether provision() ran and with what
 * data, without touching a real gateway (createDatabase()/createTenantRecord()
 * overridden to no-op, matching TenantProvisioner's own documented override
 * seam — see its class docblock).
 */
final class SpyTenantProvisioner extends TenantProvisioner
{
    public bool $provisionCalled = false;
    public ?array $lastData = null;

    public function __construct()
    {
        parent::__construct('testplatform', 'tenant', 'http://gateway.invalid', 'admin-token');
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
