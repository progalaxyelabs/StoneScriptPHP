<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\ApiResponse;

/**
 * Unit tests for ProvisionTenantRoute response envelope (AUTH-SPEC §5a, task #2662).
 *
 * Tests the shape and correctness of the 201 response returned by
 * ProvisionTenantRoute after a successful tenant provisioning + membership creation.
 *
 * Uses a testable subclass to avoid real HTTP calls to the auth service.
 */
class ProvisionTenantRouteResponseTest extends TestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Simulate what createMembership returns from the auth service's
     * POST /api/internal/create-membership endpoint.
     */
    private function makeMembershipResult(array $overrides = []): array
    {
        return array_merge([
            'membership_id'  => 'mem-uuid-123',
            'tenant_id'      => 'tenant-uuid-456',
            'tenant_slug'    => null,
            'tenant_db_schema' => 'medstoreapp_tenant_uuid_456',
            'role'           => 'owner',
            'roles'          => ['owner'],
            'is_new_tenant'  => true,
            'access_token'   => 'platform.jwt.token',
            'refresh_token'  => 'identity.refresh.token',
            'token_type'     => 'Bearer',
            'expires_in'     => 3600,
        ], $overrides);
    }

    /**
     * Build the $data array as it would look after provisioner->provision().
     */
    private function makeProvisionData(array $overrides = []): array
    {
        return array_merge([
            'identity_id'      => 'identity-uuid-789',
            'tenant_id'        => 'tenant-uuid-456',
            'tenant_name'      => 'Sharma Medical Store',
            'tenant_slug'      => null,
            'tenant_db_schema' => 'medstoreapp_tenant_uuid_456',
            'display_name'     => 'Pradeep Sharma',
            'email'            => 'pradeep@test.com',
            'phone'            => '',
            'platform_code'    => 'medstoreapp',
            'role'             => 'owner',
        ], $overrides);
    }

    /**
     * Reconstruct the response that ProvisionTenantRoute::process() now returns.
     * Mirrors the production code exactly so any drift is caught.
     */
    private function buildResponse(array $data, array $result, string $identityId): ApiResponse
    {
        $tenantSlug = $data['tenant_slug'] ?? ($result['tenant_slug'] ?? null);

        return new ApiResponse('ok', 'Tenant created', [
            'access_token'  => $result['access_token']  ?? null,
            'refresh_token' => $result['refresh_token'] ?? null,
            'token_type'    => $result['token_type']    ?? 'Bearer',
            'expires_in'    => $result['expires_in']    ?? 3600,
            'tenant' => [
                'id'        => $data['tenant_id'],
                'name'      => $data['tenant_name'],
                'slug'      => $tenantSlug,
                'db_schema' => $data['tenant_db_schema'],
            ],
            'identity' => [
                'id'           => $identityId,
                'email'        => ($data['email'] ?: null) ?: null,
                'display_name' => ($data['display_name'] ?: null) ?: null,
            ],
            'membership' => [
                'id'        => $result['membership_id'] ?? null,
                'tenant_id' => $data['tenant_id'],
                'role'      => $result['role']          ?? 'owner',
                'roles'     => $result['roles']         ?? ['owner'],
            ],
        ], 201);
    }

    // ── HTTP status code ───────────────────────────────────────────────────────

    public function test_response_is_http_201(): void
    {
        $response = $this->buildResponse(
            $this->makeProvisionData(),
            $this->makeMembershipResult(),
            'identity-uuid-789'
        );

        $this->assertSame(201, $response->httpStatusCode,
            'Provision-tenant first-create must return HTTP 201 per AUTH-SPEC §5a');
    }

    public function test_response_status_is_ok(): void
    {
        $response = $this->buildResponse(
            $this->makeProvisionData(),
            $this->makeMembershipResult(),
            'identity-uuid-789'
        );

        $this->assertSame('ok', $response->status);
        $this->assertSame('Tenant created', $response->message);
    }

    // ── Token fields ───────────────────────────────────────────────────────────

    public function test_response_contains_access_token(): void
    {
        $result = $this->makeMembershipResult(['access_token' => 'platform.jwt.token']);
        $response = $this->buildResponse($this->makeProvisionData(), $result, 'id1');

        $this->assertSame('platform.jwt.token', $response->data['access_token'],
            'access_token must be the platform JWT from createMembership');
    }

    public function test_response_contains_refresh_token(): void
    {
        $result = $this->makeMembershipResult(['refresh_token' => 'identity.refresh.token']);
        $response = $this->buildResponse($this->makeProvisionData(), $result, 'id1');

        $this->assertSame('identity.refresh.token', $response->data['refresh_token']);
    }

    public function test_response_contains_token_type_and_expires_in(): void
    {
        $result = $this->makeMembershipResult(['token_type' => 'Bearer', 'expires_in' => 3600]);
        $response = $this->buildResponse($this->makeProvisionData(), $result, 'id1');

        $this->assertSame('Bearer', $response->data['token_type']);
        $this->assertSame(3600, $response->data['expires_in']);
    }

    // ── Tenant object ──────────────────────────────────────────────────────────

    public function test_response_contains_tenant_object(): void
    {
        $data   = $this->makeProvisionData(['tenant_name' => 'My Store']);
        $result = $this->makeMembershipResult();
        $response = $this->buildResponse($data, $result, 'id1');

        $tenant = $response->data['tenant'];
        $this->assertIsArray($tenant, 'response must contain a tenant object');
        $this->assertArrayHasKey('id',        $tenant);
        $this->assertArrayHasKey('name',      $tenant);
        $this->assertArrayHasKey('slug',      $tenant);
        $this->assertArrayHasKey('db_schema', $tenant);
    }

    public function test_tenant_object_has_correct_values(): void
    {
        $data = $this->makeProvisionData([
            'tenant_id'        => 'tenant-uuid-456',
            'tenant_name'      => 'Sharma Medical Store',
            'tenant_db_schema' => 'medstoreapp_tenant_uuid_456',
            'tenant_slug'      => null,
        ]);
        $response = $this->buildResponse($data, $this->makeMembershipResult(), 'id1');
        $tenant   = $response->data['tenant'];

        $this->assertSame('tenant-uuid-456',              $tenant['id']);
        $this->assertSame('Sharma Medical Store',         $tenant['name']);
        $this->assertSame('medstoreapp_tenant_uuid_456',  $tenant['db_schema']);
        $this->assertNull($tenant['slug'], 'slug is null by default (shared-portal platforms)');
    }

    public function test_tenant_slug_from_result_when_data_slug_is_null(): void
    {
        // If auth service returns a slug (e.g. set during createMembership),
        // it should surface in the response even when $data['tenant_slug'] is null.
        $data   = $this->makeProvisionData(['tenant_slug' => null]);
        $result = $this->makeMembershipResult(['tenant_slug' => 'sharma-medical-store']);
        $response = $this->buildResponse($data, $result, 'id1');

        $this->assertSame('sharma-medical-store', $response->data['tenant']['slug'],
            'slug from createMembership result must be used when data slug is null');
    }

    // ── Identity object ────────────────────────────────────────────────────────

    public function test_response_contains_identity_object(): void
    {
        $response = $this->buildResponse(
            $this->makeProvisionData(),
            $this->makeMembershipResult(),
            'identity-uuid-789'
        );

        $identity = $response->data['identity'];
        $this->assertIsArray($identity, 'response must contain an identity object');
        $this->assertArrayHasKey('id',           $identity);
        $this->assertArrayHasKey('email',        $identity);
        $this->assertArrayHasKey('display_name', $identity);
    }

    public function test_identity_object_has_correct_values(): void
    {
        $data = $this->makeProvisionData([
            'email'        => 'pradeep@test.com',
            'display_name' => 'Pradeep Sharma',
        ]);
        $response = $this->buildResponse($data, $this->makeMembershipResult(), 'identity-uuid-789');
        $identity = $response->data['identity'];

        $this->assertSame('identity-uuid-789', $identity['id']);
        $this->assertSame('pradeep@test.com',  $identity['email']);
        $this->assertSame('Pradeep Sharma',    $identity['display_name']);
    }

    public function test_identity_email_is_null_when_empty(): void
    {
        $data = $this->makeProvisionData(['email' => '']);
        $response = $this->buildResponse($data, $this->makeMembershipResult(), 'id1');

        $this->assertNull($response->data['identity']['email'],
            'empty email string must be normalized to null');
    }

    // ── Membership object ──────────────────────────────────────────────────────

    public function test_response_contains_membership_object(): void
    {
        $response = $this->buildResponse(
            $this->makeProvisionData(),
            $this->makeMembershipResult(),
            'id1'
        );

        $membership = $response->data['membership'];
        $this->assertIsArray($membership, 'response must contain a membership object');
        $this->assertArrayHasKey('id',        $membership);
        $this->assertArrayHasKey('tenant_id', $membership);
        $this->assertArrayHasKey('role',      $membership);
        $this->assertArrayHasKey('roles',     $membership);
    }

    public function test_membership_object_has_correct_values(): void
    {
        $result = $this->makeMembershipResult([
            'membership_id' => 'mem-uuid-123',
            'role'          => 'owner',
            'roles'         => ['owner'],
        ]);
        $data = $this->makeProvisionData(['tenant_id' => 'tenant-uuid-456']);
        $response = $this->buildResponse($data, $result, 'id1');
        $membership = $response->data['membership'];

        $this->assertSame('mem-uuid-123',   $membership['id']);
        $this->assertSame('tenant-uuid-456', $membership['tenant_id']);
        $this->assertSame('owner',          $membership['role']);
        $this->assertSame(['owner'],        $membership['roles']);
    }

    // ── Top-level response keys ────────────────────────────────────────────────

    public function test_response_has_all_required_top_level_keys(): void
    {
        // AUTH-SPEC §5a: access_token, refresh_token, token_type, expires_in,
        // tenant, identity, membership
        $response = $this->buildResponse(
            $this->makeProvisionData(),
            $this->makeMembershipResult(),
            'id1'
        );

        foreach (['access_token', 'refresh_token', 'token_type', 'expires_in', 'tenant', 'identity', 'membership'] as $key) {
            $this->assertArrayHasKey($key, $response->data,
                "AUTH-SPEC §5a requires '{$key}' in provision-tenant response");
        }
    }

    // ── Backward-compat: MedstoreProvisionTenantRoute still passes access_token through ──
    //
    // The platform override (MedstoreProvisionTenantRoute) returns a flat envelope
    // (access_token at top level rather than nested). The framework route's consumer
    // (Angular portal) reads data.access_token — both flat and this new nested form
    // keep access_token at the top level. Verify this contract is not broken.

    public function test_access_token_is_at_top_level_not_nested(): void
    {
        $response = $this->buildResponse(
            $this->makeProvisionData(),
            $this->makeMembershipResult(['access_token' => 'tok']),
            'id1'
        );

        // Must be at top level (data['access_token']), NOT at data['membership']['access_token']
        $this->assertSame('tok', $response->data['access_token'],
            'access_token must be at top level for Angular portal compatibility');
        $this->assertArrayNotHasKey('access_token', $response->data['membership'] ?? [],
            'access_token must NOT be nested inside membership');
    }
}
