<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit\Tenancy;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Tenancy\Provisioning\ProvisionRequest;
use StoneScriptPHP\Tenancy\Provisioning\ProvisioningException;
use StoneScriptPHP\Tenancy\Provisioning\TenantProvisioningSequence;

require_once __DIR__ . '/TenancyFakes.php';

/**
 * The default provisioning order (design 4.2) and EVERY failure step: each step failing, a retry with the
 * same idempotency key resuming the SAME tenant, and a mismatched database_name being refused.
 *
 * @covers \StoneScriptPHP\Tenancy\Provisioning\TenantProvisioningSequence
 */
final class TenantProvisioningSequenceTest extends TestCase
{
    private CallLog $log;
    private FakeAuthority $authority;
    private FakeMainStore $main;
    private FakeDatabases $databases;
    private FakeSeeder $seeder;

    protected function setUp(): void
    {
        $this->log = new CallLog();
        $this->authority = new FakeAuthority($this->log);
        $this->main = new FakeMainStore($this->log);
        $this->databases = new FakeDatabases($this->log);
        $this->seeder = new FakeSeeder($this->log);
    }

    private function sequence(array $hooks = [], ?string $slug = 'acme'): TenantProvisioningSequence
    {
        return new TenantProvisioningSequence($this->authority, $this->main, $this->databases, $this->seeder, $hooks, 'owner', $slug);
    }

    private function request(string $key = 'key-1'): ProvisionRequest
    {
        return new ProvisionRequest('Acme Shop', $key, '11111111-1111-4111-8111-111111111111', 'user-token', 'Owner Name');
    }

    public function test_default_order_is_auth_first_then_main_row_database_owner_membership_then_provisioned(): void
    {
        $result = $this->sequence()->provision($this->request());

        $this->assertSame([
            'authority.reserve',
            'main.upsertTenant',
            'db.create',
            'tenantdb.privileges',
            'tenantdb.user:owner',
            'main.membership',
            'authority.provisioned',
            'main.status:provisioned',
        ], $this->log->calls);
        $this->assertSame('provisioned', $this->main->tenants[$result->tenantId]['status']);
        $this->assertSame('provisioned', $this->authority->status[$result->tenantId]);
        $this->assertFalse($result->alreadyProvisioned);
    }

    public function test_the_same_ids_are_used_in_every_store(): void
    {
        $result = $this->sequence()->provision($this->request());

        $reservation = $this->authority->byKey['key-1'];
        $this->assertSame($reservation->platformTenantId, $result->tenantId);
        $this->assertSame($reservation->tenantDbName, $result->tenantDbName);
        $this->assertSame([$result->tenantId], $this->databases->created, 'database uuid = platform tenant id');
        $this->assertSame($reservation->tenantDbName, $this->main->tenants[$result->tenantId]['db']);
        $this->assertSame($reservation->membershipId, $this->main->memberships[0]['id'], 'main membership carries the authority membership id');
        $this->assertTrue($this->main->memberships[0]['owner']);
        $this->assertSame([[$result->tenantId, '11111111-1111-4111-8111-111111111111', 'owner', null]], $this->seeder->users);
    }

    public function test_tenant_db_name_is_slug_tenant_uuid_with_underscores(): void
    {
        $result = $this->sequence()->provision($this->request());

        $this->assertSame('acme_tenant_' . str_replace('-', '_', $result->tenantId), $result->tenantDbName);
    }

