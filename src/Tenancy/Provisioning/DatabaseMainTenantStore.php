<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tenancy\Provisioning;

use StoneScriptPHP\Database;

/**
 * MainTenantStore and BuiltinRequestStore over the SQL functions `php stone generate tenancy` writes
 * into the platform main database (tenancy_* functions). All calls go through Database::fn, i.e. the
 * gateway; nothing talks to PostgreSQL directly.
 */
final class DatabaseMainTenantStore implements MainTenantStore, BuiltinRequestStore
{
    /** @var callable(string,array):array */
    private $fn;

    /** @param callable(string,array):array|null $fn Database::fn-shaped caller (injectable for tests). */
    public function __construct(?callable $fn = null)
    {
        $this->fn = $fn ?? Database::fn(...);
    }

    public function getTenantStatus(string $tenantId): ?string
    {
        $rows = ($this->fn)('tenancy_get_tenant_status', [$tenantId]);
        $status = self::col($rows[0] ?? [], 'status');
        return is_string($status) && $status !== '' ? $status : null;
    }

    public function upsertTenant(string $tenantId, string $name, ?string $slug, string $tenantDbName, string $createdByIdentityId): void
    {
        ($this->fn)('tenancy_upsert_tenant', [$tenantId, $name, $slug, $tenantDbName, $createdByIdentityId]);
    }

    public function setTenantStatus(string $tenantId, string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Unknown tenant_provisioning_status');
        }
        ($this->fn)('tenancy_set_tenant_status', [$tenantId, $status]);
    }

    public function createMembership(string $membershipId, string $tenantId, string $identityId, bool $isTenantOwner, ?string $invitedByIdentityId): void
    {
        $rows = ($this->fn)('tenancy_create_membership', [$membershipId, $tenantId, $identityId, $isTenantOwner, $invitedByIdentityId]);
        $stored = self::col($rows[0] ?? [], 'id');
        if (is_string($stored) && $stored !== $membershipId) {
            // The identity already has a membership here under another id: the ids must be the authority's.
            throw new \RuntimeException('Membership id does not match the one already stored for this identity and tenant');
        }
    }

    public function listIdentityMemberships(string $identityId): array
    {
        $out = [];
        foreach (($this->fn)('tenancy_get_identity_memberships', [$identityId]) as $row) {
            $slug = self::col($row, 'tenant_slug');
            $out[] = [
                'membership_id' => (string) self::col($row, 'membership_id'),
                'tenant_id' => (string) self::col($row, 'tenant_id'),
                'tenant_name' => (string) self::col($row, 'tenant_name'),
                'tenant_slug' => is_string($slug) && $slug !== '' ? $slug : null,
                'tenant_db_name' => (string) self::col($row, 'tenant_db_name'),
                'is_tenant_owner' => in_array(self::col($row, 'is_tenant_owner'), [true, 1, '1', 't', 'true'], true),
            ];
        }
        return $out;
    }

    public function find(string $idempotencyKey): ?array
    {
        $row = (($this->fn)('tenancy_find_request', [$idempotencyKey]))[0] ?? null;
        if ($row === null) {
            return null;
        }
        return [
            'identity_id' => (string) self::col($row, 'identity_id'),
            'tenant_id' => (string) self::col($row, 'tenant_id'),
            'membership_id' => (string) self::col($row, 'membership_id'),
            'tenant_db_name' => (string) self::col($row, 'tenant_db_name'),
        ];
    }

    public function save(string $idempotencyKey, string $identityId, string $tenantId, string $membershipId, string $tenantDbName): array
    {
        $row = (($this->fn)('tenancy_save_request', [$idempotencyKey, $identityId, $tenantId, $membershipId, $tenantDbName]))[0] ?? null;
        if ($row === null) {
            throw new \RuntimeException('tenancy_save_request returned no row');
        }
        return [
            'identity_id' => (string) self::col($row, 'identity_id'),
            'tenant_id' => (string) self::col($row, 'tenant_id'),
            'membership_id' => (string) self::col($row, 'membership_id'),
            'tenant_db_name' => (string) self::col($row, 'tenant_db_name'),
        ];
    }

    public function findMembership(string $tenantId, string $identityId): ?array
    {
        $row = (($this->fn)('tenancy_find_membership', [$tenantId, $identityId]))[0] ?? null;
        if ($row === null) {
            return null;
        }
        return ['id' => (string) self::col($row, 'id'), 'status' => (string) self::col($row, 'status')];
    }

    public function mirrorMembershipStatus(string $membershipId, string $status): void
    {
        if (!in_array($status, ['active', 'suspended', 'removed'], true)) {
            throw new \InvalidArgumentException('Unknown membership status');
        }
        $rows = ($this->fn)('tenancy_mirror_membership_status', [$membershipId, $status]);
        if ((int) (self::col($rows[0] ?? [], 'updated') ?? 0) < 1) {
            throw new \RuntimeException('No membership row to mirror the status into');
        }
    }

    public function recordMirrorDrift(string $membershipId, string $authStatus): void
    {
        ($this->fn)('tenancy_record_mirror_drift', [$membershipId, $authStatus]);
    }

    public function clearMirrorDrift(string $membershipId): void
    {
        ($this->fn)('tenancy_clear_mirror_drift', [$membershipId]);
    }

    public function setMembershipStatus(string $membershipId, string $status): void
    {
        if (!in_array($status, ['active', 'suspended', 'removed'], true)) {
            throw new \InvalidArgumentException('Unknown membership status');
        }
        ($this->fn)('tenancy_set_membership_status', [$membershipId, $status]);
    }

    /** Gateway rows carry `o_`-prefixed output columns; tolerate the bare name too. */
    private static function col(array $row, string $name): mixed
    {
        return $row['o_' . $name] ?? $row[$name] ?? null;
    }
}
