<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit\Tenancy;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Tenancy\Provisioning\AuthTenancyClient;
use StoneScriptPHP\Tenancy\Provisioning\BuiltinMembershipAuthority;
use StoneScriptPHP\Tenancy\Provisioning\ExternalMembershipAuthority;
use StoneScriptPHP\Tenancy\Provisioning\MainTenantStore;
use StoneScriptPHP\Tenancy\Provisioning\ProvisioningException;
use StoneScriptPHP\Tenancy\Provisioning\TenantStaffInvite;

require_once __DIR__ . '/TenancyFakes.php';

/**
 * Both MembershipAuthority implementations: External (auth over a fake transport) and Builtin (local
 * main database over in-memory stores), plus the staff invite that runs on top of either.
 *
 * @covers \StoneScriptPHP\Tenancy\Provisioning\ExternalMembershipAuthority
 * @covers \StoneScriptPHP\Tenancy\Provisioning\AuthTenancyClient
 * @covers \StoneScriptPHP\Tenancy\Provisioning\BuiltinMembershipAuthority
 * @covers \StoneScriptPHP\Tenancy\Provisioning\TenantStaffInvite
 */
final class MembershipAuthorityTest extends TestCase
{
    private const TENANT = '22222222-2222-4222-8222-222222222222';
    private const MEMBERSHIP = '33333333-3333-4333-8333-333333333333';
    private const IDENTITY = '11111111-1111-4111-8111-111111111111';
    private const SECRET = 'platform-secret-value';
    // (builtin tests below build their own authority)
    private const USER_TOKEN = 'user.jwt.token';

    /** @var list<array{string,string,list<string>,?string}> */
    private array $requests = [];

    /** @param array{0:int,1:string} $response */
    private function external(array $response): ExternalMembershipAuthority
    {
        $transport = function (string $method, string $url, array $headers, ?string $body) use ($response): array {
            $this->requests[] = [$method, $url, $headers, $body];
            return $response;
        };
        return new ExternalMembershipAuthority(new AuthTenancyClient('https://auth.example.test/', 'client-1', self::SECRET, $transport));
    }

    // ---- External ----

    public function test_external_reserve_posts_to_tenants_with_credential_and_user_token(): void
    {
        $authority = $this->external([201, json_encode(['platform_tenant_id' => self::TENANT, 'tenant_db_name' => 'acme_tenant_x', 'platform_tenant_membership_id' => self::MEMBERSHIP])]);

        $r = $authority->reserveTenant('Acme', 'idem-1', self::IDENTITY, self::USER_TOKEN);

        [$method, $url, $headers, $body] = $this->requests[0];
        $this->assertSame('POST', $method);
        $this->assertSame('https://auth.example.test/api/internal/tenants', $url);
        $this->assertContains('X-Platform-Client-Id: client-1', $headers);
        $this->assertContains('X-Platform-Client-Secret: ' . self::SECRET, $headers);
        $this->assertContains('Authorization: Bearer ' . self::USER_TOKEN, $headers);
        $this->assertSame(['tenant_name' => 'Acme', 'idempotency_key' => 'idem-1'], json_decode((string) $body, true));
        $this->assertSame(self::TENANT, $r->platformTenantId);
        $this->assertSame('acme_tenant_x', $r->tenantDbName);
        $this->assertSame(self::MEMBERSHIP, $r->membershipId);
    }

    public function test_external_accepts_a_response_wrapped_in_data(): void
    {
        $authority = $this->external([200, json_encode(['data' => ['platform_tenant_id' => self::TENANT, 'tenant_db_name' => 'n', 'platform_tenant_membership_id' => self::MEMBERSHIP]])]);

        $this->assertSame(self::TENANT, $authority->reserveTenant('Acme', 'k', self::IDENTITY, 't')->platformTenantId);
    }

    public function test_external_reserve_without_a_user_token_is_refused_before_any_request(): void
    {
        $authority = $this->external([200, '{}']);

        $this->expectException(ProvisioningException::class);
        try {
            $authority->reserveTenant('Acme', 'k', self::IDENTITY, null);
        } finally {
            $this->assertSame([], $this->requests);
        }
    }

