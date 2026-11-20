<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tenancy\Maintenance;

/** The provisioned tenant databases of this platform, for the fleet-wide tenancy commands. */
interface TenantFleet
{
    /** @return list<array{id:string,name:string,db_name:string}> tenants whose tenant_provisioning_status is `provisioned` */
    public function provisionedTenants(): array;

    /**
     * READ-ONLY: the (role, privilege) pairs of $grants that are not yet in the tenant database.
     *
     * @param array<string,list<string>> $grants
     * @return list<array{role:string,privilege:string}>
     */
    public function missingPrivileges(string $tenantId, array $grants): array;

    /**
     * Write $grants into the tenant database (idempotent; existing rows kept, none removed).
     *
     * @param array<string,list<string>> $grants
     */
    public function applyPrivileges(string $tenantId, array $grants): void;

    /**
     * READ-ONLY (main database): identities with an ACTIVE membership of the tenant.
     *
     * @return list<string>
     */
    public function activeMemberIdentities(string $tenantId): array;

    /**
     * READ-ONLY (tenant database): identities with an ACTIVE users row holding at least one role that has at least one
     * privilege.
     *
     * @return list<string>
     */
    public function identitiesWithAccess(string $tenantId): array;

    /**
     * READ-ONLY (main database): memberships whose auth-confirmed status could not be mirrored (ids and status only).
     *
     * @return list<array{membership_id:string,status:string}>
     */
    public function mirrorDrift(): array;

    /**
     * READ-ONLY: every role held by a user or having privileges, with its privilege and user counts.
     *
     * @return list<array{role:string,privileges:int,users:int}>
     */
    public function roleCounts(string $tenantId): array;
}
