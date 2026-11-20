<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tenancy\Provisioning;

/**
 * Standalone mode: remembers which ids an idempotency key reserved, so a retry resumes the SAME tenant.
 * (External mode keeps this in the auth service.)
 */
interface BuiltinRequestStore
{
    /** @return array{identity_id:string,tenant_id:string,membership_id:string,tenant_db_name:string}|null */
    public function find(string $idempotencyKey): ?array;

    /**
     * Record the ids a key reserved. First writer wins: when the key already exists the EXISTING row is kept and
     * returned, so concurrent first calls all end up with the same ids.
     *
     * @return array{identity_id:string,tenant_id:string,membership_id:string,tenant_db_name:string} the winning row
     */
    public function save(string $idempotencyKey, string $identityId, string $tenantId, string $membershipId, string $tenantDbName): array;
}