    public function test_external_incomplete_reservation_is_refused(): void
    {
        $authority = $this->external([200, json_encode(['platform_tenant_id' => 'not-a-uuid', 'tenant_db_name' => 'x', 'platform_tenant_membership_id' => self::MEMBERSHIP])]);

        try {
            $authority->reserveTenant('Acme', 'k', self::IDENTITY, 't');
            $this->fail('expected a ProvisioningException');
        } catch (ProvisioningException $e) {
            $this->assertSame('reserve_tenant', $e->step);
        }
    }

    public function test_external_error_and_transport_failures_carry_no_secret_or_token(): void
    {
        foreach ([[403, json_encode(['error' => 'wrong_platform', 'detail' => self::SECRET])], [500, '<html>' . self::SECRET . '</html>']] as $response) {
            $authority = $this->external($response);
            try {
                $authority->reserveTenant('Acme', 'k', self::IDENTITY, self::USER_TOKEN);
                $this->fail('expected a ProvisioningException');
            } catch (ProvisioningException $e) {
                $this->assertStringNotContainsString(self::SECRET, $e->getMessage());
                $this->assertStringNotContainsString(self::USER_TOKEN, $e->getMessage());
                $this->assertStringContainsString('HTTP ' . $response[0], $e->getMessage());
            }
        }

        $throwing = new ExternalMembershipAuthority(new AuthTenancyClient('https://auth.example.test', 'c', self::SECRET, function (): array {
            throw new \RuntimeException('connect failed to https://auth.example.test with ' . self::SECRET);
        }));
        try {
            $throwing->markProvisioned(self::TENANT);
            $this->fail('expected a ProvisioningException');
        } catch (ProvisioningException $e) {
            $this->assertStringNotContainsString(self::SECRET, $e->getMessage());
        }
    }

    public function test_external_provisioned_and_failed_and_add_member_paths(): void
    {
        $authority = $this->external([200, json_encode(['platform_tenant_membership_id' => self::MEMBERSHIP, 'status' => 'active'])]);

        $authority->markProvisioned(self::TENANT);
        $authority->markProvisioningFailed(self::TENANT, 'step:create_database');
        $id = $authority->addMember(self::TENANT, self::IDENTITY, 'invite-1');

        $this->assertSame('https://auth.example.test/api/internal/tenants/' . self::TENANT . '/provisioned', $this->requests[0][1]);
        $this->assertSame('https://auth.example.test/api/internal/tenants/' . self::TENANT . '/provisioning-failed', $this->requests[1][1]);
        $this->assertNull($this->requests[1][3], 'the auth endpoint takes no body');
        $this->assertSame('https://auth.example.test/api/internal/tenants/' . self::TENANT . '/memberships', $this->requests[2][1]);
        $this->assertSame(['identity_id' => self::IDENTITY, 'idempotency_key' => 'invite-1'], json_decode((string) $this->requests[2][3], true));
        $this->assertSame(self::MEMBERSHIP, $id);
    }

    public function test_external_re_invite_of_a_suspended_or_removed_member_is_refused_with_the_status(): void
    {
        foreach (['suspended', 'removed'] as $status) {
            $authority = $this->external([200, json_encode(['platform_tenant_membership_id' => self::MEMBERSHIP, 'status' => $status, 'is_new_membership' => false, 'idempotent_replay' => true])]);
            try {
                $authority->addMember(self::TENANT, self::IDENTITY, 'again');
                $this->fail("a $status member must not be treated as added");
            } catch (ProvisioningException $e) {
                $this->assertSame('add_member', $e->step);
                $this->assertStringContainsString($status, $e->getMessage());
            }
        }
    }

