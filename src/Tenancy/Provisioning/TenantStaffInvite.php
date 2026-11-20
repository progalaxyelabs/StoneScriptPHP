<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tenancy\Provisioning;

/**
 * Adding a member to an existing tenant (design 4.3). WHO may invite is the platform's decision (its
 * tenant-DB roles) and is checked by the caller before this runs; this class only runs the writes:
 *
 *   1. authority adds the membership (it checks the tenant belongs to this platform and is provisioned)
 *   2. tenant DB: `users` + `user_roles` row for the identity and role
 *   3. main `tenant_memberships` row (same membership id)
 *
 * Every step is idempotent on the idempotency key, so a failed call is simply repeated. A role code
 * is a lower-case code; an unknown role later grants nothing (see TenantRoleResolver).
 */
final class TenantStaffInvite
{
    public function __construct(
        private readonly MembershipAuthority $authority,
        private readonly MainTenantStore $main,
        private readonly TenantDbSeeder $tenantDb,
    ) {
    }

    /** @return string the membership id */
    public function add(string $tenantId, string $identityId, string $roleCode, ?string $invitedByIdentityId, string $idempotencyKey, ?string $displayName = null): string
    {
        $invitedByIdentityId = ($invitedByIdentityId === null || $invitedByIdentityId === '') ? null : $invitedByIdentityId;
        $step = ProvisioningException::STEP_ADD_MEMBER;
        if (!Uuid::isValid($tenantId) || $identityId === '' || $idempotencyKey === '') {
            throw new ProvisioningException($step, 'tenant id, identity id and idempotency key are required');
        }
        if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $roleCode) !== 1) {
            throw new ProvisioningException($step, 'role code must be a lower-case code');
        }

        $membershipId = $this->authority->addMember($tenantId, $identityId, $idempotencyKey, $invitedByIdentityId);
        try {
            $step = ProvisioningException::STEP_OWNER_USER;
            $this->tenantDb->ensureUserWithRole($tenantId, $identityId, $displayName, $roleCode, $invitedByIdentityId);
            $step = ProvisioningException::STEP_MAIN_MEMBERSHIP;
            $this->main->createMembership($membershipId, $tenantId, $identityId, false, $invitedByIdentityId);
        } catch (ProvisioningException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ProvisioningException($step, "Adding the member failed at step $step", $e);
        }
        return $membershipId;
    }
}
