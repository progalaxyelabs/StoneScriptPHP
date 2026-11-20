<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tenancy\Provisioning;

/**
 * Writes inside a tenant database: the platform privilege seed and the tenant-side `users` / `user_roles`.
 * Everything is idempotent (unique on identity_id, on (user_id, role_code) and on (role_code, privilege_code)),
 * so a retry can resume.
 */
interface TenantDbSeeder
{
    /**
     * Write the platform-wide privilege seed into the tenant database and return how many privileges $checkRole
     * has there AFTER seeding (rows that were already present count). Existing rows are kept.
     *
     * @param array<string,list<string>> $grants role => privilege codes
     * @throws \Throwable on failure (the sequence wraps it with the step name)
     */
    public function seedPrivileges(string $platformTenantId, array $grants, string $checkRole): int;

    /**
     * Ensure the tenant has a user for $identityId holding $roleCode.
     *
     * @param string|null $assignedByIdentityId identity that granted the role (null for the founding owner)
     * @throws \Throwable on failure (the sequence wraps it with the step name)
     */
    public function ensureUserWithRole(string $platformTenantId, string $identityId, ?string $displayName, string $roleCode, ?string $assignedByIdentityId): void;
}
