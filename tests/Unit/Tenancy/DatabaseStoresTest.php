<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit\Tenancy;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Tenancy\Platform\DatabasePlatformRowStore;
use StoneScriptPHP\Tenancy\Provisioning\DatabaseMainTenantStore;
use StoneScriptPHP\Tenancy\Provisioning\DatabaseTenantDbSeeder;

/**
 * The SQL-function boundary: which function is called, with which positional arguments (the gateway
 * passes them by position), and how rows are read back.
 *
 * @covers \StoneScriptPHP\Tenancy\Provisioning\DatabaseMainTenantStore
 * @covers \StoneScriptPHP\Tenancy\Provisioning\DatabaseTenantDbSeeder
 * @covers \StoneScriptPHP\Tenancy\Platform\DatabasePlatformRowStore
 */
final class DatabaseStoresTest extends TestCase
{
    private const TENANT = '22222222-2222-4222-8222-222222222222';
    private const MEMBERSHIP = '33333333-3333-4333-8333-333333333333';
    private const IDENTITY = '11111111-1111-4111-8111-111111111111';

    /** @var list<array{string,array}> */
    private array $calls = [];

    /** @param array<string,array> $rowsByFn */
    private function fn(array $rowsByFn = []): callable
    {
        return function (string $name, array $params) use ($rowsByFn): array {
            $this->calls[] = [$name, $params];
            return $rowsByFn[$name] ?? [];
        };
    }

    public function test_main_store_calls_the_generated_functions_with_positional_arguments(): void
    {
        $store = new DatabaseMainTenantStore($this->fn(['tenancy_get_tenant_status' => [['o_status' => 'provisioning']], 'tenancy_create_membership' => [['o_id' => self::MEMBERSHIP]]]));

        $this->assertSame('provisioning', $store->getTenantStatus(self::TENANT));
        $store->upsertTenant(self::TENANT, 'Acme', null, 'acme_tenant_x', self::IDENTITY);
        $store->setTenantStatus(self::TENANT, 'provisioned');
        $store->createMembership(self::MEMBERSHIP, self::TENANT, self::IDENTITY, true, null);

        $this->assertSame([
            ['tenancy_get_tenant_status', [self::TENANT]],
            ['tenancy_upsert_tenant', [self::TENANT, 'Acme', null, 'acme_tenant_x', self::IDENTITY]],
            ['tenancy_set_tenant_status', [self::TENANT, 'provisioned']],
            ['tenancy_create_membership', [self::MEMBERSHIP, self::TENANT, self::IDENTITY, true, null]],
        ], $this->calls);
    }

    public function test_unknown_tenant_has_no_status_and_unknown_status_values_are_refused(): void
    {
        $store = new DatabaseMainTenantStore($this->fn());

        $this->assertNull($store->getTenantStatus(self::TENANT));
        foreach (['active', 'provisioning-failed', 'trial', 'expired', ''] as $bad) {
            try {
                $store->setTenantStatus(self::TENANT, $bad);
                $this->fail("status '$bad' is not in the vocabulary");
            } catch (\InvalidArgumentException) {
            }
        }
        $this->assertSame([['tenancy_get_tenant_status', [self::TENANT]]], $this->calls, 'a refused status never reaches the database');
    }

    public function test_main_store_refuses_a_membership_id_that_differs_from_the_stored_one(): void
    {
        $store = new DatabaseMainTenantStore($this->fn(['tenancy_create_membership' => [['o_id' => '99999999-9999-4999-8999-999999999999']]]));

        $this->expectException(\RuntimeException::class);
        $store->createMembership(self::MEMBERSHIP, self::TENANT, self::IDENTITY, false, null);
    }

    public function test_list_maps_rows_and_a_blank_slug_is_null(): void
    {
        $store = new DatabaseMainTenantStore($this->fn(['tenancy_get_identity_memberships' => [[
            'o_membership_id' => self::MEMBERSHIP, 'o_tenant_id' => self::TENANT, 'o_tenant_name' => 'Acme',
            'o_tenant_slug' => '', 'o_tenant_db_name' => 'acme_tenant_x', 'o_is_tenant_owner' => 't',
        ]]]));

        $rows = $store->listIdentityMemberships(self::IDENTITY);

        $this->assertNull($rows[0]['tenant_slug']);
        $this->assertTrue($rows[0]['is_tenant_owner']);
        $this->assertSame('acme_tenant_x', $rows[0]['tenant_db_name']);
    }

    public function test_request_ledger_round_trip_arguments(): void
    {
        $row = ['o_identity_id' => self::IDENTITY, 'o_tenant_id' => self::TENANT, 'o_membership_id' => self::MEMBERSHIP, 'o_tenant_db_name' => 'x'];
        $store = new DatabaseMainTenantStore($this->fn(['tenancy_find_request' => [$row], 'tenancy_save_request' => [$row]]));

        $winner = $store->save('k', self::IDENTITY, self::TENANT, self::MEMBERSHIP, 'x');
        $found = $store->find('k');

        $this->assertSame(self::TENANT, $found['tenant_id']);
        $this->assertSame(self::MEMBERSHIP, $winner['membership_id'], 'save returns the winning row');
        $this->assertSame(['tenancy_save_request', ['k', self::IDENTITY, self::TENANT, self::MEMBERSHIP, 'x']], $this->calls[0]);
        $this->assertNull((new DatabaseMainTenantStore($this->fn()))->find('missing'));
    }

