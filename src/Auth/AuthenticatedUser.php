<?php

namespace StoneScriptPHP\Auth;

/**
 * Authenticated User
 *
 * Represents the currently authenticated user extracted from JWT token.
 * Contains standard user claims that can be extended by applications.
 *
 * Supports both built-in auth (integer user_id) and external auth
 * (UUID identity_id from an external auth service).
 */
class AuthenticatedUser
{
    public function __construct(
        public readonly string|int $user_id,
        public readonly ?string $email = null,
        public readonly ?string $display_name = null,
        public readonly ?string $user_role = null,
        public readonly string|int|null $tenant_id = null,
        public readonly ?string $tenant_slug = null,
        public readonly ?string $platform_code = null,
        public readonly ?string $issuer_type = null,
        public readonly array $customClaims = []
    ) {
    }

    /**
     * Create from JWT payload array
     *
     * Supports multiple claim formats:
     * - Built-in auth: user_id (int), tenant_id (int)
     * - External auth: identity_id (UUID), tenant_id (string), tenant_slug, platform_code
     * - Standard JWT: sub (string)
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
            throw new \InvalidArgumentException('JWT payload must contain user_id, identity_id, or sub claim');
        }

        $email = $payload['email'] ?? null;
        $display_name = $payload['display_name'] ?? $payload['name'] ?? null;
        $user_role = $payload['user_role'] ?? $payload['role'] ?? null;
        $tenant_id = $payload['tenant_id'] ?? null;
        $tenant_slug = $payload['tenant_slug'] ?? null;
        $platform_code = $payload['platform_code'] ?? null;
        $issuer_type = $payload['issuer_type'] ?? null;

        // Store any additional claims not covered by standard fields
        $standardClaims = [
            'user_id', 'identity_id', 'local_user_id', 'sub',
            'email', 'display_name', 'name',
            'user_role', 'role',
            'tenant_id', 'tenant_slug', 'platform_code',
            'issuer_type',
            'iat', 'exp', 'iss', 'aud'
        ];
        $customClaims = array_diff_key($payload, array_flip($standardClaims));

        return new self(
            user_id: $user_id,
            email: $email,
            display_name: $display_name,
            user_role: $user_role,
            tenant_id: $tenant_id,
            tenant_slug: $tenant_slug,
            platform_code: $platform_code,
            issuer_type: $issuer_type,
            customClaims: $customClaims
        );
    }

    /**
     * Get a custom claim value
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
     * Check if user has a specific role
     *
     * @param string $role Role name to check
     * @return bool
     */
    public function hasRole(string $role): bool
    {
        return $this->user_role === $role;
    }

    /**
     * Check if user has any of the given roles
     *
     * @param array $roles Array of role names
     * @return bool
     */
    public function hasAnyRole(array $roles): bool
    {
        return in_array($this->user_role, $roles, true);
    }

    /**
     * Convert to array
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->user_id,
            'email' => $this->email,
            'display_name' => $this->display_name,
            'user_role' => $this->user_role,
            'tenant_id' => $this->tenant_id,
            'tenant_slug' => $this->tenant_slug,
            'platform_code' => $this->platform_code,
            ...$this->customClaims
        ];
    }
}