    public function test_external_set_membership_status_matches_the_auth_contract(): void
    {
        $authority = $this->external([200, json_encode(['id' => self::MEMBERSHIP, 'status' => 'suspended', 'updated_at' => '2026-11-21T00:00:00Z'])]);

        $authority->setMembershipStatus(self::MEMBERSHIP, 'suspended');

        [$method, $url, $headers, $body] = $this->requests[0];
        $this->assertSame('PUT', $method);
        $this->assertSame('https://auth.example.test/api/internal/memberships/' . self::MEMBERSHIP . '/status', $url);
        $this->assertContains('X-Platform-Client-Id: client-1', $headers);
        $this->assertSame(['status' => 'suspended'], json_decode((string) $body, true));

        foreach (['deleted', '', 'Active'] as $bad) {
            try {
                $authority->setMembershipStatus(self::MEMBERSHIP, $bad);
                $this->fail("status '$bad' must be refused locally");
            } catch (ProvisioningException) {
            }
        }
        $this->assertCount(1, $this->requests);
    }

    public function test_external_status_change_is_mirrored_into_the_platform_row_after_auth_confirms(): void
    {
        $log = new CallLog();
        $main = new FakeMainStore($log);
        $client = new AuthTenancyClient('https://auth.example.test', 'client-1', self::SECRET, function (string $m, string $u) use ($log): array {
            $log->add('auth.' . $m);
            return [200, json_encode(['id' => self::MEMBERSHIP, 'status' => 'suspended'])];
        });
        $authority = new ExternalMembershipAuthority($client, $main);

        $authority->setMembershipStatus(self::MEMBERSHIP, 'suspended');

        $this->assertSame(['auth.PUT', 'main.mirror:suspended', 'main.driftCleared'], $log->calls, 'auth first, then the mirror WITHOUT the local last-owner rule, then any old drift is cleared');
        $this->assertSame('suspended', $main->membershipStatus[self::MEMBERSHIP]);
    }

    public function test_external_auth_refusal_leaves_the_platform_row_untouched(): void
    {
        $log = new CallLog();
        $main = new FakeMainStore($log);
        $refusing = new ExternalMembershipAuthority(new AuthTenancyClient('https://auth.example.test', 'c', self::SECRET, fn (): array => [409, json_encode(['error' => 'cannot_suspend_tenant_owner'])]), $main);
        try {
            $refusing->setMembershipStatus(self::MEMBERSHIP, 'suspended');
            $this->fail('expected a ProvisioningException');
        } catch (ProvisioningException) {
        }
        $this->assertSame([], $log->calls, 'auth refused: the platform row is not changed');
    }

    public function test_external_mirror_never_applies_the_local_last_owner_rule(): void
    {
        $log = new CallLog();
        $main = new FakeMainStore($log);
        // The local rule would throw (setMembershipStatus), the mirror must not use it.
        $main->failStatusChange = true;
        $authority = new ExternalMembershipAuthority(new AuthTenancyClient('https://auth.example.test', 'c', self::SECRET, fn (): array => [200, json_encode(['id' => self::MEMBERSHIP, 'status' => 'suspended'])]), $main);

        $authority->setMembershipStatus(self::MEMBERSHIP, 'suspended');

        $this->assertSame('suspended', $main->membershipStatus[self::MEMBERSHIP], 'mirrored although the local rule would have refused');
        $this->assertSame([], $main->drift);
    }

    public function test_a_failed_mirror_returns_success_and_records_drift_with_ids_only(): void
    {
        $log = new CallLog();
        $main = new FakeMainStore($log);
        $main->failMirror = true;
        $authority = new ExternalMembershipAuthority(new AuthTenancyClient('https://auth.example.test', 'c', self::SECRET, fn (): array => [200, json_encode(['id' => self::MEMBERSHIP, 'status' => 'removed'])]), $main);

        $authority->setMembershipStatus(self::MEMBERSHIP, 'removed'); // no exception: auth is the source of truth

        $this->assertSame([self::MEMBERSHIP => 'removed'], $main->drift, 'the drift is recorded for tenancy:preflight');
    }

    public function test_a_mirror_failure_is_survived_even_when_the_drift_record_also_fails(): void
    {
        $main = new FakeMainStore(new CallLog());
        $main->failMirror = true;
        $main->failDriftRecord = true;
        $authority = new ExternalMembershipAuthority(new AuthTenancyClient('https://auth.example.test', 'c', self::SECRET, fn (): array => [200, json_encode(['id' => self::MEMBERSHIP, 'status' => 'removed'])]), $main);

        $authority->setMembershipStatus(self::MEMBERSHIP, 'removed');

        $this->assertSame([], $main->drift);
    }

