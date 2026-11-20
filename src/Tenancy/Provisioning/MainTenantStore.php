<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tenancy\Provisioning;

/**
 * The platform main database's side of tenancy: the `tenants` core columns and `tenant_memberships`.
 * All writes are idempotent so a retry with the same idempotency key can resume.
 */
interface MainTenantStore
{
    public const STATUS_PROVISIONING = 'provisioning';
    public const STATUS_PROVISIONED = 'provisioned';
    public const STATUS_FAILED = 'provisioning_failed';
    public const STATUS_DEPROVISIONING = 'deprovisioning';
    public const STATUS_DEPROVISIONED = 'deprovisioned';

    public const STATUSES = [
        self::STATUS_PROVISIONING,
        self::STATUS_PROVISIONED,
        self::STATUS_FAILED,
        self::STATUS_DEPROVISIONING,
        self::STATUS_DEPROVISIONED,
    ];

    /** Current tenant_provisioning_status, or null when the tenant row does not exist. */
    public function getTenantStatus(string $tenantId): ?string;

    /**
     * Insert the tenant as `provisioning`, or (existing row) move `provisioning_failed` back to
     * `provisioning`. A `provisioned` tenant is left untouched.
     */
    public function upsertTenant(string $tenantId, string $name, ?string $slug, string $tenantDbName, string $createdByIdentityId): void;

    /** Set tenant_provisioning_status (and provisioned_at when provisioned). Only the five vocabulary values. */
    public function setTenantStatus(string $tenantId, string $status): void;

    /** Insert the membership with the id auth (or the builtin authority) generated. Idempotent on (identity, tenant). */
    public function createMembership(string $membershipId, string $tenantId, string $identityId, bool $isTenantOwner, ?string $invitedByIdentityId): void;

    /** The identity's membership of the tenant in any status, or null. @return array{id:string,status:string}|null */
    public function findMembership(string $tenantId, string $identityId): ?array;

    /**
     * Change a membership's status. A tenant never loses its last active owner (throws).
     *
     * @throws \RuntimeException
     */
    public function setMembershipStatus(string $membershipId, string $status): void;

    /**
     * Mirror a status the AUTH service already confirmed into the platform row, with NO last-owner rule (auth is the
     * source of truth and has enforced it). Throws when no such row exists here.
     *
     * @throws \RuntimeException
     */
    public function mirrorMembershipStatus(string $membershipId, string $status): void;

    /** Remember that auth confirmed $status for the membership but the mirror write failed (ids and status only). */
    public function recordMirrorDrift(string $membershipId, string $authStatus): void;

    /** Forget a recorded drift (the mirror write now succeeded). */
    public function clearMirrorDrift(string $membershipId): void;

    /**
     * Provisioned tenants an identity belongs to (active memberships only).
     *
     * @return list<array{membership_id:string,tenant_id:string,tenant_name:string,tenant_slug:?string,tenant_db_name:string,is_tenant_owner:bool}>
     */
    public function listIdentityMemberships(string $identityId): array;
}
