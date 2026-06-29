<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth;

/**
 * Authenticated User
 *
 * Represents the context extracted from a validated JWT token.
 *
 * ## Passport vs Card (framework-spec.md §6)
 *
 * - **Passport** (identity JWT from auth service): has user_id/identity_id, email.
 *   tenant_id and role_id are NULL — a passport is always tenant-less.
 * - **Card** (platform JWT issued by exchange): has user_id/identity_id, tenant_id,
 *   AND role_id (a single active role). All three are present together.
 *
 * Code that needs tenant context should check `$user->tenant_id !== null` to confirm
 * it has a card, not a passport.
 *
 * Identity IDs are always UUID strings (from external auth service).
 */
class AuthenticatedUser
{
    public function __construct(
        /** Identity ID — always present (UUID, from auth service). Alias: user_id. */
        public readonly string $user_id,
        public readonly ?string $email = null,
        public readonly ?string $display_name = null,

        /**
         * Active role on the card (single string, e.g. 'owner', 'cashier').
         * NULL for passport tokens — they carry no role.
         * Use role_id for card-model checks; user_role is a legacy alias.
         */
        public readonly ?string $role_id = null,

        /**
         * @deprecated Alias for role_id. Use role_id for new code.
         *   Kept because legacy platform code reads user_role from this object.
         */
        public readonly ?string $user_role = null,

        /** Tenant ID — present on cards, NULL on passports. */
        public readonly ?string $tenant_id = null,
        public readonly ?string $tenant_slug = null,
        public readonly ?string $platform_code = null,
        public readonly ?string $issuer_type = null,
        public readonly array $customClaims = []
    ) {
    }

    /**
     * Create from JWT payload array.
     *
     * Supports both the card model (role_id claim, single string) and the
     * legacy platform token (roles[] array, reads first element as user_role).
     *
     * @param array $payload JWT token payload
     * @return self
     */
    public static function fromPayload(array $payload): self
    {
        // Extract user identifier (supports multiple claim names)
        $user_id = $payload['user_id']
            ?? $payload['identity_id']
            ?? $payload['sub']
            ?? $payload['local_user_id']
            ?? null;

        if ($user_id === null) {
            throw new \InvalidArgumentException(
                'JWT payload must contain user_id, identity_id, or sub claim'
            );
        }

        $email        = $payload['email'] ?? null;
        $display_name = $payload['display_name'] ?? $payload['name'] ?? null;
        $tenant_id    = $payload['tenant_id'] ?? null;
        $tenant_slug  = $payload['tenant_slug'] ?? null;
        $platform_code = $payload['platform_code'] ?? null;
        $issuer_type  = $payload['issuer_type'] ?? null;

        // Card model: role_id (single string).
        // Legacy: roles[] array — read first element; or scalar user_role/role.
        $role_id = $payload['role_id'] ?? null;

        $user_role = $role_id
            ?? $payload['user_role']
            ?? $payload['role']
            ?? (is_array($payload['roles'] ?? null) ? ($payload['roles'][0] ?? null) : null)
            ?? null;

        // role_id is the canonical claim; fall back to user_role for compat.
        if ($role_id === null) {
            $role_id = $user_role;
        }

        // Store any additional claims not covered by standard fields.
        $standardClaims = [
            'user_id', 'identity_id', 'local_user_id', 'sub',
            'email', 'display_name', 'name',
            'role_id', 'user_role', 'role', 'roles',
            'tenant_id', 'tenant_slug', 'platform_code',
            'issuer_type', 'token_type',
            'iat', 'exp', 'iss', 'aud',
        ];
        $customClaims = array_diff_key($payload, array_flip($standardClaims));

        return new self(
            user_id: $user_id,
            email: $email,
            display_name: $display_name,
            role_id: $role_id,
            user_role: $user_role,
            tenant_id: $tenant_id,
            tenant_slug: $tenant_slug,
            platform_code: $platform_code,
            issuer_type: $issuer_type,
            customClaims: $customClaims
        );
    }

    /**
     * Get a custom claim value.
     *
     * @param string $key Claim name
     * @param mixed $default Default value if claim doesn't exist
     * @return mixed
     */
    public function getClaim(string $key, mixed $default = null): mixed
    {
        return $this->customClaims[$key] ?? $default;
    }

    /**
     * Check if this token is a card (has tenant + role context).
     * Returns false for passports (tenant-less identity tokens).
     */
    public function isCard(): bool
    {
        return $this->tenant_id !== null;
    }

    /**
     * Check if the active role equals the given role.
     *
     * @param string $role Role name to check
     * @return bool
     */
    public function hasRole(string $role): bool
    {
        return $this->role_id === $role || $this->user_role === $role;
    }

    /**
     * Check if the active role is one of the given roles.
     *
     * @param array $roles Array of role names
     * @return bool
     */
    public function hasAnyRole(array $roles): bool
    {
        return in_array($this->role_id ?? $this->user_role, $roles, true);
    }

    /**
     * Convert to array.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'user_id'      => $this->user_id,
            'email'        => $this->email,
            'display_name' => $this->display_name,
            'role_id'      => $this->role_id,
            'user_role'    => $this->user_role,
            'tenant_id'    => $this->tenant_id,
            'tenant_slug'  => $this->tenant_slug,
            'platform_code' => $this->platform_code,
            ...$this->customClaims,
        ];
    }
}
