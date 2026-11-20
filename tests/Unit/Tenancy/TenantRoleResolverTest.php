<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit\Tenancy;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\TenantRoles\DatabaseTenantRoleStore;
use StoneScriptPHP\Auth\TenantRoles\TenantRoleResolver;
use StoneScriptPHP\Auth\TenantRoles\TenantRoleStore;

/**
 * Roles and privileges come from the tenant database; an unknown role grants NOTHING and there is no
 * fallback role (in particular, never "owner").
 *
 * @covers \StoneScriptPHP\Auth\TenantRoles\TenantRoleResolver
 * @covers \StoneScriptPHP\Auth\TenantRoles\DatabaseTenantRoleStore
 */
final class TenantRoleResolverTest extends TestCase
{
    private const IDENTITY = '11111111-1111-4111-8111-111111111111';

    /** @param array<string,list<string>> $grants */
    private function resolver(array $grants): TenantRoleResolver
    {
        return new TenantRoleResolver(new class ($grants) implements TenantRoleStore {
            /** @param array<string,list<string>> $grants */
            public function __construct(private array $grants)
            {
            }

            public function roleGrants(string $identityId): array
            {
                return $this->grants;
            }
        });
    }

    public function test_known_roles_resolve_with_their_privileges(): void
    {
        $r = $this->resolver(['owner' => ['sales.create', 'staff.invite'], 'cashier' => ['sales.create']]);

        $this->assertSame(['cashier', 'owner'], $r->rolesFor(self::IDENTITY));
        $this->assertSame(['sales.create', 'staff.invite'], $r->privilegesFor(self::IDENTITY));
        $this->assertTrue($r->can(self::IDENTITY, 'staff.invite'));
        $this->assertTrue($r->hasRole(self::IDENTITY, 'owner'));
    }

    public function test_an_unknown_role_grants_nothing_and_is_not_reported_as_a_role(): void
    {
        $r = $this->resolver(['authenticated' => []]);

        $this->assertSame([], $r->rolesFor(self::IDENTITY));
        $this->assertSame([], $r->privilegesFor(self::IDENTITY));
        $this->assertFalse($r->can(self::IDENTITY, 'sales.create'));
        $this->assertFalse($r->hasRole(self::IDENTITY, 'authenticated'));
    }

    public function test_an_unknown_role_never_becomes_owner(): void
    {
        foreach (['authenticated', 'member', 'Owner', '', 'admin'] as $role) {
            $r = $this->resolver([$role => []]);

            $this->assertFalse($r->hasRole(self::IDENTITY, 'owner'), "role '$role' must not grant owner");
            $this->assertFalse($r->can(self::IDENTITY, 'staff.invite'));
        }
    }

    public function test_a_known_role_does_not_make_an_unknown_one_known(): void
    {
        $r = $this->resolver(['cashier' => ['sales.create'], 'ghost' => []]);

        $this->assertSame(['cashier'], $r->rolesFor(self::IDENTITY));
        $this->assertFalse($r->hasRole(self::IDENTITY, 'ghost'));
    }

    public function test_an_identity_with_no_user_row_has_no_roles(): void
    {
        $r = $this->resolver([]);

        $this->assertSame([], $r->rolesFor(self::IDENTITY));
        $this->assertFalse($r->can(self::IDENTITY, 'anything'));
        $this->assertFalse($r->can('', 'anything'));
    }

    public function test_a_failing_store_grants_nothing(): void
    {
        $r = new TenantRoleResolver(new class implements TenantRoleStore {
            public function roleGrants(string $identityId): array
            {
                throw new \RuntimeException('tenant database unreachable');
            }
        });

        $this->assertSame([], $r->rolesFor(self::IDENTITY));
        $this->assertFalse($r->can(self::IDENTITY, 'sales.create'));
    }

    public function test_database_store_maps_rows_and_keeps_a_role_that_has_no_privileges(): void
    {
        $calls = [];
        $store = new DatabaseTenantRoleStore(function (string $fn, array $params) use (&$calls): array {
            $calls[] = [$fn, $params];
            return [
                ['o_role_code' => 'owner', 'o_privilege_code' => 'a'],
                ['o_role_code' => 'owner', 'o_privilege_code' => 'b'],
                ['o_role_code' => 'ghost', 'o_privilege_code' => null],
                ['o_role_code' => '', 'o_privilege_code' => 'x'],
            ];
        });

        $this->assertSame(['owner' => ['a', 'b'], 'ghost' => []], $store->roleGrants(self::IDENTITY));
        $this->assertSame([['tenant_get_identity_role_privileges', [self::IDENTITY]]], $calls);
    }
}
