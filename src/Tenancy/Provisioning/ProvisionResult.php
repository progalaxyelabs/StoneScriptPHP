<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tenancy\Provisioning;

final class ProvisionResult
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $tenantDbName,
        public readonly string $membershipId,
        /** True when this call found the tenant already provisioned and changed nothing. */
        public readonly bool $alreadyProvisioned = false,
        /** True when the authority recognised the idempotency key (a retry/resume), whether or not it is provisioned now. */
        public readonly bool $replay = false,
    ) {
    }
}
