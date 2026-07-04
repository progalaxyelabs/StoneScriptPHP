<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Tenancy\TenantProvisioner;

/**
 * Regression coverage for task #3165 — the ghost-tenant framework bug.
 *
 * ROOT CAUSE: TenantProvisioner::createDatabase() POSTed a JSON body with key
 * `database_id` to the gateway's POST /admin/database/create. The gateway's
 * Rust CreateDatabaseRequest struct (stonescriptdb-gateway src/api/database.rs)
 * only recognizes `uuid: Option<String>` — it has no `database_id` field. Serde
 * silently drops unrecognized JSON keys (no deny_unknown_fields at the time),
 * so `uuid` always deserialized to None. DatabaseRouter::database_name() with
 * uuid=None returns the shared `{platform}_{schema_name}` base database instead
 * of a distinct per-tenant `{platform}_{schema_name}_{uuid}` database — every
 * tenant collapsed onto one shared DB (or hit 409-already-exists, treated as
 * idempotent success) while being marked db_status='active' with no real
 * per-tenant database backing it ("ghost tenants"). Every multi-tenant
 * platform using the stock base class inherited this bug; aasaanwork worked
 * around it locally (AasaanworkProvisioner override) before this framework fix.
 *
 * These tests pin: (1) the outgoing payload uses `uuid`, never `database_id`;
 * (2) 2xx and 409 are both treated as provisioning success; (3) any other
 * status, and gateway unreachability, THROW — so seedData() (called only
 * after createDatabase() returns without throwing) can never run against a
 * tenant whose DB doesn't really exist.
 */
final class TenantProvisionerTest extends TestCase
{
    private function provisioner(): TestableTenantProvisioner
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
        $provisioner = $this->provisioner();
        $payload = $provisioner->exposeBuildCreateDatabasePayload([
            'tenant_id' => '518c2d9d-1111-4faa-8ae4-059bdabb5426',
        ]);

        $this->assertSame('myplatform', $payload['platform']);
        $this->assertSame('tenant', $payload['schema_name']);
        $this->assertSame('518c2d9d-1111-4faa-8ae4-059bdabb5426', $payload['uuid']);
        $this->assertArrayNotHasKey(
            'database_id',
            $payload,
            'Regression (#3165): payload must never carry database_id — the gateway\'s ' .
            'CreateDatabaseRequest struct does not have that field and silently ' .
            'drops it, defaulting uuid to None (the ghost-tenant root cause).'
        );
    }

    public function test_2xx_response_is_treated_as_success_and_does_not_throw(): void
    {
        $provisioner = $this->provisioner();
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
        $provisioner = $this->provisioner();
        $provisioner->queueResponse(409, '{"error":"database already exists"}', '');

        // Must not throw.
        $provisioner->exposeCreateDatabase(['tenant_id' => 'tenant-uuid-retry', 'tenant_slug' => 'acme']);
        $this->addToAssertionCount(1);
    }

    public function test_non_2xx_non_409_response_throws(): void
    {
        $provisioner = $this->provisioner();
        $provisioner->queueResponse(500, '{"error":"internal error"}', '');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 500');
        $provisioner->exposeCreateDatabase(['tenant_id' => 'tenant-uuid-fail', 'tenant_slug' => 'acme']);
    }

    public function test_unreachable_gateway_throws_instead_of_silently_succeeding(): void
    {
        $provisioner = $this->provisioner();
        // curl_exec() returns false on transport failure (DNS, connect refused, timeout).
        $provisioner->queueResponse(0, false, 'Could not resolve host: gateway');

        $this->expectException(\RuntimeException::class);
        $provisioner->exposeCreateDatabase(['tenant_id' => 'tenant-uuid-unreachable', 'tenant_slug' => 'acme']);
    }

    public function test_provision_stops_before_seed_data_when_create_database_fails(): void
    {
        $provisioner = $this->provisioner();
        $provisioner->queueResponse(503, 'Service Unavailable', '');

        try {
            $provisioner->provision(['tenant_id' => 'tenant-uuid-2', 'tenant_slug' => 'acme']);
            $this->fail('Expected RuntimeException to propagate from createDatabase()');
        } catch (\RuntimeException $e) {
            $this->assertFalse($provisioner->seedDataCalled, 'seedData() must never run when createDatabase() throws');
        }
    }
}

/**
 * Test double: stubs the HTTP transport (postToGateway) so createDatabase()'s
 * branching logic and the exact outgoing payload can be asserted without a
 * live gateway.
 */
final class TestableTenantProvisioner extends TenantProvisioner
{
    /** @var array<int, array{0:int,1:string|false,2:string}> */
    private array $queuedResponses = [];

    /** @var array<int, array{path:string,payload:string}> */
    public array $capturedRequests = [];

    public bool $seedDataCalled = false;

    public function queueResponse(int $httpCode, string|false $response, string $curlErr): void
    {
        $this->queuedResponses[] = [$httpCode, $response, $curlErr];
    }

    protected function postToGateway(string $path, string $payload): array
    {
        $this->capturedRequests[] = ['path' => $path, 'payload' => $payload];
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
}
