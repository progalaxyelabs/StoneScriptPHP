<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\TenantRoles;

/** Reads roles and privileges from the TENANT database (the only place they live). */
interface TenantRoleStore
{
    /**
     * Roles an active user holds in the current tenant database, each with the privileges seeded for that
     * role (an empty list when the role has no `user_role_privileges` rows).
     *
     * @return array<string,list<string>> role_code => privilege codes
     */
    public function roleGrants(string $identityId): array;
}
