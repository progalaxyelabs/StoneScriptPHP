<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tenancy\Provisioning;

interface TenantDatabaseCreator
{
    /**
     * Create (or confirm) the tenant database whose uuid is $platformTenantId.
     *
     * @return string|null The database name the gateway reports, or null when the gateway confirmed an
     *                     existing, identity-verified database without naming it (a retry). The caller
     *                     compares a non-null name with the reserved tenant_db_name.
     * @throws ProvisioningException
     */
    public function create(string $platformTenantId): ?string;
}
