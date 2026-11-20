<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit\Tenancy;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Tenancy\Maintenance\DatabaseTenantFleet;
use StoneScriptPHP\Tenancy\Maintenance\TenancyFleetCommands;
use StoneScriptPHP\Tenancy\Maintenance\TenantFleet;

/**
 * `tenancy:seed-privileges` and `tenancy:preflight`: every provisioned tenant visited, idempotent, dry run writes
 * nothing, one tenant failing does not stop the others, and the pre-flight fails on a held role with zero privileges.
 *
 * @covers \StoneScriptPHP\Tenancy\Maintenance\TenancyFleetCommands
 * @covers \StoneScriptPHP\Tenancy\Maintenance\DatabaseTenantFleet
 */
final class TenancyFleetCommandsTest extends TestCase
{
    private const A = '11111111-1111-4111-8111-111111111111';
    private const B = '22222222-2222-4222-8222-222222222222';
    private const C = '33333333-3333-4333-8333-333333333333';
    private const GRANTS = ['owner' => ['tenant.manage'], 'cashier' => ['sales.create']];

    private string $out = '';
    private string $err = '';

    /** @param array<string,array{missing?:list<array{role:string,privilege:string}>,counts?:list<array{role:string,privileges:int,users:int}>,fail?:bool}> $tenants */
    private function fleet(array $tenants): FakeFleet
    {
        return new FakeFleet($tenants);
    }

    private function commands(TenantFleet $fleet): TenancyFleetCommands
    {
        return new TenancyFleetCommands(function (string $t): void { $this->out .= $t; }, function (string $t): void { $this->err .= $t; }, $fleet);
    }

    public function test_every_provisioned_tenant_is_visited_and_reported_per_tenant(): void
    {
        $fleet = $this->fleet([
            self::A => ['missing' => [['role' => 'owner', 'privilege' => 'tenant.manage']]],
            self::B => ['missing' => []],
            self::C => ['missing' => [['role' => 'cashier', 'privilege' => 'sales.create'], ['role' => 'owner', 'privilege' => 'tenant.manage']]],
        ]);

        $status = $this->commands($fleet)->seedPrivileges([], self::GRANTS);

        $this->assertSame(0, $status);
        $this->assertSame([self::A, self::C], $fleet->applied, 'only the tenants that miss something are written');
        $this->assertStringContainsString('seeded  ' . self::A, $this->out);
        $this->assertStringContainsString('ok      ' . self::B, $this->out);
        $this->assertStringContainsString('added 2 privilege(s): cashier [sales.create]; owner [tenant.manage]', $this->out);
        $this->assertStringContainsString('3 tenant(s) visited, 2 changed, 0 failed', $this->out);
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $fleet = $this->fleet([self::A => ['missing' => [['role' => 'owner', 'privilege' => 'tenant.manage']]]]);
        $this->commands($fleet)->seedPrivileges([], self::GRANTS);
        $this->assertSame([self::A], $fleet->applied);

        $this->out = '';
        $status = $this->commands($fleet)->seedPrivileges([], self::GRANTS);

        $this->assertSame(0, $status);
        $this->assertSame([self::A], $fleet->applied, 'the second run wrote nothing');
        $this->assertStringContainsString('ok      ' . self::A . ' (db_1111): nothing to add', $this->out);
        $this->assertStringContainsString('0 changed', $this->out);
    }

    public function test_dry_run_reports_what_would_be_added_and_writes_nothing(): void
    {
        $fleet = $this->fleet([self::A => ['missing' => [['role' => 'owner', 'privilege' => 'tenant.manage']]]]);

        $status = $this->commands($fleet)->seedPrivileges(['--dry-run'], self::GRANTS);

        $this->assertSame(0, $status);
        $this->assertSame([], $fleet->applied);
        $this->assertStringContainsString('would   ' . self::A, $this->out);
        $this->assertStringContainsString('dry run: nothing written', $this->out);
    }

    public function test_one_tenant_only_and_unknown_or_malformed_tenants_are_refused(): void
    {
        $fleet = $this->fleet([self::A => ['missing' => [['role' => 'owner', 'privilege' => 'x.y']]], self::B => ['missing' => [['role' => 'owner', 'privilege' => 'x.y']]]]);

        $this->assertSame(0, $this->commands($fleet)->seedPrivileges(['--tenant=' . self::B], self::GRANTS));
        $this->assertSame([self::B], $fleet->applied);

        $this->assertSame(1, $this->commands($fleet)->seedPrivileges(['--tenant=' . self::C], self::GRANTS));
        $this->assertStringContainsString('not a provisioned tenant', $this->err);
        $this->err = '';
        $this->assertSame(1, $this->commands($fleet)->seedPrivileges(['--tenant=nope'], self::GRANTS));
        $this->assertStringContainsString('uuid', $this->err);
        $this->err = '';
        $this->assertSame(1, $this->commands($fleet)->seedPrivileges(['--bogus'], self::GRANTS));
        $this->assertStringContainsString('Usage', $this->err);
    }

