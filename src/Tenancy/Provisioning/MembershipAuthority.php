<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tenancy\Provisioning;

/**
 * The source of truth for tenant ids and memberships.
 *
 * External mode: progalaxyelabs auth (v2 endpoints) generates every id and the platform stores the
 * same ids. Builtin mode: the platform's own main database does the same job (standalone
 * deployments with local identities). The provisioning sequence is identical in both; only this
 * authority differs. Neither ever holds a role: roles and privileges live in the tenant database.
 */
interface MembershipAuthority
{
    /**
     * Reserve a tenant and its owner membership in ONE step. Idempotent on $idempotencyKey: a retry
     * returns the SAME ids (never a new uuid).
     *
     * @throws ProvisioningException
     */
    public function reserveTenant(
        string $tenantName,
        string $idempotencyKey,
        string $identityId,
        #[\SensitiveParameter] ?string $userToken,
        ?string $tenantSlug = null,
    ): TenantReservation;

    /** Mark the tenant provisioned. Idempotent. @throws ProvisioningException */
    public function markProvisioned(string $tenantId): void;

    /** Mark the tenant provisioning-failed (best effort for callers). @throws ProvisioningException */
    public function markProvisioningFailed(string $tenantId, string $reason): void;

    /**
     * Add a member to a provisioned tenant of THIS platform. Idempotent on $idempotencyKey.
     * Returns the membership id.
     *
     * @throws ProvisioningException
     */
    public function addMember(string $tenantId, string $identityId, string $idempotencyKey, ?string $invitedByIdentityId = null): string;

    /**
     * Change a membership's status (active, suspended, removed). The authority keeps the rule that a tenant never
     * loses its last active owner (cannot_suspend_tenant_owner). Who may do this is the platform's decision.
     *
     * @throws ProvisioningException
     */
    public function setMembershipStatus(string $membershipId, string $status): void;

    /**
     * Does the identity hold an active membership on this platform's provisioned tenant (select-tenant /
     * exchange check)? A slug is compared only when supplied. Null when there is none.
     *
     * @return array{membership_id:string,tenant_id:string,tenant_name:string,tenant_slug:?string,tenant_db_name:string,is_tenant_owner:bool}|null
     * @throws ProvisioningException
     */
    public function verifyMembership(string $identityId, #[\SensitiveParameter] ?string $userToken, string $tenantId, ?string $tenantSlug = null): ?array;

    /**
     * Memberships of an identity on this platform, provisioned tenants only. Slug may be null.
     *
     * @return list<array{membership_id:string,tenant_id:string,tenant_name:string,tenant_slug:?string,tenant_db_name:string,is_tenant_owner:bool}>
     * @throws ProvisioningException
     */
    public function listMemberships(string $identityId, #[\SensitiveParameter] ?string $userToken): array;
}
