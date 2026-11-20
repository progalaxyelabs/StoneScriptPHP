<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tenancy\Provisioning;

/** What the membership authority returns when a tenant is reserved: the ids every other store must reuse. */
final class TenantReservation
{
    public function __construct(
        public readonly string $platformTenantId,
        public readonly string $tenantDbName,
        public readonly string $membershipId,
        /** True when the idempotency key had already reserved this tenant (a retry). */
        public readonly bool $replay = false,
    ) {
        if (!Uuid::isValid($platformTenantId) || !Uuid::isValid($membershipId)) {
            throw new \InvalidArgumentException('tenant and membership ids must be lower-case uuids');
        }
        if ($tenantDbName === '') {
            throw new \InvalidArgumentException('tenant_db_name must not be empty');
        }
    }
}
