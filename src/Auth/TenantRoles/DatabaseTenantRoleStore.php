<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\TenantRoles;

use StoneScriptPHP\Database;

/**
 * TenantRoleStore over the `tenant_get_identity_role_privileges` function `php stone generate
 * tenancy` writes into the tenant schema. Runs in the tenant database the request is already routed to.
 */
final class DatabaseTenantRoleStore implements TenantRoleStore
{
    /** @var callable(string,array):array */
    private $fn;

    /** @param callable(string,array):array|null $fn Database::fn-shaped caller (injectable for tests). */
    public function __construct(?callable $fn = null)
    {
        $this->fn = $fn ?? Database::fn(...);
    }

    public function roleGrants(string $identityId): array
    {
        $grants = [];
        foreach (($this->fn)('tenant_get_identity_role_privileges', [$identityId]) as $row) {
            $role = $row['o_role_code'] ?? $row['role_code'] ?? null;
            if (!is_string($role) || $role === '') {
                continue;
            }
            $grants[$role] ??= [];
            $privilege = $row['o_privilege_code'] ?? $row['privilege_code'] ?? null;
            if (is_string($privilege) && $privilege !== '') {
                $grants[$role][] = $privilege;
            }
        }
        return $grants;
    }
}