    /** @return array<string,array{0:string,1:string,2:bool,3:bool}> [failAt target, expected step, authority told failed, main marked failed] */
    public static function failingSteps(): array
    {
        return [
            'main tenant row' => ['main_tenant_row', 'main_tenant_row', true, false],
            'create database' => ['create_database', 'create_database', true, true],
            'seed privileges' => ['seed_privileges', 'seed_privileges', true, true],
            'owner user' => ['owner_user', 'owner_user', true, true],
            'main membership' => ['main_membership', 'main_membership', true, true],
            'authority provisioned' => ['auth_provisioned', 'auth_provisioned', true, true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failingSteps')]
    public function test_every_failing_step_marks_the_tenant_failed_and_names_the_step(string $failAt, string $expectedStep, bool $authorityTold, bool $mainMarked): void
    {
        match ($failAt) {
            'main_tenant_row', 'main_membership' => $this->main->failAt = $failAt,
            'create_database' => $this->databases->failAt = $failAt,
            'owner_user', 'seed_privileges' => $this->seeder->failAt = $failAt,
            'auth_provisioned' => $this->authority->failAt = $failAt,
        };

        try {
            $this->sequence()->provision($this->request());
            $this->fail('expected a ProvisioningException');
        } catch (ProvisioningException $e) {
            $this->assertSame($expectedStep, $e->step);
        }

        $tenantId = $this->authority->byKey['key-1']->platformTenantId;
        $this->assertSame($authorityTold ? 1 : 0, $this->log->count('authority.failed'));
        if ($failAt === 'main_tenant_row') {
            $this->assertArrayNotHasKey($tenantId, $this->main->tenants, 'nothing was written to main');
        } else {
            $this->assertSame('provisioning_failed', $this->main->tenants[$tenantId]['status']);
        }
        $this->assertNotSame('provisioned', $this->authority->status[$tenantId] ?? null);
    }

    public function test_the_privilege_seed_is_passed_to_the_tenant_database_and_the_owner_role_is_checked(): void
    {
        $grants = ['owner' => ['tenant.manage'], 'cashier' => ['sales.create']];

        $result = (new TenantProvisioningSequence($this->authority, $this->main, $this->databases, $this->seeder, [], 'owner', 'acme', 'tenant', $grants))->provision($this->request());

        $this->assertSame([[$result->tenantId, $grants, 'owner']], $this->seeder->privilegeSeeds);
    }

    public function test_an_owner_role_with_no_privileges_fails_the_tenant_and_nothing_after_it_runs(): void
    {
        $this->seeder->ownerPrivileges = 0;

        try {
            $this->sequence()->provision($this->request());
            $this->fail('expected a ProvisioningException');
        } catch (ProvisioningException $e) {
            $this->assertSame('seed_privileges', $e->step);
            $this->assertStringContainsString('config/tenant-privileges.php', $e->getMessage());
        }

        $tenantId = $this->authority->byKey['key-1']->platformTenantId;
        $this->assertSame('provisioning_failed', $this->main->tenants[$tenantId]['status']);
        $this->assertSame('provisioning_failed', $this->authority->status[$tenantId]);
        $this->assertSame(0, $this->log->count('tenantdb.user'), 'no owner user for an unusable owner role');
        $this->assertSame([], $this->main->memberships);
        $this->assertSame(0, $this->log->count('authority.provisioned'));
    }

    public function test_reserve_failure_writes_nothing_and_marks_nothing(): void
    {
        $this->authority->failAt = 'reserve';

        try {
            $this->sequence()->provision($this->request());
            $this->fail('expected a ProvisioningException');
        } catch (ProvisioningException $e) {
            $this->assertSame('reserve', $e->step);
        }

        $this->assertSame(['authority.reserve'], $this->log->calls);
        $this->assertSame([], $this->main->tenants);
    }

    public function test_final_main_status_failure_leaves_the_tenant_resumable_and_does_not_contradict_the_authority(): void
    {
        $this->main->failAt = 'main_provisioned';

        try {
            $this->sequence()->provision($this->request());
            $this->fail('expected a ProvisioningException');
        } catch (ProvisioningException $e) {
            $this->assertSame('main_provisioned', $e->step);
        }

        $tenantId = $this->authority->byKey['key-1']->platformTenantId;
        $this->assertSame('provisioned', $this->authority->status[$tenantId], 'the authority already said provisioned; it is not told "failed"');
        $this->assertSame(0, $this->log->count('authority.failed'));
        $this->assertSame('provisioning', $this->main->tenants[$tenantId]['status'], 'not marked failed: only the status write is left');

        $this->main->failAt = null;
        $retry = $this->sequence()->provision($this->request());
        $this->assertSame($tenantId, $retry->tenantId);
        $this->assertSame('provisioned', $this->main->tenants[$tenantId]['status']);
    }

    public function test_retry_with_the_same_key_resumes_the_same_tenant_after_a_database_failure(): void
    {
        $this->databases->failAt = 'create_database';
        try {
            $this->sequence()->provision($this->request('retry-key'));
            $this->fail('expected a ProvisioningException');
        } catch (ProvisioningException) {
        }
        $firstTenant = $this->authority->byKey['retry-key']->platformTenantId;
        $this->assertSame('provisioning_failed', $this->main->tenants[$firstTenant]['status']);

        $this->databases->failAt = null;
        $result = $this->sequence()->provision($this->request('retry-key'));

        $this->assertSame($firstTenant, $result->tenantId, 'same tenant, no new uuid');
        $this->assertCount(1, $this->authority->byKey);
        $this->assertCount(1, $this->main->tenants);
        $this->assertSame('provisioned', $this->main->tenants[$firstTenant]['status']);
        $this->assertSame('provisioned', $this->authority->status[$firstTenant]);
        $this->assertCount(1, $this->main->memberships);
    }

    public function test_retry_after_each_step_failure_resumes_the_same_tenant(): void
    {
        foreach (['main_membership', 'owner_user', 'auth_provisioned'] as $failAt) {
            $this->setUp();
            match ($failAt) {
                'main_membership' => $this->main->failAt = $failAt,
                'owner_user' => $this->seeder->failAt = $failAt,
                'auth_provisioned' => $this->authority->failAt = $failAt,
            };
            try {
                $this->sequence()->provision($this->request('k'));
                $this->fail("expected a failure at $failAt");
            } catch (ProvisioningException) {
            }
            $tenant = $this->authority->byKey['k']->platformTenantId;

            $this->main->failAt = $this->seeder->failAt = $this->authority->failAt = null;
            $result = $this->sequence()->provision($this->request('k'));

            $this->assertSame($tenant, $result->tenantId, "retry after $failAt must resume the same tenant");
            $this->assertSame('provisioned', $this->main->tenants[$tenant]['status']);
            $this->assertCount(1, $this->main->memberships, "one membership after resuming from $failAt");
        }
    }

    public function test_the_authority_replay_flag_is_passed_through_to_the_result_on_a_resume(): void
    {
        $this->databases->failAt = 'create_database';
        try {
            $this->sequence()->provision($this->request('resume'));
        } catch (ProvisioningException) {
        }
        $this->databases->failAt = null;

        $fresh = $this->sequence()->provision($this->request('brand-new'));
        $resumed = $this->sequence()->provision($this->request('resume'));

        $this->assertFalse($fresh->replay);
        $this->assertTrue($resumed->replay, 'a resume is reported as a replay');
        $this->assertFalse($resumed->alreadyProvisioned);
    }

    public function test_a_different_key_is_a_different_tenant(): void
    {
        $a = $this->sequence()->provision($this->request('key-a'));
        $b = $this->sequence()->provision($this->request('key-b'));

        $this->assertNotSame($a->tenantId, $b->tenantId);
    }

    public function test_mismatched_database_name_from_the_gateway_is_refused(): void
    {
        $this->databases->reportName = 'acme_tenant_somebody_elses_database';

        try {
            $this->sequence()->provision($this->request());
            $this->fail('expected a ProvisioningException');
        } catch (ProvisioningException $e) {
            $this->assertSame('create_database', $e->step);
            $this->assertStringContainsString('does not match', $e->getMessage());
        }

        $tenantId = $this->authority->byKey['key-1']->platformTenantId;
        $this->assertSame('provisioning_failed', $this->main->tenants[$tenantId]['status']);
        $this->assertSame([], $this->main->memberships, 'no membership may exist for a tenant whose database name did not match');
        $this->assertSame(0, $this->log->count('tenantdb.user'), 'nothing is seeded into the wrong database');
        $this->assertSame(0, $this->log->count('authority.provisioned'));
    }

    public function test_matching_reported_name_and_unreported_name_on_retry_are_accepted(): void
    {
        $this->databases->reportName = '';

        $result = $this->sequence()->provision($this->request());

        $this->assertSame('provisioned', $this->main->tenants[$result->tenantId]['status']);
    }

    public function test_reserved_name_that_does_not_follow_the_platform_naming_is_refused_before_creating_any_database(): void
    {
        $this->authority->slug = 'another_platform';

        try {
            $this->sequence()->provision($this->request());
            $this->fail('expected a ProvisioningException');
        } catch (ProvisioningException $e) {
            $this->assertSame('reserve_tenant', $e->step);
        }

        $this->assertSame([], $this->databases->created);
        $this->assertSame(0, $this->log->count('main.upsertTenant'));
        $this->assertSame(1, $this->log->count('authority.failed'), 'the authority is told the reserved tenant failed');
    }

    public function test_an_already_provisioned_tenant_is_returned_unchanged_on_replay(): void
    {
        $seedRuns = 0;
        $hooks = ['seed_tenant' => function () use (&$seedRuns) {
            $seedRuns++;
        }];
        $first = $this->sequence($hooks)->provision($this->request());
        $callsAfterFirst = count($this->log->calls);

        $second = $this->sequence($hooks)->provision($this->request());

        $this->assertSame(1, $seedRuns, 'the seed hook does not run again for a tenant that is already provisioned');
        $this->assertTrue($second->replay);
        $this->assertSame($first->tenantId, $second->tenantId);
        $this->assertTrue($second->alreadyProvisioned);
        $this->assertSame($callsAfterFirst + 1, count($this->log->calls), 'only the reservation lookup ran again');
        $this->assertCount(1, $this->databases->created);
        $this->assertCount(1, $this->seeder->users);
    }

    public function test_failure_report_to_the_authority_never_hides_the_original_error(): void
    {
        $this->databases->failAt = 'create_database';
        $this->authority->failAt = 'auth_failed_report';

        try {
            $this->sequence()->provision($this->request());
            $this->fail('expected a ProvisioningException');
        } catch (ProvisioningException $e) {
            $this->assertSame('create_database', $e->step);
        }

        $tenantId = $this->authority->byKey['key-1']->platformTenantId;
        $this->assertSame('provisioning_failed', $this->main->tenants[$tenantId]['status'], 'main is still marked');
    }

    public function test_hooks_run_for_business_columns_and_seed_data_and_their_failure_fails_the_tenant(): void
    {
        $seen = [];
        $hooks = [
            'after_tenant_row' => function ($request, $reservation) use (&$seen) {
                $seen[] = 'row:' . $request->extra['city'] . ':' . $reservation->platformTenantId;
            },
            'seed_tenant' => function () use (&$seen) {
                $seen[] = 'seed';
            },
        ];
        $request = new ProvisionRequest('Acme Shop', 'hook-key', '11111111-1111-4111-8111-111111111111', 't', null, null, ['city' => 'Pune']);

        $result = $this->sequence($hooks)->provision($request);

        $this->assertSame(['row:Pune:' . $result->tenantId, 'seed'], $seen);

        $this->setUp();
        try {
            $this->sequence(['seed_tenant' => function () {
                throw new \RuntimeException('seed broke');
            }])->provision($this->request('hook-fail'));
            $this->fail('expected a ProvisioningException');
        } catch (ProvisioningException $e) {
            $this->assertSame('seed_tenant', $e->step);
        }
        $tenantId = $this->authority->byKey['hook-fail']->platformTenantId;
        $this->assertSame('provisioning_failed', $this->main->tenants[$tenantId]['status']);
    }

    public function test_blank_inputs_are_refused_before_anything_is_reserved(): void
    {
        foreach ([new ProvisionRequest(' ', 'k', 'i'), new ProvisionRequest('n', '', 'i'), new ProvisionRequest('n', 'k', '')] as $bad) {
            try {
                $this->sequence()->provision($bad);
                $this->fail('expected a ProvisioningException');
            } catch (ProvisioningException) {
            }
        }
        $this->assertSame([], $this->log->calls);
    }
}
