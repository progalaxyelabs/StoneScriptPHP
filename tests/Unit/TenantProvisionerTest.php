<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Tenancy\TenantProvisioner;

/**
 * Regression coverage for two framework bugs in TenantProvisioner::createDatabase():
 *
 * BUG #1 (ghost tenants — fixed pre-7.1.3): TenantProvisioner::createDatabase() POSTed a
 * JSON body with key `database_id` to the gateway's POST /admin/database/create. The
 * gateway's Rust CreateDatabaseRequest struct (stonescriptdb-gateway src/api/database.rs)
 * only recognizes `uuid: Option<String>` — it has no `database_id` field. Serde silently
 * drops unrecognized JSON keys (no deny_unknown_fields at the time), so `uuid` always
 * deserialized to None. DatabaseRouter::database_name() with uuid=None returns the shared
 * `{platform}_{schema_name}` base database instead of a distinct per-tenant
 * `{platform}_{schema_name}_{uuid}` database — every tenant collapsed onto one shared DB
 * (or hit 409-already-exists, treated as idempotent success) while being marked
 * db_status='active' with no real per-tenant database backing it ("ghost tenants").
 *
 * BUG #2 (fleet-wide signup 403 — fixed in 7.1.3): gateway v4.1.0+ wraps
 * POST /admin/database/create with `platform_token_middleware`, which requires a
 * per-platform scoped bearer token (ssdb_pt_...) — NOT the shared admin token.
 * TenantProvisioner was still sending $this->adminToken as the Authorization bearer,
 * so the gateway rejected every provision-tenant call with `403 unknown token`
 * (any new-tenant signup on any platform built on the stock base class). Fixed by
 * TenantProvisioner::getPlatformToken(), which
 * mirrors cli/helpers/gateway-common.php's resolveGatewayPlatformToken() /
 * stepProvisionPlatformToken() (the same flow deploy-manager's CLI-driven
 * register-tenant / migrate-all-tenants steps already use successfully): resolve an
 * explicit constructor override (Env::DB_GATEWAY_PLATFORM_TOKEN) first, else
 * auto-provision + cache one via POST /admin/platform-token using the admin token.
 *
 * These tests pin: (1) the outgoing create-database payload uses `uuid`, never
 * `database_id`; (2) 2xx and 409 are both treated as provisioning success; (3) any other
 * status, and gateway unreachability, THROW — so seedData() (called only after
 * createDatabase() returns without throwing) can never run against a tenant whose DB
 * doesn't really exist; (4) POST /admin/database/create is always called with the
 * platform token, never the admin token; (5) the platform token is auto-provisioned via
 * POST /admin/platform-token (using the admin token) when no explicit token is
 * configured, and (6) that auto-provisioned token is cached, not re-fetched, on
 * subsequent calls within the same instance.
 */
final class TenantProvisionerTest extends TestCase
{
    /**
     * Provisioner with an explicit platform token configured (mirrors
     * Env::DB_GATEWAY_PLATFORM_TOKEN being set) — createDatabase() should use it
     * directly with no /admin/platform-token round trip.
     */
    private function provisionerWithExplicitPlatformToken(): TestableTenantProvisioner
    {
        return new TestableTenantProvisioner(
            'myplatform',
            'tenant',
            'http://gateway:9000',
            'test-admin-token',
            '',
            '',
            'ssdb_pt_explicit0000000000000000',
        );
    }

    /**
     * Provisioner with NO explicit platform token — exercises the auto-provisioning
     * path via POST /admin/platform-token.
     */
    private function provisionerWithoutExplicitPlatformToken(): TestableTenantProvisioner
    {
        return new TestableTenantProvisioner(
            'myplatform',
            'tenant',
            'http://gateway:9000',
            'test-admin-token',
        );
    }

    public function test_payload_uses_uuid_field_not_database_id(): void
    {
        $provisioner = $this->provisionerWithExplicitPlatformToken();
        $payload = $provisioner->exposeBuildCreateDatabasePayload([
            'tenant_id' => '518c2d9d-1111-4faa-8ae4-059bdabb5426',
        ]);

        $this->assertSame('myplatform', $payload['platform']);
        $this->assertSame('tenant', $payload['schema_name']);
        $this->assertSame('518c2d9d-1111-4faa-8ae4-059bdabb5426', $payload['uuid']);
        $this->assertArrayNotHasKey(
            'database_id',
            $payload,
            'Regression: payload must never carry database_id — the gateway\'s ' .
            'CreateDatabaseRequest struct does not have that field and silently ' .
            'drops it, defaulting uuid to None (the ghost-tenant root cause).'
        );
    }