    public function test_save_that_returns_no_row_is_an_error(): void
    {
        $this->expectException(\RuntimeException::class);
        (new DatabaseMainTenantStore($this->fn()))->save('k', self::IDENTITY, self::TENANT, self::MEMBERSHIP, 'x');
    }

    public function test_mirror_and_drift_calls_use_the_functions_without_the_owner_rule(): void
    {
        $store = new DatabaseMainTenantStore($this->fn(['tenancy_mirror_membership_status' => [['o_updated' => 1]]]));

        $store->mirrorMembershipStatus(self::MEMBERSHIP, 'removed');
        $store->recordMirrorDrift(self::MEMBERSHIP, 'removed');
        $store->clearMirrorDrift(self::MEMBERSHIP);

        $this->assertSame([
            ['tenancy_mirror_membership_status', [self::MEMBERSHIP, 'removed']],
            ['tenancy_record_mirror_drift', [self::MEMBERSHIP, 'removed']],
            ['tenancy_clear_mirror_drift', [self::MEMBERSHIP]],
        ], $this->calls);
    }

    public function test_a_mirror_that_updated_no_row_and_an_unknown_status_are_errors(): void
    {
        $none = new DatabaseMainTenantStore($this->fn(['tenancy_mirror_membership_status' => [['o_updated' => 0]]]));
        try {
            $none->mirrorMembershipStatus(self::MEMBERSHIP, 'removed');
            $this->fail('no row updated must be an error (it becomes drift)');
        } catch (\RuntimeException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        $none->mirrorMembershipStatus(self::MEMBERSHIP, 'deleted');
    }

    public function test_membership_lookup_and_status_change_call_the_generated_functions(): void
    {
        $store = new DatabaseMainTenantStore($this->fn(['tenancy_find_membership' => [['o_id' => self::MEMBERSHIP, 'o_status' => 'suspended']]]));

        $this->assertSame(['id' => self::MEMBERSHIP, 'status' => 'suspended'], $store->findMembership(self::TENANT, self::IDENTITY));
        $store->setMembershipStatus(self::MEMBERSHIP, 'removed');
        $this->assertNull((new DatabaseMainTenantStore($this->fn()))->findMembership(self::TENANT, self::IDENTITY));

        $this->assertSame(['tenancy_set_membership_status', [self::MEMBERSHIP, 'removed']], $this->calls[1]);
        $this->expectException(\InvalidArgumentException::class);
        $store->setMembershipStatus(self::MEMBERSHIP, 'deleted');
    }

    public function test_seeder_routes_to_the_tenant_database_and_always_restores_the_routing(): void
    {
        $routes = [];
        $route = function (?string $id) use (&$routes): ?string {
            $routes[] = $id;
            return 'previous-tenant';
        };
        $user = '77777777-7777-4777-8777-777777777777';
        $seeder = new DatabaseTenantDbSeeder($this->fn(['tenant_user_upsert' => [['o_id' => $user]]]), $route);

        $seeder->ensureUserWithRole(self::TENANT, self::IDENTITY, 'Owner', 'owner', null);

        $this->assertSame([self::TENANT, 'previous-tenant'], $routes);
        $this->assertSame(['tenant_user_upsert', [self::IDENTITY, 'Owner']], $this->calls[0]);
        $this->assertSame(['tenant_user_assign_role', [$user, 'owner', null]], $this->calls[1]);
    }

    public function test_seeder_restores_the_routing_when_a_call_fails(): void
    {
        $routes = [];
        $route = function (?string $id) use (&$routes): ?string {
            $routes[] = $id;
            return null;
        };
        $seeder = new DatabaseTenantDbSeeder(fn (): array => [], $route);

        try {
            $seeder->ensureUserWithRole(self::TENANT, self::IDENTITY, null, 'owner', null);
            $this->fail('no user id came back, so this must fail');
        } catch (\RuntimeException) {
        }

        $this->assertSame([self::TENANT, null], $routes, 'routing is restored even on failure');
    }

    public function test_seeder_records_who_assigned_the_role_for_an_invited_member(): void
    {
        $assigner = '88888888-8888-4888-8888-888888888888';
        $user = '77777777-7777-4777-8777-777777777777';
        $seeder = new DatabaseTenantDbSeeder($this->fn(['tenant_user_upsert' => [['o_id' => $assigner]]]), fn (?string $id): ?string => null);

        $seeder->ensureUserWithRole(self::TENANT, self::IDENTITY, null, 'cashier', '55555555-5555-4555-8555-555555555555');

        $this->assertSame('tenant_user_assign_role', $this->calls[2][0]);
        $this->assertSame([$assigner, 'cashier', $assigner], $this->calls[2][1], 'upsert returns the same id for both calls in this fake');
    }

    public function test_platform_row_store_calls_platform_upsert(): void
    {
        (new DatabasePlatformRowStore($this->fn()))->register(self::TENANT, 'Acme', 'acme', 'external');

        $this->assertSame([['platform_upsert', [self::TENANT, 'Acme', 'acme', 'external']]], $this->calls);
    }
}
