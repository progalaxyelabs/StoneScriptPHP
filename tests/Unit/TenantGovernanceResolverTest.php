<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\TenantGovernance\TenantGovernanceResolver;
use StoneScriptPHP\Database;

/**
 * Unit tests for the framework-shipped TenantGovernanceResolver — the default
 * tenants_resolver/roles_resolver implementation platforms wire into
 * config/auth.php (TENANT-GOVERNANCE.md §5).
 *
 * Uses Database::fake() to stub get_identity_tenant_memberships /
 * resolve_role_id with the EXACT o_-prefixed row shape the gateway returns
 * for these functions (SPEC.md "Output Column Naming") — so these tests pin
 * that the resolver reads the real wire shape, not an assumed one.
 *
 * @covers \StoneScriptPHP\Auth\TenantGovernance\TenantGovernanceResolver
 */
final class TenantGovernanceResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        Database::clearFakeMode();
    }

    // ── tenants_resolver ────────────────────────────────────────────────

    /**
     * Regression guard for the 7.4.1 main-DB-context fix: both resolvers route
     * their governance calls through queryMainDb(), which first tries to force
     * the gateway tenant context to null. getGatewayClient() throws in fake
     * mode by design — queryMainDb must swallow that and still issue the query,
     * so the resolver keeps working under Database::fake() (and, in production,
     * still forces main-DB context). If queryMainDb ever stopped tolerating a
     * missing gateway client, every fake-mode test here — and this one
     * explicitly — would break.
     */
    public function test_resolvers_work_in_fake_mode_despite_gateway_client_being_unavailable(): void
    {
        Database::fake([
            'get_identity_tenant_memberships' => [
                ['o_tenant_id' => 't1', 'o_is_tenant_owner' => true, 'o_is_tenant_admin' => false, 'o_is_tenant_creator' => true, 'o_job_role' => null, 'o_status' => 'active'],
            ],
            'resolve_role_id' => [['o_role_id' => 'owner']],
        ]);

        $resolver = new TenantGovernanceResolver();

        // Neither call throws (queryMainDb swallowed the getGatewayClient()
        // fake-mode exception) and both return real data.
        $tenants = ($resolver->tenantsResolver())(['sub' => 'id-1']);
        $this->assertSame('t1', $tenants[0]['id']);

        $roles = ($resolver->rolesResolver())(['sub' => 'id-1', 'tenant_id' => 't1']);
        $this->assertSame(['owner'], $roles);
    }

    public function test_tenants_resolver_maps_governance_rows_to_tenant_objects(): void
    {
        Database::fake([
            'get_identity_tenant_memberships' => [
                [
                    'o_id'                => 'm-1',
                    'o_tenant_id'         => 'tenant-owner',
                    'o_is_tenant_creator' => true,
                    'o_is_tenant_owner'   => true,
                    'o_is_tenant_admin'   => false,
                    'o_job_role'          => 'Founder',
                    'o_status'            => 'active',
                ],
                [
                    'o_id'                => 'm-2',
                    'o_tenant_id'         => 'tenant-admin',
                    'o_is_tenant_creator' => false,
                    'o_is_tenant_owner'   => false,
                    'o_is_tenant_admin'   => true,
                    'o_job_role'          => 'Engineer',
                    'o_status'            => 'active',
                ],
                [
                    'o_id'                => 'm-3',
                    'o_tenant_id'         => 'tenant-member',
                    'o_is_tenant_creator' => false,
                    'o_is_tenant_owner'   => false,
                    'o_is_tenant_admin'   => false,
                    'o_job_role'          => null,
                    'o_status'            => 'active',
                ],
            ],
        ]);

        $resolver = new TenantGovernanceResolver();
        $tenants  = ($resolver->tenantsResolver())(['sub' => 'identity-x']);

        $this->assertCount(3, $tenants);

        // Owner tenant — role derived from flags, all flags passed through.
        $this->assertSame('tenant-owner', $tenants[0]['id']);
        $this->assertSame('owner', $tenants[0]['role']);
        $this->assertTrue($tenants[0]['is_tenant_creator']);
        $this->assertTrue($tenants[0]['is_tenant_owner']);
        $this->assertFalse($tenants[0]['is_tenant_admin']);
        $this->assertSame('Founder', $tenants[0]['job_role']);

        // Admin tenant.
        $this->assertSame('admin', $tenants[1]['role']);
        $this->assertFalse($tenants[1]['is_tenant_owner']);
        $this->assertTrue($tenants[1]['is_tenant_admin']);

        // Plain member tenant.
        $this->assertSame('member', $tenants[2]['role']);
        $this->assertNull($tenants[2]['job_role']);
    }

    public function test_tenants_resolver_returns_empty_when_identity_id_missing(): void
    {
        // No fake needed — the resolver must short-circuit before any DB call.
        $resolver = new TenantGovernanceResolver();
        $this->assertSame([], ($resolver->tenantsResolver())([]));
    }

    public function test_tenants_resolver_prefers_sub_then_identity_id_claim(): void
    {
        $seen = [];
        Database::fake([
            'get_identity_tenant_memberships' => function (array $params) use (&$seen): array {
                $seen[] = $params['identity_id'] ?? null;
                return [];
            },
        ]);

        $resolver = new TenantGovernanceResolver();
        ($resolver->tenantsResolver())(['sub' => 'from-sub', 'identity_id' => 'from-id']);
        ($resolver->tenantsResolver())(['identity_id' => 'from-id-only']);

        $this->assertSame(['from-sub', 'from-id-only'], $seen);
    }

    public function test_tenants_resolver_applies_enricher_when_provided(): void
    {
        Database::fake([
            'get_identity_tenant_memberships' => [
                [
                    'o_id'                => 'm-1',
                    'o_tenant_id'         => 'tenant-1',
                    'o_is_tenant_creator' => true,
                    'o_is_tenant_owner'   => true,
                    'o_is_tenant_admin'   => false,
                    'o_job_role'          => null,
                    'o_status'            => 'active',
                ],
            ],
        ]);

        $enricher = function (array $tenants): array {
            foreach ($tenants as &$t) {
                $t['name'] = 'Name of ' . $t['id'];
            }
            return $tenants;
        };

        $resolver = new TenantGovernanceResolver($enricher);
        $tenants  = ($resolver->tenantsResolver())(['sub' => 'identity-x']);

        $this->assertSame('Name of tenant-1', $tenants[0]['name']);
        // Governance fields still present after enrichment.
        $this->assertSame('owner', $tenants[0]['role']);
    }

    public function test_tenants_resolver_tolerates_unprefixed_keys(): void
    {
        // A platform that hand-edited a generated function to drop the o_
        // prefix must still resolve — col() falls back to the bare key.
        Database::fake([
            'get_identity_tenant_memberships' => [
                [
                    'tenant_id'         => 'tenant-bare',
                    'is_tenant_creator' => false,
                    'is_tenant_owner'   => false,
                    'is_tenant_admin'   => true,
                    'job_role'          => 'Ops',
                    'status'            => 'active',
                ],
            ],
        ]);

        $resolver = new TenantGovernanceResolver();
        $tenants  = ($resolver->tenantsResolver())(['sub' => 'identity-x']);

        $this->assertSame('tenant-bare', $tenants[0]['id']);
        $this->assertSame('admin', $tenants[0]['role']);
    }

    // ── roles_resolver ──────────────────────────────────────────────────

    public function test_roles_resolver_returns_single_role_list(): void
    {
        Database::fake([
            'resolve_role_id' => [['o_role_id' => 'owner']],
        ]);

        $resolver = new TenantGovernanceResolver();
        $roles = ($resolver->rolesResolver())(['sub' => 'identity-x', 'tenant_id' => 'tenant-1']);

        $this->assertSame(['owner'], $roles);
    }

    public function test_roles_resolver_returns_empty_for_suspended_or_absent_membership(): void
    {
        // resolve_role_id → _tenant_membership_tier returns NULL for a
        // suspended/removed/nonexistent membership; the gateway surfaces that
        // as a single row with a null o_role_id. The resolver must turn that
        // into an empty list so ExchangeRoute 403s (no_roles_in_tenant).
        Database::fake([
            'resolve_role_id' => [['o_role_id' => null]],
        ]);

        $resolver = new TenantGovernanceResolver();
        $roles = ($resolver->rolesResolver())(['sub' => 'identity-x', 'tenant_id' => 'tenant-1']);

        $this->assertSame([], $roles);
    }

    public function test_roles_resolver_returns_empty_for_no_rows(): void
    {
        Database::fake(['resolve_role_id' => []]);

        $resolver = new TenantGovernanceResolver();
        $roles = ($resolver->rolesResolver())(['sub' => 'identity-x', 'tenant_id' => 'tenant-1']);

        $this->assertSame([], $roles);
    }

    public function test_roles_resolver_returns_empty_when_tenant_id_missing(): void
    {
        // Must short-circuit before any DB call — no fake registered.
        $resolver = new TenantGovernanceResolver();
        $this->assertSame([], ($resolver->rolesResolver())(['sub' => 'identity-x']));
    }

    public function test_roles_resolver_returns_empty_when_identity_missing(): void
    {
        $resolver = new TenantGovernanceResolver();
        $this->assertSame([], ($resolver->rolesResolver())(['tenant_id' => 'tenant-1']));
    }

    public function test_roles_resolver_passes_both_ids_to_the_function(): void
    {
        $seen = [];
        Database::fake([
            'resolve_role_id' => function (array $params) use (&$seen): array {
                $seen = $params;
                return [['o_role_id' => 'member']];
            },
        ]);

        $resolver = new TenantGovernanceResolver();
        $roles = ($resolver->rolesResolver())(['identity_id' => 'id-42', 'tenant_id' => 'tenant-9']);

        $this->assertSame(['member'], $roles);
        $this->assertSame('id-42', $seen['identity_id']);
        $this->assertSame('tenant-9', $seen['tenant_id']);
    }
}
