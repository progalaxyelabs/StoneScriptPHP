<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit\Tenancy;

use StoneScriptPHP\Database;
use StoneScriptPHP\Tenancy\Provisioning\TenantDbScope;
use StoneScriptPHP\Tenancy\Provisioning\TenantDbSeeder;

/**
 * Tenant databases as real state: provisioning writes into it (TenantDbSeeder) and the real role read
 * (tenant_get_identity_role_privileges over Database::fake) reads the SAME state, routed by the tenant the
 * running TenantDbScope names. Nothing is stubbed between "provisioned today" and "logs in tomorrow".
 */
final class InMemoryTenantDbs implements TenantDbSeeder
{
    /** @var array<string,array{priv:array<string,array<string,true>>,roles:array<string,array<string,true>>}> */
    public array $dbs = [];
    public bool $failReads = false;

    private function db(string $tenantId): array
    {
        return $this->dbs[$tenantId] ?? ['priv' => [], 'roles' => []];
    }

    public function seedPrivileges(string $platformTenantId, array $grants, string $checkRole): int
    {
        $db = $this->db($platformTenantId);
        foreach ($grants as $role => $privileges) {
            foreach ($privileges as $p) {
                $db['priv'][$role][$p] = true;
            }
        }
        $this->dbs[$platformTenantId] = $db;
        return count($db['priv'][$checkRole] ?? []);
    }

    public function ensureUserWithRole(string $platformTenantId, string $identityId, ?string $displayName, string $roleCode, ?string $assignedByIdentityId): void
    {
        $db = $this->db($platformTenantId);
        $db['roles'][$identityId][$roleCode] = true;
        $this->dbs[$platformTenantId] = $db;
    }

    /** Pre-existing privilege rows, as a platform's own tenant schema would seed them. */
    public function preSeed(string $tenantId, array $grants): void
    {
        $this->seedPrivileges($tenantId, $grants, 'owner');
    }

    /** Route Database::fn('tenant_get_identity_role_privileges') to the tenant database the scope names. */
    public function installDatabaseFake(): void
    {
        Database::fake(['tenant_get_identity_role_privileges' => function (array $params): array {
            if ($this->failReads) {
                throw new \RuntimeException('tenant database unreachable');
            }
            $tenant = TenantDbScope::current();
            if ($tenant === null) {
                throw new \LogicException('a tenant role read must run inside a TenantDbScope');
            }
            $db = $this->db($tenant);
            $rows = [];
            foreach (array_keys($db['roles'][$params[0]] ?? []) as $role) {
                $privileges = array_keys($db['priv'][$role] ?? []);
                if ($privileges === []) {
                    $rows[] = ['o_role_code' => $role, 'o_privilege_code' => null];
                }
                foreach ($privileges as $p) {
                    $rows[] = ['o_role_code' => $role, 'o_privilege_code' => $p];
                }
            }
            return $rows;
        }]);
    }
}