    public function test_a_failing_tenant_is_reported_the_others_still_run_and_the_exit_status_is_1(): void
    {
        $fleet = $this->fleet([
            self::A => ['fail' => true],
            self::B => ['missing' => [['role' => 'owner', 'privilege' => 'x.y']]],
        ]);

        $status = $this->commands($fleet)->seedPrivileges([], self::GRANTS);

        $this->assertSame(1, $status);
        $this->assertSame([self::B], $fleet->applied, 'the tenant after the failing one is still seeded');
        $this->assertStringContainsString('FAILED  ' . self::A, $this->err);
        $this->assertStringContainsString('1 failed', $this->out);
    }

    public function test_an_empty_seed_is_refused(): void
    {
        $this->assertSame(1, $this->commands($this->fleet([self::A => []]))->seedPrivileges([], []));
        $this->assertSame(1, $this->commands($this->fleet([self::A => []]))->seedPrivileges([], ['owner' => []]));
        $this->assertStringContainsString('no privileges', $this->err);
    }

    public function test_preflight_passes_when_every_held_role_has_privileges(): void
    {
        $fleet = $this->fleet([
            self::A => ['counts' => [['role' => 'owner', 'privileges' => 2, 'users' => 1], ['role' => 'spare', 'privileges' => 0, 'users' => 0]]],
            self::B => ['counts' => []],
        ]);

        $status = $this->commands($fleet)->preflight();

        $this->assertSame(0, $status, $this->err);
        $this->assertStringContainsString('Pre-flight passed: 2 provisioned tenant(s)', $this->out);
    }

    public function test_preflight_fails_naming_the_tenant_and_the_role_with_users_and_zero_privileges(): void
    {
        $fleet = $this->fleet([
            self::A => ['counts' => [['role' => 'owner', 'privileges' => 2, 'users' => 1]]],
            self::B => ['counts' => [['role' => 'ghost', 'privileges' => 0, 'users' => 3]]],
        ]);

        $status = $this->commands($fleet)->preflight();

        $this->assertSame(1, $status);
        $this->assertStringContainsString("FAIL " . self::B, $this->err);
        $this->assertStringContainsString("role 'ghost' is held by 3 user(s) but has no privileges", $this->err);
        $this->assertStringContainsString('Do not set TENANCY_V2=true', $this->err);
        $this->assertStringNotContainsString(self::A, $this->err, 'the healthy tenant is not reported');
    }

    public function test_preflight_fails_when_an_active_member_has_no_usable_role_in_the_tenant_database(): void
    {
        $m1 = '44444444-4444-4444-8444-444444444441';
        $m2 = '44444444-4444-4444-8444-444444444442';
        $m3 = '44444444-4444-4444-8444-444444444443';
        $fleet = $this->fleet([
            // healthy: both members have access
            self::A => ['counts' => [['role' => 'owner', 'privileges' => 1, 'users' => 1]], 'members' => [$m1], 'access' => [$m1]],
            // empty user_roles before the role move: three active members, only one can select the tenant
            self::B => ['counts' => [], 'members' => [$m1, $m2, $m3], 'access' => [$m1]],
        ]);

        $status = $this->commands($fleet)->preflight();

        $this->assertSame(1, $status);
        $this->assertStringContainsString('FAIL ' . self::B, $this->err);
        $this->assertStringContainsString("2 of 3 active member(s) have no active tenant user with a role that has privileges; identities: $m2, $m3", $this->err);
        $this->assertStringNotContainsString('FAIL ' . self::A, $this->err);
        $this->assertStringNotContainsString('@', $this->err, 'ids and counts only: no emails or names');
    }

    public function test_preflight_with_every_active_member_covered_passes(): void
    {
        $m1 = '44444444-4444-4444-8444-444444444441';
        $fleet = $this->fleet([self::A => ['members' => [$m1], 'access' => [$m1, '55555555-5555-4555-8555-555555555555']]]);

        $this->assertSame(0, $this->commands($fleet)->preflight(), $this->err);
    }

    public function test_preflight_reports_mirror_drift_and_a_failed_member_comparison(): void
    {
        $fleet = $this->fleet([self::A => []]);
        $fleet->drift = [['membership_id' => '44444444-4444-4444-8444-444444444441', 'status' => 'suspended']];

        $this->assertSame(1, $this->commands($fleet)->preflight());
        $this->assertStringContainsString('mirror drift: 1 membership(s)', $this->err);
        $this->assertStringContainsString('44444444-4444-4444-8444-444444444441=suspended', $this->err);

        $this->err = '';
        $broken = $this->fleet([self::A => ['fail' => true]]);
        $this->assertSame(1, $this->commands($broken)->preflight());

        $this->err = '';
        $noDrift = $this->fleet([self::A => []]);
        $noDrift->driftReadFails = true;
        $this->assertSame(1, $this->commands($noDrift)->preflight());
        $this->assertStringContainsString('could not read the membership mirror drift list', $this->err);
    }