    public function test_2xx_response_is_treated_as_success_and_does_not_throw(): void
    {
        $provisioner = $this->provisionerWithExplicitPlatformToken();
        $provisioner->queueResponse(201, '{"status":"created"}', '');

        $provisioner->exposeCreateDatabase([
            'tenant_id'   => 'tenant-uuid-1',
            'tenant_slug' => 'acme',
        ]);

        $this->assertCount(1, $provisioner->capturedRequests);
        $this->assertSame('/admin/database/create', $provisioner->capturedRequests[0]['path']);
        $sentPayload = json_decode($provisioner->capturedRequests[0]['payload'], true);
        $this->assertSame('tenant-uuid-1', $sentPayload['uuid']);
        $this->assertArrayNotHasKey('database_id', $sentPayload);
    }

    public function test_409_already_exists_is_treated_as_idempotent_success(): void
    {
        $provisioner = $this->provisionerWithExplicitPlatformToken();
        $provisioner->queueResponse(409, '{"error":"database already exists"}', '');

        // Must not throw.
        $provisioner->exposeCreateDatabase(['tenant_id' => 'tenant-uuid-retry', 'tenant_slug' => 'acme']);
        $this->addToAssertionCount(1);
    }

    public function test_non_2xx_non_409_response_throws(): void
    {
        $provisioner = $this->provisionerWithExplicitPlatformToken();
        $provisioner->queueResponse(500, '{"error":"internal error"}', '');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 500');
        $provisioner->exposeCreateDatabase(['tenant_id' => 'tenant-uuid-fail', 'tenant_slug' => 'acme']);
    }

    public function test_unreachable_gateway_throws_instead_of_silently_succeeding(): void
    {
        $provisioner = $this->provisionerWithExplicitPlatformToken();
        // curl_exec() returns false on transport failure (DNS, connect refused, timeout).
        $provisioner->queueResponse(0, false, 'Could not resolve host: gateway');

        $this->expectException(\RuntimeException::class);
        $provisioner->exposeCreateDatabase(['tenant_id' => 'tenant-uuid-unreachable', 'tenant_slug' => 'acme']);
    }

    public function test_provision_stops_before_seed_data_when_create_database_fails(): void
    {
        $provisioner = $this->provisionerWithExplicitPlatformToken();
        $provisioner->queueResponse(503, 'Service Unavailable', '');

        try {
            $provisioner->provision(['tenant_id' => 'tenant-uuid-2', 'tenant_slug' => 'acme']);
            $this->fail('Expected RuntimeException to propagate from createDatabase()');
        } catch (\RuntimeException $e) {
            $this->assertFalse($provisioner->seedDataCalled, 'seedData() must never run when createDatabase() throws');
        }
    }

    public function test_create_database_uses_explicit_platform_token_not_admin_token(): void
    {
        $provisioner = $this->provisionerWithExplicitPlatformToken();
        $provisioner->queueResponse(201, '{"status":"created"}', '');

        $provisioner->exposeCreateDatabase(['tenant_id' => 'tenant-uuid-3', 'tenant_slug' => 'acme']);

        $this->assertCount(1, $provisioner->capturedRequests);
        $this->assertSame('/admin/database/create', $provisioner->capturedRequests[0]['path']);
        $this->assertSame(
            'ssdb_pt_explicit0000000000000000',
            $provisioner->capturedRequests[0]['token'],
            'Regression: POST /admin/database/create must be called with the platform ' .
            'token (ssdb_pt_...), never the shared admin token — the gateway\'s ' .
            'platform_token_middleware rejects the admin token with 403 unknown token.'
        );
        $this->assertNotSame('test-admin-token', $provisioner->capturedRequests[0]['token']);
    }

