<?php

namespace StoneScriptPHP\Auth;

/**
 * Identity Value Object
 *
 * Represents the authenticated identity from JWT token.
 * Contains core identity information like user ID, email, and role.
 */
class Identity
{
    /**
     * Create a new Identity instance
     *
     * @param int|string $id User/Identity ID
     * @param string|null $email User email
     * @param string|null $role User role (admin, user, etc.)
     * @param array $attributes Additional identity attributes
     */
    public function __construct(
        public readonly int|string $id,
        public readonly ?string $email = null,
        public readonly ?string $role = null,
        public readonly array $attributes = []
    ) {}

    /**
     * Create Identity from JWT claims
     *
     * Extracts identity information from JWT payload:
     * - identity_id, user_id, sub, or id
     * - email
     * - role
     * - Any other claims become attributes
     *
     * @param array $claims JWT claims
     * @return self|null Returns null if no identity information found
     */
    public static function fromJWT(array $claims): ?self
    {
        // Extract identity ID (try multiple claim names)
        $id = $claims['identity_id']
            ?? $claims['user_id']
            ?? $claims['sub']
            ?? $claims['id']
            ?? null;

        if ($id === null) {
            return null;
        }

        // Extract optional fields
        $email = $claims['email'] ?? null;
        $role = $claims['role'] ?? null;

        // Extract additional attributes (any claims not already processed)
        $attributes = [];
        $reservedKeys = ['identity_id', 'user_id', 'sub', 'id', 'email', 'role',
                        'tenant_id', 'tenant_uuid', 'tenant_slug', 'tenant_db',
                        'tid', 'tuid', 'tslug', 'tdb', 'local_user_id',
                        'iat', 'exp', 'nbf', 'iss', 'aud', 'jti'];

        foreach ($claims as $key => $value) {
            if (!in_array($key, $reservedKeys) && !str_starts_with($key, 'tenant_')) {
                $attributes[$key] = $value;
            }
        }

        return new self(
            id: is_numeric($id) ? (int) $id : $id,
            email: $email,
            role: $role,
            attributes: $attributes
        );
    }

    /**
     * Get attribute value by key
     *
     * @param string $key Attribute key
     * @param mixed $default Default value if key doesn't exist
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Check if identity has specific attribute
     *
     * @param string $key Attribute key
     * @return bool
     */
    public function has(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    /**
     * Convert identity to array representation
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'role' => $this->role,
            'attributes' => $this->attributes
        ];
    }

    /**
     * Convert identity to JSON
     *
     * @return string
     */
    public function toJson(): string
    {
        return json_encode($this->toArray());
    }
}
