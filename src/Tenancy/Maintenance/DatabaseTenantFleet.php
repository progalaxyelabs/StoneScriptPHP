<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tenancy\Maintenance;

use StoneScriptPHP\Database;
use StoneScriptPHP\Tenancy\Provisioning\TenantDbScope;

/** TenantFleet over the SQL functions `php stone generate tenancy` writes (main: tenancy_list_tenants; tenant: tenant_*). */
final class DatabaseTenantFleet implements TenantFleet
{
    /** @var callable(string,array):array */
    private $fn;

    /** @param callable(string,array):array|null $fn Database::fn-shaped caller (injectable for tests). */
    public function __construct(?callable $fn = null)
    {
        $this->fn = $fn ?? Database::fn(...);
    }

    public function provisionedTenants(): array
    {
        $out = [];
        foreach (($this->fn)('tenancy_list_tenants', ['provisioned']) as $row) {
            $id = (string) ($row['o_uuid'] ?? $row['uuid'] ?? '');
            if ($id !== '') {
                $out[] = ['id' => $id, 'name' => (string) ($row['o_name'] ?? ''), 'db_name' => (string) ($row['o_tenant_db_name'] ?? '')];
            }
        }
        return $out;
    }

    public function missingPrivileges(string $tenantId, array $grants): array
    {
        return TenantDbScope::run($tenantId, function () use ($grants): array {
            $out = [];
            foreach (($this->fn)('tenant_missing_privileges', [json_encode((object) $grants, JSON_THROW_ON_ERROR)]) as $row) {
                $out[] = ['role' => (string) ($row['o_role_code'] ?? ''), 'privilege' => (string) ($row['o_privilege_code'] ?? '')];
            }
            return $out;
        });
    }

    public function applyPrivileges(string $tenantId, array $grants): void
    {
        TenantDbScope::run($tenantId, function () use ($grants): void {
            ($this->fn)('tenant_seed_privileges', [json_encode((object) $grants, JSON_THROW_ON_ERROR), 'owner']);
        });
    }

    public function activeMemberIdentities(string $tenantId): array
    {
        $out = [];
        foreach (($this->fn)('tenancy_list_active_member_identities', [$tenantId]) as $row) {
            $id = (string) ($row['o_identity_id'] ?? $row['identity_id'] ?? '');
            if ($id !== '') {
                $out[] = $id;
            }
        }
        return $out;
    }

    public function identitiesWithAccess(string $tenantId): array
    {
        return TenantDbScope::run($tenantId, function (): array {
            $out = [];
            foreach (($this->fn)('tenant_identities_with_access', []) as $row) {
                $id = (string) ($row['o_identity_id'] ?? $row['identity_id'] ?? '');
                if ($id !== '') {
                    $out[] = $id;
                }
            }
            return $out;
        });
    }

    public function mirrorDrift(): array
    {
        $out = [];
        foreach (($this->fn)('tenancy_list_mirror_drift', []) as $row) {
            $out[] = ['membership_id' => (string) ($row['o_membership_id'] ?? ''), 'status' => (string) ($row['o_auth_status'] ?? '')];
        }
        return $out;
    }

    public function roleCounts(string $tenantId): array
    {
        return TenantDbScope::run($tenantId, function (): array {
            $out = [];
            foreach (($this->fn)('tenant_role_privilege_counts', []) as $row) {
                $out[] = ['role' => (string) ($row['o_role_code'] ?? ''), 'privileges' => (int) ($row['o_privilege_count'] ?? 0), 'users' => (int) ($row['o_user_count'] ?? 0)];
            }
            return $out;
        });
    }
}