    public function test_external_last_owner_refusal_is_reported_with_its_code(): void
    {
        $authority = $this->external([409, json_encode(['error' => 'cannot_suspend_tenant_owner'])]);

        try {
            $authority->setMembershipStatus(self::MEMBERSHIP, 'suspended');
            $this->fail('expected a ProvisioningException');
        } catch (ProvisioningException $e) {
            $this->assertStringContainsString('409 cannot_suspend_tenant_owner', $e->getMessage());
        }
    }

    public function test_external_reservation_passes_the_idempotent_replay_flag_through(): void
    {
        $fresh = $this->external([201, json_encode(['platform_tenant_id' => self::TENANT, 'tenant_db_name' => 'n', 'platform_tenant_membership_id' => self::MEMBERSHIP, 'is_new_tenant' => true, 'idempotent_replay' => false])]);
        $replay = $this->external([200, json_encode(['platform_tenant_id' => self::TENANT, 'tenant_db_name' => 'n', 'platform_tenant_membership_id' => self::MEMBERSHIP, 'is_new_tenant' => false, 'idempotent_replay' => true])]);

        $this->assertFalse($fresh->reserveTenant('A', 'k', self::IDENTITY, 't')->replay);
        $this->assertTrue($replay->reserveTenant('A', 'k', self::IDENTITY, 't')->replay);
    }

    public function test_builtin_re_invite_of_a_non_active_member_is_refused_and_an_active_one_keeps_its_id(): void
    {
        $store = null;
        $authority = $this->builtin($store);
        $r = $authority->reserveTenant('Acme', 'k1', self::IDENTITY, null);
        $store->upsertTenant($r->platformTenantId, 'Acme', null, $r->tenantDbName, self::IDENTITY);
        $store->setTenantStatus($r->platformTenantId, MainTenantStore::STATUS_PROVISIONED);
        $member = '44444444-4444-4444-8444-444444444444';
        $existingId = '55555555-5555-4555-8555-555555555555';
        $store->createMembership($existingId, $r->platformTenantId, $member, false, null);

        $this->assertSame($existingId, $authority->addMember($r->platformTenantId, $member, 'invite-a'), 'an active member keeps its membership id');

        $store->membershipStatus[$existingId] = 'suspended';
        $this->expectException(ProvisioningException::class);
        $authority->addMember($r->platformTenantId, $member, 'invite-b');
    }

    public function test_builtin_two_first_calls_on_one_key_both_end_with_the_winning_ids(): void
    {
        $store = null;
        $authority = $this->builtin($store);
        // The other caller wrote the key between our find() and save(): the store keeps its row and returns it.
        $store->requests['k-race'] = ['identity_id' => self::IDENTITY, 'tenant_id' => '66666666-6666-4666-8666-666666666666', 'membership_id' => '77777777-7777-4777-8777-777777777777', 'tenant_db_name' => 'acme_tenant_other'];
        $racing = new class ($store) implements \StoneScriptPHP\Tenancy\Provisioning\BuiltinRequestStore {
            public bool $first = true;

            public function __construct(private FakeMainStore $inner)
            {
            }

            public function find(string $idempotencyKey): ?array
            {
                if ($this->first) {
                    $this->first = false;
                    return null; // not yet visible to this caller
                }
                return $this->inner->find($idempotencyKey);
            }

            public function save(string $idempotencyKey, string $identityId, string $tenantId, string $membershipId, string $tenantDbName): array
            {
                return $this->inner->save($idempotencyKey, $identityId, $tenantId, $membershipId, $tenantDbName);
            }
        };
        $authority = new BuiltinMembershipAuthority('acme', $store, $racing);

        $r = $authority->reserveTenant('Acme', 'k-race', self::IDENTITY, null);

        $this->assertSame('66666666-6666-4666-8666-666666666666', $r->platformTenantId, 'the winner row is used, not our own ids');
        $this->assertSame('77777777-7777-4777-8777-777777777777', $r->membershipId);
        $this->assertSame('acme_tenant_other', $r->tenantDbName);
        $this->assertTrue($r->replay);
    }

