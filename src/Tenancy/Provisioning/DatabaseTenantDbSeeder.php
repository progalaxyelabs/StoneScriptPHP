<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tenancy\Provisioning;

use StoneScriptPHP\Database;

/**
 * TenantDbSeeder over the tenant-DB SQL functions `php stone generate tenancy` writes
 * (tenant_user_upsert, tenant_user_assign_role). Routes the calls to the tenant database through the
 * gateway client's tenant id and always restores the previous routing.
 */
final class DatabaseTenantDbSeeder implements TenantDbSeeder
{
    /** @var callable(string,array):array */
    private $fn;
    /** @var callable(?string):?string */
    private $route;

    /**
     * @param callable(string,array):array|null $fn Database::fn-shaped caller.
     * @param callable(?string):?string|null $route Sets the tenant routing id and returns the previous one.
     */
    public function __construct(?callable $fn = null, ?callable $route = null)
    {
        $this->fn = $fn ?? Database::fn(...);
        $this->route = $route ?? static function (?string $tenantId): ?string {
            $gw = Database::getGatewayClient();
            $previous = $gw->getTenantId();
            $gw->setTenantId($tenantId);
            return is_string($previous) ? $previous : null;
        };
    }

    public function seedPrivileges(string $platformTenantId, array $grants, string $checkRole): int
    {
        $previous = ($this->route)($platformTenantId);
        try {
            $rows = ($this->fn)('tenant_seed_privileges', [json_encode((object) $grants, JSON_THROW_ON_ERROR), $checkRole]);
            $count = $rows[0]['o_privilege_count'] ?? $rows[0]['privilege_count'] ?? null;
            if (!is_int($count) && !(is_string($count) && ctype_digit($count))) {
                throw new \RuntimeException('tenant_seed_privileges did not return a count');
            }
            return (int) $count;
        } finally {
            ($this->route)($previous);
        }
    }

    public function ensureUserWithRole(string $platformTenantId, string $identityId, ?string $displayName, string $roleCode, ?string $assignedByIdentityId): void
    {
        $previous = ($this->route)($platformTenantId);
        try {
            $rows = ($this->fn)('tenant_user_upsert', [$identityId, $displayName]);
            $userId = $rows[0]['o_id'] ?? $rows[0]['id'] ?? null;
            if (!is_string($userId) || !Uuid::isValid($userId)) {
                throw new \RuntimeException('tenant_user_upsert did not return a user id');
            }
            $assignedBy = null;
            if ($assignedByIdentityId !== null) {
                $assignerRows = ($this->fn)('tenant_user_upsert', [$assignedByIdentityId, null]);
                $assignedBy = $assignerRows[0]['o_id'] ?? $assignerRows[0]['id'] ?? null;
            }
            ($this->fn)('tenant_user_assign_role', [$userId, $roleCode, is_string($assignedBy) ? $assignedBy : null]);
        } finally {
            ($this->route)($previous);
        }
    }
}