    public function test_preflight_fails_when_a_tenant_database_cannot_be_read(): void
    {
        $status = $this->commands($this->fleet([self::A => ['fail' => true]]))->preflight();

        $this->assertSame(1, $status);
        $this->assertStringContainsString('could not read the tenant database', $this->err);
    }

    public function test_the_database_fleet_maps_the_generated_functions(): void
    {
        $calls = [];
        $fleet = new DatabaseTenantFleet(function (string $fn, array $params) use (&$calls): array {
            $calls[] = [$fn, $params];
            return match ($fn) {
                'tenancy_list_tenants' => [['o_uuid' => self::A, 'o_name' => 'Acme', 'o_tenant_db_name' => 'acme_tenant_a']],
                'tenant_missing_privileges' => [['o_role_code' => 'owner', 'o_privilege_code' => 'tenant.manage']],
                'tenant_role_privilege_counts' => [['o_role_code' => 'owner', 'o_privilege_count' => 2, 'o_user_count' => 1]],
                'tenancy_list_active_member_identities' => [['o_identity_id' => self::B]],
                'tenant_identities_with_access' => [['o_identity_id' => self::C]],
                'tenancy_list_mirror_drift' => [['o_membership_id' => self::A, 'o_auth_status' => 'removed']],
                default => [],
            };
        });

        \StoneScriptPHP\Database::fake([]); // no gateway client in tests: the tenant scope just runs
        try {
            $this->assertSame([['id' => self::A, 'name' => 'Acme', 'db_name' => 'acme_tenant_a']], $fleet->provisionedTenants());
            $this->assertSame([['role' => 'owner', 'privilege' => 'tenant.manage']], $fleet->missingPrivileges(self::A, self::GRANTS));
            $fleet->applyPrivileges(self::A, self::GRANTS);
            $this->assertSame([['role' => 'owner', 'privileges' => 2, 'users' => 1]], $fleet->roleCounts(self::A));
            $this->assertSame([self::B], $fleet->activeMemberIdentities(self::A));
            $this->assertSame([self::C], $fleet->identitiesWithAccess(self::A));
            $this->assertSame([['membership_id' => self::A, 'status' => 'removed']], $fleet->mirrorDrift());
        } finally {
            \StoneScriptPHP\Database::clearFakeMode();
        }

        $this->assertSame(['tenancy_list_tenants', ['provisioned']], $calls[0]);
        $this->assertSame(['owner' => ['tenant.manage'], 'cashier' => ['sales.create']], json_decode($calls[1][1][0], true));
        $this->assertSame('tenant_seed_privileges', $calls[2][0]);
    }
}

final class FakeFleet implements TenantFleet
{
    /** @var list<string> */
    public array $applied = [];

    /** @param array<string,array<string,mixed>> $tenants */
    public function __construct(private array $tenants)
    {
    }

    public function provisionedTenants(): array
    {
        $out = [];
        foreach (array_keys($this->tenants) as $id) {
            $out[] = ['id' => $id, 'name' => 'T', 'db_name' => 'db_' . substr($id, 0, 4)];
        }
        return $out;
    }

    public function missingPrivileges(string $tenantId, array $grants): array
    {
        $this->maybeFail($tenantId);
        return $this->tenants[$tenantId]['missing'] ?? [];
    }

    public function applyPrivileges(string $tenantId, array $grants): void
    {
        $this->maybeFail($tenantId);
        $this->applied[] = $tenantId;
        // after applying, nothing is missing any more (idempotent second run)
        $this->tenants[$tenantId]['missing'] = [];
    }

    public function roleCounts(string $tenantId): array
    {
        $this->maybeFail($tenantId);
        return $this->tenants[$tenantId]['counts'] ?? [];
    }

    /** @var list<array{membership_id:string,status:string}> */
    public array $drift = [];
    public bool $driftReadFails = false;

    public function activeMemberIdentities(string $tenantId): array
    {
        $this->maybeFail($tenantId);
        return $this->tenants[$tenantId]['members'] ?? [];
    }

    public function identitiesWithAccess(string $tenantId): array
    {
        $this->maybeFail($tenantId);
        return $this->tenants[$tenantId]['access'] ?? ($this->tenants[$tenantId]['members'] ?? []);
    }

    public function mirrorDrift(): array
    {
        if ($this->driftReadFails) {
            throw new \RuntimeException('down');
        }
        return $this->drift;
    }

    private function maybeFail(string $tenantId): void
    {
        if ($this->tenants[$tenantId]['fail'] ?? false) {
            throw new \RuntimeException('tenant database unreachable');
        }
    }
}