    public function test_external_calls_without_a_configured_credential_are_refused(): void
    {
        $client = new AuthTenancyClient('https://auth.example.test', null, null, function (): array {
            $this->fail('no request may be sent without a credential');
        });

        $this->expectException(ProvisioningException::class);
        (new ExternalMembershipAuthority($client))->markProvisioned(self::TENANT);
    }

    public function test_external_add_member_rejects_a_non_uuid_membership_id(): void
    {
        $authority = $this->external([200, json_encode(['platform_tenant_membership_id' => 'abc'])]);

        $this->expectException(ProvisioningException::class);
        $authority->addMember(self::TENANT, self::IDENTITY, 'k');
    }

    public function test_external_list_maps_rows_and_slug_may_be_null(): void
    {
        $authority = $this->external([200, json_encode(['memberships' => [
            ['id' => self::MEMBERSHIP, 'tenant_id' => self::TENANT, 'tenant_name' => 'Acme', 'tenant_slug' => null, 'tenant_db_name' => 'acme_tenant_x', 'is_tenant_owner' => true],
            ['tenant_id' => self::TENANT, 'is_tenant_owner' => false],
        ]])]);

        $rows = $authority->listMemberships(self::IDENTITY, self::USER_TOKEN);

        $this->assertCount(1, $rows, 'a row without a membership id is dropped, never invented');
        $this->assertNull($rows[0]['tenant_slug']);
        $this->assertTrue($rows[0]['is_tenant_owner']);
        $this->assertSame('GET', $this->requests[0][0]);
    }

    // ---- Builtin ----

    private function builtin(?FakeMainStore &$store = null): BuiltinMembershipAuthority
    {
        $store = new FakeMainStore(new CallLog());
        $n = 0;
        $uuid = static function () use (&$n): string {
            $n++;
            return sprintf('00000000-0000-4000-8000-%012d', $n);
        };
        return new BuiltinMembershipAuthority('acme', $store, $store, $uuid);
    }

    public function test_builtin_reserve_generates_ids_locally_and_the_db_name_follows_the_platform_naming(): void
    {
        $authority = $this->builtin();

        $r = $authority->reserveTenant('Acme', 'k1', self::IDENTITY, null);

        $this->assertSame('00000000-0000-4000-8000-000000000001', $r->platformTenantId);
        $this->assertSame('00000000-0000-4000-8000-000000000002', $r->membershipId);
        $this->assertSame('acme_tenant_00000000_0000_4000_8000_000000000001', $r->tenantDbName);
        $this->assertFalse($r->replay);
    }

    public function test_builtin_reserve_is_idempotent_and_returns_the_same_ids(): void
    {
        $authority = $this->builtin();

        $first = $authority->reserveTenant('Acme', 'k1', self::IDENTITY, null);
        $again = $authority->reserveTenant('Acme', 'k1', self::IDENTITY, null);

        $this->assertSame($first->platformTenantId, $again->platformTenantId);
        $this->assertSame($first->membershipId, $again->membershipId);
        $this->assertTrue($again->replay);
    }

    public function test_builtin_never_hands_a_tenant_to_a_different_identity_using_the_same_key(): void
    {
        $authority = $this->builtin();
        $authority->reserveTenant('Acme', 'k1', self::IDENTITY, null);

        $this->expectException(ProvisioningException::class);
        $authority->reserveTenant('Acme', 'k1', '99999999-9999-4999-8999-999999999999', null);
    }

