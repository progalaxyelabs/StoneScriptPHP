<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tenancy\Provisioning;

use StoneScriptPHP\Tenancy\TenantIdRule;

/**
 * The framework's default tenant provisioning order. Platforms override only business
 * columns and seed data (the two hooks), never the order:
 *
 *   1. authority reserves tenant + owner membership      (auth tenant + owner membership FIRST)
 *   2. main `tenants` row, status `provisioning`
 *   3. gateway creates the tenant database (uuid = platform tenant id); the returned database_name
 *      MUST equal the reserved tenant_db_name
 *   4. tenant DB: the platform-wide privilege seed (user_role_privileges), then the owner `users` row and
 *      owner `user_roles` row (+ optional seed hook). If the owner role has NO privileges in the tenant database
 *      after seeding, provisioning fails here (provisioning_failed, no token): an owner who could not do anything
 *      would be locked out on their next login
 *   5. main `tenant_memberships` row (same membership id)
 *   6. authority marks the tenant provisioned
 *   7. main `tenants` status `provisioned`
 *
 * Any failure marks the tenant `provisioning_failed` (authority best effort, main row when it exists)
 * and throws a ProvisioningException naming the step. After the authority has marked the tenant
 * provisioned, a later failure does NOT mark it failed there (that would contradict the authority).
 * A retry with the same idempotency key gets the SAME ids from the authority and every step is
 * idempotent, so it resumes the same tenant; an already provisioned tenant is returned unchanged.
 *
 * Hooks (both optional, `callable(ProvisionRequest, TenantReservation): void`):
 *   after_tenant_row  right after step 2, to write platform business columns on the tenants row
 *   seed_tenant       in step 4, after the owner user exists, to seed platform data in the tenant DB
 * Hooks MUST be idempotent: a retry that resumes a tenant which is not yet `provisioned` runs them again.
 * A tenant that is already `provisioned` is returned unchanged and runs NO step and NO hook.
 */
final class TenantProvisioningSequence
{
    public const STEP_SEED = 'seed_tenant';
    public const STEP_BUSINESS_COLUMNS = 'after_tenant_row';

    /**
     * @param array{after_tenant_row?:callable,seed_tenant?:callable} $hooks
     * @param string|null $platformSlug when set (with $tenantSchema), the reserved tenant_db_name must equal
     *        the gateway's derivation `{slug}_{schema}_{uuid}`; catches naming drift before any database is made
     * @param array<string,list<string>> $privilegeGrants the platform-wide privilege seed (role => privileges),
     *        see {@see TenantPrivilegeSeed}
     */
    public function __construct(
        private readonly MembershipAuthority $authority,
        private readonly MainTenantStore $main,
        private readonly TenantDatabaseCreator $databases,
        private readonly TenantDbSeeder $tenantDb,
        private readonly array $hooks = [],
        private readonly string $ownerRoleCode = 'owner',
        private readonly ?string $platformSlug = null,
        private readonly string $tenantSchema = 'tenant',
        private readonly array $privilegeGrants = [],
    ) {
    }

