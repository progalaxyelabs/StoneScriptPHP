<?php

namespace Framework\Auth;

/**
 * Authenticated User
 *
 * Represents the currently authenticated user extracted from JWT token.
 * Contains standard user claims that can be extended by applications.
 */
class AuthenticatedUser
{
    public function __construct(
        public readonly int $user_id,
        public readonly ?string $email = null,
        public readonly ?string $display_name = null,
        public readonly ?string $user_role = null,
        public readonly ?int $tenant_id = null,
        public readonly array $customClaims = []
    ) {
    }

    /**
     * Create from JWT payload array
     *
     * @param array $payload JWT token payload
     * @return self
     */
    public static function fromPayload(array $payload): self
    {
        // Extract standard claims
        $user_id = $payload['user_id'] ?? $payload['sub'] ?? null;

        if ($user_id === null) {
            throw new \InvalidArgumentException('JWT payload must contain user_id or sub claim');
        }

        $email = $payload['email'] ?? null;
        $display_name = $payload['display_name'] ?? $payload['name'] ?? null;
        $user_role = $payload['user_role'] ?? $payload['role'] ?? null;
        $tenant_id = $payload['tenant_id'] ?? null;

        // Store any additional claims
        $standardClaims = ['user_id', 'sub', 'email', 'display_name', 'name', 'user_role', 'role', 'tenant_id', 'iat', 'exp', 'iss', 'aud'];
        $customClaims = array_diff_key($payload, array_flip($standardClaims));

        return new self(
            user_id: (int) $user_id,
            email: $email,
            display_name: $display_name,
            user_role: $user_role,
            tenant_id: $tenant_id ? (int) $tenant_id : null,
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
            ...$this->customClaims
        ];
    }
}
