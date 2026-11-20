<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\TenantRoles;

/**
 * Resolves what an identity may do inside the current tenant from the tenant database
 * (`users`, `user_roles`, `user_role_privileges`).
 *
 * FAIL CLOSED: a role with no seeded privileges is an unknown role and grants nothing (it is not
 * reported as a role either), an identity with no user row has no roles, and any store failure
 * yields no roles and no privileges. There is no fallback role: in particular, an unrecognised role
 * never becomes `owner`.
 */
final class TenantRoleResolver
{
    public function __construct(private readonly TenantRoleStore $store)
    {
    }

    /** @return list<string> the held roles that are known (have at least one privilege), sorted */
    public function rolesFor(string $identityId): array
    {
        return self::knownRoles($this->grants($identityId));
    }

    /** @return list<string> sorted, distinct */
    public function privilegesFor(string $identityId): array
    {
        return self::union($this->grants($identityId));
    }

    /**
     * Like {@see rolesFor()} but a store failure THROWS instead of meaning "no roles". Use it where a failed read
     * must be told apart from a user who really has no role (select-tenant answers 502 for the first, 403 for
     * the second). An empty identity id is still "no roles".
     *
     * @return list<string>
     * @throws \Throwable the store's own exception
     */
    public function strictRolesFor(string $identityId): array
    {
        return self::knownRoles($identityId === '' ? [] : $this->store->roleGrants($identityId));
    }

    /**
     * Strict variant of {@see privilegesFor()}: a store failure throws.
     *
     * @return list<string>
     * @throws \Throwable
     */
    public function strictPrivilegesFor(string $identityId): array
    {
        return self::union($identityId === '' ? [] : $this->store->roleGrants($identityId));
    }

    /**
     * One strict read giving both the known roles and the union of their privileges (consistent by construction).
     *
     * @return array{roles:list<string>,privileges:list<string>}
     * @throws \Throwable
     */
    public function strictAccess(string $identityId): array
    {
        $grants = $identityId === '' ? [] : $this->store->roleGrants($identityId);
        return ['roles' => self::knownRoles($grants), 'privileges' => self::union($grants)];
    }

    /** @param array<string,list<string>> $grants @return list<string> */
    private static function knownRoles(array $grants): array
    {
        $roles = [];
        foreach ($grants as $role => $privileges) {
            if ($privileges !== []) {
                $roles[] = (string) $role;
            }
        }
        sort($roles);
        return $roles;
    }

    /** @param array<string,list<string>> $grants @return list<string> */
    private static function union(array $grants): array
    {
        $all = [];
        foreach ($grants as $privileges) {
            foreach ($privileges as $p) {
                $all[$p] = true;
            }
        }
        $codes = array_map('strval', array_keys($all));
        sort($codes);
        return $codes;
    }

    public function can(string $identityId, string $privilege): bool
    {
        return $privilege !== '' && in_array($privilege, $this->privilegesFor($identityId), true);
    }

    /** True only for a known role actually held. */
    public function hasRole(string $identityId, string $role): bool
    {
        return in_array($role, $this->rolesFor($identityId), true);
    }

    /** @return array<string,list<string>> */
    private function grants(string $identityId): array
    {
        if ($identityId === '') {
            return [];
        }
        try {
            return $this->store->roleGrants($identityId);
        } catch (\Throwable) {
            if (function_exists('log_error')) {
                log_error('TenantRoleResolver: role lookup failed; granting nothing');
            }
            return [];
        }
    }
}