    public function provision(ProvisionRequest $request): ProvisionResult
    {
        if (trim($request->tenantName) === '' || $request->idempotencyKey === '' || $request->identityId === '') {
            throw new ProvisioningException(ProvisioningException::STEP_RESERVE, 'tenant name, idempotency key and identity id are required');
        }

        // Step 1. A failure here has nothing to mark: no tenant was reserved.
        $reservation = $this->authority->reserveTenant($request->tenantName, $request->idempotencyKey, $request->identityId, $request->userToken, $request->slug);
        $tenantId = $reservation->platformTenantId;

        $step = ProvisioningException::STEP_RESERVE;
        $mainRowWritten = false;
        $authProvisioned = false;

        try {
            if ($this->main->getTenantStatus($tenantId) === MainTenantStore::STATUS_PROVISIONED) {
                return new ProvisionResult($tenantId, $reservation->tenantDbName, $reservation->membershipId, true, true);
            }
            $this->assertNamingMatches($reservation);

            $step = ProvisioningException::STEP_MAIN_TENANT;
            $this->main->upsertTenant($tenantId, $request->tenantName, $request->slug, $reservation->tenantDbName, $request->identityId);
            $mainRowWritten = true;
            $this->runHook('after_tenant_row', self::STEP_BUSINESS_COLUMNS, $request, $reservation);

            $step = ProvisioningException::STEP_DATABASE;
            $reported = $this->databases->create($tenantId);
            if ($reported !== null && $reported !== $reservation->tenantDbName) {
                throw new ProvisioningException($step, 'The gateway database name does not match the reserved tenant_db_name; refusing to continue');
            }

            $step = ProvisioningException::STEP_SEED_PRIVILEGES;
            $ownerPrivileges = $this->tenantDb->seedPrivileges($tenantId, $this->privilegeGrants, $this->ownerRoleCode);
            if ($ownerPrivileges < 1) {
                throw new ProvisioningException($step, "The '{$this->ownerRoleCode}' role has no privileges in the tenant database; add it to config/tenant-privileges.php. Refusing to finish a tenant its owner could not use");
            }

            $step = ProvisioningException::STEP_OWNER_USER;
            $this->tenantDb->ensureUserWithRole($tenantId, $request->identityId, $request->displayName, $this->ownerRoleCode, null);
            $this->runHook('seed_tenant', self::STEP_SEED, $request, $reservation);

            $step = ProvisioningException::STEP_MAIN_MEMBERSHIP;
            $this->main->createMembership($reservation->membershipId, $tenantId, $request->identityId, true, null);

            $step = ProvisioningException::STEP_AUTH_PROVISIONED;
            $this->authority->markProvisioned($tenantId);
            $authProvisioned = true;

            $step = ProvisioningException::STEP_MAIN_PROVISIONED;
            $this->main->setTenantStatus($tenantId, MainTenantStore::STATUS_PROVISIONED);
        } catch (\Throwable $e) {
            $failure = $e instanceof ProvisioningException ? $e : new ProvisioningException($step, "Tenant provisioning failed at step $step", $e);
            $this->markFailed($tenantId, $failure, $mainRowWritten, $authProvisioned);
            throw $failure;
        }

        return new ProvisionResult($tenantId, $reservation->tenantDbName, $reservation->membershipId, false, $reservation->replay);
    }

    private function assertNamingMatches(TenantReservation $reservation): void
    {
        if ($this->platformSlug === null) {
            return;
        }
        $expected = TenantIdRule::databaseName($this->platformSlug, $this->tenantSchema, $reservation->platformTenantId);
        if ($expected !== $reservation->tenantDbName) {
            throw new ProvisioningException(ProvisioningException::STEP_RESERVE, 'The reserved tenant_db_name does not match this platform\'s database naming; refusing to create a database');
        }
    }

    private function runHook(string $key, string $label, ProvisionRequest $request, TenantReservation $reservation): void
    {
        $hook = $this->hooks[$key] ?? null;
        if ($hook === null) {
            return;
        }
        try {
            $hook($request, $reservation);
        } catch (\Throwable $e) {
            throw new ProvisioningException($label, "Platform hook $key failed", $e);
        }
    }

    private function markFailed(string $tenantId, ProvisioningException $failure, bool $mainRowWritten, bool $authProvisioned): void
    {
        if (!$authProvisioned) {
            try {
                $this->authority->markProvisioningFailed($tenantId, 'step:' . $failure->step);
            } catch (\Throwable $e) {
                self::log("provisioning-failed report to the authority did not go through for tenant $tenantId");
            }
        }
        // Once the authority says provisioned, only the final status write is left: leave the main row
        // as `provisioning` so the retry (which resumes every step) finishes it, instead of claiming failure.
        if ($mainRowWritten && !$authProvisioned) {
            try {
                $this->main->setTenantStatus($tenantId, MainTenantStore::STATUS_FAILED);
            } catch (\Throwable $e) {
                self::log("could not mark tenant $tenantId provisioning_failed in the main database");
            }
        }
        self::log("tenant $tenantId provisioning stopped at step {$failure->step}");
    }

    private static function log(string $message): void
    {
        if (function_exists('log_error')) {
            log_error('TenantProvisioningSequence: ' . $message);
        }
    }
}
