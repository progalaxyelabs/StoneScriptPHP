<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tenancy\Provisioning;

use StoneScriptPHP\Tenancy\TenantIdRule;

/**
 * MembershipAuthority for standalone platforms with local identities (AUTH_MODE=builtin): the
 * platform main database is the source of tenants and memberships, ids are generated here, and the
 * same provisioning sequence runs against it. No outbound call; no token needed.
 *
 * The tenant row's status is written by the sequence itself (MainTenantStore), so markProvisioned /
 * markProvisioningFailed have nothing extra to record here.
 */
final class BuiltinMembershipAuthority implements MembershipAuthority
{
    /** @var callable():string */
    private $uuid;

    public function __construct(
        private readonly string $platformSlug,
        private readonly MainTenantStore $main,
        private readonly BuiltinRequestStore $requests,
        ?callable $uuidGenerator = null,
        private readonly string $tenantSchema = 'tenant',
    ) {
        $this->uuid = $uuidGenerator ?? Uuid::v4(...);
    }

    public function reserveTenant(string $tenantName, string $idempotencyKey, string $identityId, #[\SensitiveParameter] ?string $userToken, ?string $tenantSlug = null): TenantReservation
    {
        if ($idempotencyKey === '' || $identityId === '') {
            throw new ProvisioningException(ProvisioningException::STEP_RESERVE, 'idempotency key and identity id are required');
        }
        try {
            $existing = $this->requests->find($idempotencyKey);
            if ($existing !== null) {
                if ($existing['identity_id'] !== $identityId) {
                    // The key belongs to another identity: never hand that tenant to this caller.
                    throw new ProvisioningException(ProvisioningException::STEP_RESERVE, 'Idempotency key already used by a different identity');
                }
                return new TenantReservation($existing['tenant_id'], $existing['tenant_db_name'], $existing['membership_id'], true);
            }

            $tenantId = ($this->uuid)();
            $membershipId = ($this->uuid)();
            $dbName = TenantIdRule::databaseName($this->platformSlug, $this->tenantSchema, $tenantId);
            // Two concurrent first calls can both get here: the store keeps the FIRST row and returns the winner,
            // so every caller ends up with the same ids.
            $winner = $this->requests->save($idempotencyKey, $identityId, $tenantId, $membershipId, $dbName);
            if ($winner['identity_id'] !== $identityId) {
                throw new ProvisioningException(ProvisioningException::STEP_RESERVE, 'Idempotency key already used by a different identity');
            }
            $lost = $winner['tenant_id'] !== $tenantId;
            return new TenantReservation($winner['tenant_id'], $winner['tenant_db_name'], $winner['membership_id'], $lost);
        } catch (ProvisioningException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ProvisioningException(ProvisioningException::STEP_RESERVE, 'Local tenant reservation failed', $e);
        }
    }

    public function markProvisioned(string $tenantId): void
    {
        // Nothing to record: the sequence writes tenants.tenant_provisioning_status next.
    }

    public function markProvisioningFailed(string $tenantId, string $reason): void
    {
        // Nothing to record: the sequence writes tenants.tenant_provisioning_status itself.
    }

    public function verifyMembership(string $identityId, #[\SensitiveParameter] ?string $userToken, string $tenantId, ?string $tenantSlug = null): ?array
    {
        foreach ($this->main->listIdentityMemberships($identityId) as $row) {
            if ($row['tenant_id'] === $tenantId) {
                return ($tenantSlug === null || $tenantSlug === '' || $row['tenant_slug'] === $tenantSlug) ? $row : null;
            }
        }
        return null;
    }

    public function addMember(string $tenantId, string $identityId, string $idempotencyKey, ?string $invitedByIdentityId = null): string
    {
        $status = $this->main->getTenantStatus($tenantId);
        if ($status !== MainTenantStore::STATUS_PROVISIONED) {
            throw new ProvisioningException(ProvisioningException::STEP_ADD_MEMBER, 'Tenant is not provisioned; members can only be added to a provisioned tenant');
        }
        // An identity that already belongs to the tenant keeps its membership id; one that is not active is
        // never silently reactivated by an invite.
        $current = $this->main->findMembership($tenantId, $identityId);
        if ($current !== null && $current['status'] !== 'active') {
            throw new ProvisioningException(ProvisioningException::STEP_ADD_MEMBER, 'The membership already exists with status "' . $current['status'] . '"; reactivate it explicitly instead of inviting again');
        }
        $existing = $this->requests->find('member:' . $idempotencyKey);
        if ($existing !== null) {
            if ($existing['tenant_id'] !== $tenantId || $existing['identity_id'] !== $identityId) {
                throw new ProvisioningException(ProvisioningException::STEP_ADD_MEMBER, 'Idempotency key already used for a different member');
            }
            return $existing['membership_id'];
        }
        $membershipId = $current['id'] ?? ($this->uuid)();
        $winner = $this->requests->save('member:' . $idempotencyKey, $identityId, $tenantId, $membershipId, '');
        if ($winner['tenant_id'] !== $tenantId || $winner['identity_id'] !== $identityId) {
            throw new ProvisioningException(ProvisioningException::STEP_ADD_MEMBER, 'Idempotency key already used for a different member');
        }
        return $winner['membership_id'];
    }

    public function setMembershipStatus(string $membershipId, string $status): void
    {
        if (!in_array($status, ['active', 'suspended', 'removed'], true)) {
            throw new ProvisioningException('set_membership_status', 'status must be active, suspended or removed');
        }
        try {
            $this->main->setMembershipStatus($membershipId, $status);
        } catch (\Throwable $e) {
            throw new ProvisioningException('set_membership_status', 'Could not change the membership status', $e);
        }
    }

    public function listMemberships(string $identityId, #[\SensitiveParameter] ?string $userToken): array
    {
        return $this->main->listIdentityMemberships($identityId);
    }
}