    public function test_builtin_add_member_requires_a_provisioned_tenant_and_is_idempotent(): void
    {
        $store = null;
        $authority = $this->builtin($store);
        $r = $authority->reserveTenant('Acme', 'k1', self::IDENTITY, null);

        try {
            $authority->addMember($r->platformTenantId, '44444444-4444-4444-8444-444444444444', 'inv-1');
            $this->fail('a tenant that is not provisioned must refuse members');
        } catch (ProvisioningException $e) {
            $this->assertSame('add_member', $e->step);
        }

        $store->upsertTenant($r->platformTenantId, 'Acme', null, $r->tenantDbName, self::IDENTITY);
        $store->setTenantStatus($r->platformTenantId, MainTenantStore::STATUS_PROVISIONED);
        $a = $authority->addMember($r->platformTenantId, '44444444-4444-4444-8444-444444444444', 'inv-1');
        $b = $authority->addMember($r->platformTenantId, '44444444-4444-4444-8444-444444444444', 'inv-1');
        $this->assertSame($a, $b);

        $this->expectException(ProvisioningException::class);
        $authority->addMember($r->platformTenantId, '55555555-5555-4555-8555-555555555555', 'inv-1');
    }

    public function test_builtin_list_returns_only_provisioned_tenants_from_the_local_store(): void
    {
        $store = null;
        $authority = $this->builtin($store);
        $r = $authority->reserveTenant('Acme', 'k1', self::IDENTITY, null);
        $store->upsertTenant($r->platformTenantId, 'Acme', null, $r->tenantDbName, self::IDENTITY);
        $store->createMembership($r->membershipId, $r->platformTenantId, self::IDENTITY, true, null);

        $this->assertSame([], $authority->listMemberships(self::IDENTITY, null), 'still provisioning');

        $store->setTenantStatus($r->platformTenantId, MainTenantStore::STATUS_PROVISIONED);
        $rows = $authority->listMemberships(self::IDENTITY, null);
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['tenant_slug']);
    }

    // ---- Staff invite on top of an authority ----

    public function test_staff_invite_adds_authority_membership_then_tenant_user_then_main_row(): void
    {
        $log = new CallLog();
        $authority = new FakeAuthority($log);
        $main = new FakeMainStore($log);
        $seeder = new FakeSeeder($log);

        $id = (new TenantStaffInvite($authority, $main, $seeder))->add(self::TENANT, self::IDENTITY, 'cashier', '66666666-6666-4666-8666-666666666666', 'inv-1', 'Cash Ier');

        $this->assertSame(['authority.addMember', 'tenantdb.user:cashier', 'main.membership'], $log->calls);
        $this->assertSame($id, $main->memberships[0]['id']);
        $this->assertFalse($main->memberships[0]['owner'], 'an invited member is never the owner');
        $this->assertSame('66666666-6666-4666-8666-666666666666', $main->memberships[0]['invited_by']);
    }

    public function test_staff_invite_refused_by_the_authority_writes_nothing_locally(): void
    {
        $log = new CallLog();
        $authority = new FakeAuthority($log);
        $authority->failAt = 'add_member';
        $main = new FakeMainStore($log);
        $seeder = new FakeSeeder($log);

        try {
            (new TenantStaffInvite($authority, $main, $seeder))->add(self::TENANT, self::IDENTITY, 'cashier', self::IDENTITY, 'inv-1');
            $this->fail('expected a ProvisioningException');
        } catch (ProvisioningException $e) {
            $this->assertSame('add_member', $e->step);
        }
        $this->assertSame([], $main->memberships);
        $this->assertSame([], $seeder->users);
    }

    public function test_staff_invite_rejects_a_bad_role_code_and_names_the_failed_local_step(): void
    {
        $log = new CallLog();
        $authority = new FakeAuthority($log);
        $main = new FakeMainStore($log);
        $seeder = new FakeSeeder($log);
        $invite = new TenantStaffInvite($authority, $main, $seeder);

        try {
            $invite->add(self::TENANT, self::IDENTITY, 'Owner; DROP', self::IDENTITY, 'k');
            $this->fail('expected a ProvisioningException');
        } catch (ProvisioningException) {
        }
        $this->assertSame([], $log->calls, 'a bad role code is refused before the authority is called');

        $main->failAt = 'main_membership';
        try {
            $invite->add(self::TENANT, self::IDENTITY, 'cashier', self::IDENTITY, 'k');
            $this->fail('expected a ProvisioningException');
        } catch (ProvisioningException $e) {
            $this->assertSame('main_membership', $e->step);
        }
    }
}
