<?php

namespace StoneScriptPHP\Auth;

/**
 * Membership Value Object
 *
 * Represents the relationship between an identity and a tenant.
 * Contains information about the user's role and permissions within a specific tenant.
 */
class Membership
{
    /**
     * Create a new Membership instance
     *
     * @param int|string $identityId Identity/User ID
     * @param int|string $tenantId Tenant ID
     * @param string|null $role User's role in this tenant
     * @param int|string|null $localUserId Tenant-specific user ID (local to tenant's database)
     * @param array $permissions User permissions in this tenant
     * @param array $metadata Additional membership metadata
     */
    public function __construct(
        public readonly int|string $identityId,
        public readonly int|string $tenantId,
        public readonly ?string $role = null,
        public readonly int|string|null $localUserId = null,
        public readonly array $permissions = [],
        public readonly array $metadata = []
    ) {}

    /**
     * Create Membership from JWT claims
     *
     * Extracts membership information from JWT payload:
     * - identity_id, user_id, sub, or id (identity)
     * - tenant_id or tid (tenant)
     * - role
     * - local_user_id or luid (tenant-specific user ID)
     * - permissions (array)
     *
     * @param array $claims JWT claims
     * @return self|null Returns null if missing required identity or tenant info
     */
    public static function fromJWT(array $claims): ?self
    {
        // Extract identity ID
        $identityId = $claims['identity_id']
            ?? $claims['user_id']
            ?? $claims['sub']
            ?? $claims['id']
            ?? null;

        // Extract tenant ID
        $tenantId = $claims['tenant_id'] ?? $claims['tid'] ?? null;

        // Must have both identity and tenant to form a membership
        if ($identityId === null || $tenantId === null) {
            return null;
        }

        // Extract optional fields
        $role = $claims['role'] ?? null;
        $localUserId = $claims['local_user_id'] ?? $claims['luid'] ?? null;
        $permissions = $claims['permissions'] ?? [];

        // Ensure permissions is an array
        if (!is_array($permissions)) {
            $permissions = [$permissions];
        }

        // Extract metadata (membership-specific claims)
        $metadata = [];
        $reservedKeys = ['identity_id', 'user_id', 'sub', 'id', 'email', 'role',
                        'tenant_id', 'tenant_uuid', 'tenant_slug', 'tenant_db',
                        'tid', 'tuid', 'tslug', 'tdb', 'local_user_id', 'luid',
                        'permissions', 'iat', 'exp', 'nbf', 'iss', 'aud', 'jti'];

        foreach ($claims as $key => $value) {
            if (!in_array($key, $reservedKeys) &&
                !str_starts_with($key, 'tenant_') &&
                str_starts_with($key, 'membership_')) {
                $metadata[substr($key, 11)] = $value; // Remove 'membership_' prefix
            }
        }

        return new self(
            identityId: is_numeric($identityId) ? (int) $identityId : $identityId,
            tenantId: is_numeric($tenantId) ? (int) $tenantId : $tenantId,
            role: $role,
            localUserId: $localUserId !== null && is_numeric($localUserId) ? (int) $localUserId : $localUserId,
            permissions: $permissions,
            metadata: $metadata
        );
    }

    /**
     * Check if membership has a specific permission
     *
     * @param string $permission Permission to check
     * @return bool
     */
    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions);
    }

    /**
     * Check if membership has any of the given permissions
     *
     * @param array $permissions Permissions to check
     * @return bool
     */
    public function hasAnyPermission(array $permissions): bool
    {
        return !empty(array_intersect($this->permissions, $permissions));
    }

    /**
     * Check if membership has all of the given permissions
     *
     * @param array $permissions Permissions to check
     * @return bool
     */
    public function hasAllPermissions(array $permissions): bool
    {
        return empty(array_diff($permissions, $this->permissions));
    }

    /**
     * Get metadata value by key
     *
     * @param string $key Metadata key
     * @param mixed $default Default value if key doesn't exist
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Check if membership has specific metadata
     *
     * @param string $key Metadata key
     * @return bool
     */
    public function has(string $key): bool
    {
        return isset($this->metadata[$key]);
    }

    /**
     * Convert membership to array representation
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'identity_id' => $this->identityId,
            'tenant_id' => $this->tenantId,
            'role' => $this->role,
            'local_user_id' => $this->localUserId,
            'permissions' => $this->permissions,
            'metadata' => $this->metadata
        ];
    }

    /**
     * Convert membership to JSON
     *
     * @return string
     */
    public function toJson(): string
    {
        return json_encode($this->toArray());
    }
}
