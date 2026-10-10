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
 * BUG #2 (platform-wide signup 403 — fixed in 7.1.3): gateway v4.1.0+ wraps
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

    /** @return array<string, array{0:int,1:string,2:bool}> */
    public static function gatewayCodeMatrix(): array
    {
        return [
            '409 database_already_exists -> success'        => [409, '{"error":"database_already_exists"}', true],
            '409 database_exists_unmarked -> FAIL (never released: unknown 409 code fails closed)' => [409, '{"error":"database_exists_unmarked"}', false],
            '409 database_exists_legacy -> legacy success'   => [409, '{"error":"database_exists_legacy"}', true],
            '409 older gateway no body -> legacy success'    => [409, '', true],
            '409 older gateway non-json -> legacy success'   => [409, 'Conflict', true],
            '409 older gateway prose -> legacy success'      => [409, '{"error":"database already exists"}', true],
            '409 name collision -> FAIL'                     => [409, '{"error":"database_name_collision"}', false],
            '422 name collision -> FAIL'                     => [422, '{"error":"database_name_collision"}', false],
            '409 unknown code -> fail closed'                => [409, '{"error":"something_new"}', false],
            '500 database_incomplete_unmarked -> FAIL'       => [500, '{"error":"database_incomplete_unmarked"}', false],
            '500 other -> FAIL'                              => [500, '{"error":"query_failed"}', false],
            '503 -> FAIL'                                    => [503, '', false],
            '201 -> success'                                 => [201, '{"status":"created"}', true],
        ];
    }

    /** @dataProvider gatewayCodeMatrix */
    #[\PHPUnit\Framework\Attributes\DataProvider('gatewayCodeMatrix')]
    public function test_gateway_error_code_matrix(int $http, string $body, bool $succeeds): void
    {
        $provisioner = $this->provisionerWithExplicitPlatformToken();
        $provisioner->queueResponse($http, $body, '');
        if (!$succeeds) {
            $this->expectException(\RuntimeException::class);
        }
        $provisioner->exposeCreateDatabase(['tenant_id' => 'tenant-uuid-x', 'tenant_slug' => 'acme']);
        $this->addToAssertionCount(1);
    }

    public function test_failed_provisioning_logs_status_and_code_but_never_the_response_body(): void
    {
        $dir = sys_get_temp_dir() . '/ssp-prov-' . bin2hex(random_bytes(4));
        mkdir($dir);
        \StoneScriptPHP\Logger::get_instance()->configure(false, true, false, $dir);
        try {
            $provisioner = $this->provisionerWithExplicitPlatformToken();
            $provisioner->queueResponse(500, '{"error":"query_failed","detail":"Key (email)=(victim@example.com) SECRETBODY"}', '');
            try {
                $provisioner->exposeCreateDatabase(['tenant_id' => 'tenant-uuid-x', 'tenant_slug' => 'acme']);
                $this->fail('must throw');
            } catch (\RuntimeException $e) {
                $this->assertStringNotContainsString('SECRETBODY', $e->getMessage());
            }
            $log = '';
            foreach (glob($dir . '/*') ?: [] as $f) {
                $log .= file_get_contents($f);
                unlink($f);
            }
            $this->assertStringContainsString('HTTP 500 query_failed', $log);
            $this->assertStringNotContainsString('SECRETBODY', $log);
            $this->assertStringNotContainsString('victim@example.com', $log);
        } finally {
            \StoneScriptPHP\Logger::get_instance()->configure(false, true, false, null);
            rmdir($dir);
        }
    }

    public function test_name_collision_never_returns_so_seed_data_cannot_run(): void
    {
        $provisioner = $this->provisionerWithExplicitPlatformToken();
        $provisioner->queueResponse(409, '{"error":"database_name_collision"}', '');
        try {
            $provisioner->exposeCreateDatabase(['tenant_id' => 'abc-1', 'tenant_slug' => 'a']);
            $this->fail('must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('collides', $e->getMessage());
        }
    }

    /** @return array<string, array{0:string,1:bool}> */
    public static function tenantIds(): array
    {
        return [
            'canonical uuid'            => ['3f2504e0-4f89-41d3-9a0c-0305e82c3301', true],
            'plain lower'               => ['clinic001', true],
            'hyphenated'                => ['clinic-001', true],
            'underscore rejected'       => ['clinic_001', false],
            'a-b / a_b collision pair'  => ['a_b', false],
            'upper case collides'       => ['Clinic001', false],
            'special chars'             => ['a.b', false],
            'space'                     => ['a b', false],
            'leading underscore'        => ['_a', false],
            'trailing hyphen'           => ['a-', false],
            'double hyphen'             => ['a--b', false],
            'trailing newline'          => ["abc\n", false],
            'leading newline'           => ["\nabc", false],
            'empty'                     => ['', false],
            'long id -> db name > 63'   => [str_repeat('a', 60), false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tenantIds')]
    public function test_unsafe_tenant_ids_are_rejected_before_the_gateway_is_called(string $id, bool $ok): void
    {
        $provisioner = $this->provisionerWithExplicitPlatformToken();
        $provisioner->queueResponse(201, '{}', '');
        if (!$ok) {
            $this->expectException(\RuntimeException::class);
        }
        try {
            $provisioner->exposeCreateDatabase(['tenant_id' => $id, 'tenant_slug' => 's']);
        } finally {
            if (!$ok) {
                $this->assertSame([], $provisioner->capturedRequests, 'gateway must not be called for an unsafe id');
            }
        }
        $this->addToAssertionCount(1);
    }

    public function test_sanitised_collision_pair_cannot_both_pass(): void
    {
        $passing = array_filter(['a-b', 'a_b'], fn (string $id) => \StoneScriptPHP\Tenancy\TenantIdRule::violation($id, 'myplatform', 'tenant') === null);
        $this->assertSame(['a-b'], array_values($passing));
    }

    public function test_db_name_length_boundary_is_63_bytes(): void
    {
        // myplatform_tenant_ = 18 bytes -> 45 chars of id fit exactly
        $this->assertNull(\StoneScriptPHP\Tenancy\TenantIdRule::violation(str_repeat('a', 45), 'myplatform', 'tenant'));
        $this->assertNotNull(\StoneScriptPHP\Tenancy\TenantIdRule::violation(str_repeat('a', 46), 'myplatform', 'tenant'));
    }

    public function test_already_exists_log_is_neutral_unless_gateway_version_is_known(): void
    {
        foreach ([[null, false], ['4.6.7', false], ['4.7.0', true], ['v4.8.1', true]] as [$version, $verifies]) {
            $p = $this->provisionerWithExplicitPlatformToken();
            $p->version = $version;
            $p->queueResponse(409, '{"error":"database_already_exists"}', '');
            $p->exposeCreateDatabase(['tenant_id' => 'tenant-1', 'tenant_slug' => 's']);
            $this->assertSame($verifies, $p->exposeGatewayVerifies(), (string) $version);
        }
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

    /** Gateway version the stub reports (null = unknown). */
    public ?string $version = null;

    protected function gatewayVersion(): ?string
    {
        return $this->version;
    }

    public function exposeGatewayVerifies(): bool
    {
        $m = new \ReflectionMethod(TenantProvisioner::class, 'gatewayVerifiesIdentity');
        return $m->invoke($this);
    }

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