    public function test_no_explicit_token_auto_provisions_platform_token_via_admin_token(): void
    {
        $provisioner = $this->provisionerWithoutExplicitPlatformToken();
        // First call: POST /admin/platform-token (authorized by admin token).
        $provisioner->queueResponse(201, '{"platform":"myplatform","token":"ssdb_pt_autoprovisioned0001","created_at":"2026-07-18T00:00:00Z"}', '');
        // Second call: POST /admin/database/create (authorized by the platform token above).
        $provisioner->queueResponse(201, '{"status":"created"}', '');

        $provisioner->exposeCreateDatabase(['tenant_id' => 'tenant-uuid-4', 'tenant_slug' => 'acme']);

        $this->assertCount(2, $provisioner->capturedRequests);

        $this->assertSame('/admin/platform-token', $provisioner->capturedRequests[0]['path']);
        $this->assertSame(
            'test-admin-token',
            $provisioner->capturedRequests[0]['token'],
            'POST /admin/platform-token must be authorized with the shared admin token.'
        );
        $tokenPayload = json_decode($provisioner->capturedRequests[0]['payload'], true);
        $this->assertSame('myplatform', $tokenPayload['platform']);

        $this->assertSame('/admin/database/create', $provisioner->capturedRequests[1]['path']);
        $this->assertSame(
            'ssdb_pt_autoprovisioned0001',
            $provisioner->capturedRequests[1]['token'],
            'POST /admin/database/create must use the auto-provisioned platform token.'
        );
    }

    public function test_auto_provisioned_platform_token_is_cached_not_refetched(): void
    {
        $provisioner = $this->provisionerWithoutExplicitPlatformToken();
        $provisioner->queueResponse(201, '{"platform":"myplatform","token":"ssdb_pt_cached0001","created_at":"2026-07-18T00:00:00Z"}', '');

        $first = $provisioner->exposeGetPlatformToken();
        $second = $provisioner->exposeGetPlatformToken();

        $this->assertSame('ssdb_pt_cached0001', $first);
        $this->assertSame($first, $second);
        $this->assertCount(
            1,
            $provisioner->capturedRequests,
            'Platform token must be cached after the first resolution — a second ' .
            'call must NOT re-provision (re-provisioning is safe on the gateway side ' .
            'due to its upsert semantics, but an unnecessary round trip per call is wasteful).'
        );
    }

    public function test_platform_token_provisioning_failure_throws(): void
    {
        $provisioner = $this->provisionerWithoutExplicitPlatformToken();
        $provisioner->queueResponse(403, '{"error":"admin token invalid"}', '');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 403');
        $provisioner->exposeGetPlatformToken();
    }

    public function test_platform_token_response_missing_token_field_throws(): void
    {
        $provisioner = $this->provisionerWithoutExplicitPlatformToken();
        $provisioner->queueResponse(201, '{"platform":"myplatform"}', '');

        $this->expectException(\RuntimeException::class);
        $provisioner->exposeGetPlatformToken();
    }

    public function test_platform_token_unreachable_gateway_throws(): void
    {
        $provisioner = $this->provisionerWithoutExplicitPlatformToken();
        $provisioner->queueResponse(0, false, 'Could not resolve host: gateway');

        $this->expectException(\RuntimeException::class);
        $provisioner->exposeGetPlatformToken();
    }
}

/**
 * Test double: stubs the HTTP transport (postToGateway) so createDatabase()'s and
 * getPlatformToken()'s branching logic and the exact outgoing payload/token can be
 * asserted without a live gateway.
 */
final class TestableTenantProvisioner extends TenantProvisioner
{
    /** @var array<int, array{0:int,1:string|false,2:string}> */
    private array $queuedResponses = [];

    /** @var array<int, array{path:string,payload:string,token:?string}> */
    public array $capturedRequests = [];

    public bool $seedDataCalled = false;

    public function queueResponse(int $httpCode, string|false $response, string $curlErr): void
    {
        $this->queuedResponses[] = [$httpCode, $response, $curlErr];
    }

    protected function postToGateway(string $path, string $payload, ?string $bearerToken = null): array
    {
        $this->capturedRequests[] = ['path' => $path, 'payload' => $payload, 'token' => $bearerToken];
        return array_shift($this->queuedResponses) ?? [201, '{}', ''];
    }

    protected function seedData(array $data): void
    {
        $this->seedDataCalled = true;
    }

    public function exposeBuildCreateDatabasePayload(array $data): array
    {
        return $this->buildCreateDatabasePayload($data);
    }

    public function exposeCreateDatabase(array $data): void
    {
        $this->createDatabase($data);
    }

    public function exposeGetPlatformToken(): string
    {
        return $this->getPlatformToken();
    }
}
