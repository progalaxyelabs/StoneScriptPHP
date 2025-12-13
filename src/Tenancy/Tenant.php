<?php

namespace StoneScriptPHP\Tenancy;

/**
 * Tenant Value Object
 *
 * Represents a tenant in a multi-tenant application.
 * Supports multiple tenancy strategies:
 * - Per-tenant database isolation
 * - Shared database with tenant_id filtering
 * - Hybrid approaches
 */
class Tenant
{
    /**
     * Create a new Tenant instance
     *
     * @param int|string $id Tenant ID (can be integer or string/UUID)
     * @param string|null $uuid Tenant UUID (for distributed systems)
     * @param string|null $slug Tenant slug (for URL-based resolution)
     * @param string|null $dbName Database name for per-tenant DB strategy
     * @param array $metadata Additional tenant metadata (settings, features, etc.)
     */
    public function __construct(
        public readonly int|string $id,
        public readonly ?string $uuid = null,
        public readonly ?string $slug = null,
        public readonly ?string $dbName = null,
        public readonly array $metadata = []
    ) {}

    /**
     * Create Tenant from JWT token payload
     *
     * Extracts tenant information from standard JWT claims:
     * - tenant_id or tid
     * - tenant_uuid or tuid
     * - tenant_slug or tslug
     * - tenant_db or tdb (database name)
     *
     * @param array $payload JWT payload containing tenant claims
     * @return self
     * @throws \InvalidArgumentException If required tenant_id is missing
     */
    public static function fromJWT(array $payload): self
    {
        // Extract tenant ID (required)
        $id = $payload['tenant_id'] ?? $payload['tid'] ?? null;

        if ($id === null) {
            throw new \InvalidArgumentException(
                'JWT payload must contain tenant_id or tid claim'
            );
        }

        // Extract optional fields
        $uuid = $payload['tenant_uuid'] ?? $payload['tuid'] ?? null;
        $slug = $payload['tenant_slug'] ?? $payload['tslug'] ?? null;
        $dbName = $payload['tenant_db'] ?? $payload['tdb'] ?? null;

        // Derive database name from UUID if not explicitly provided
        // Follows medstoreapp pattern: tenant_{uuid_without_hyphens}
        if (!$dbName && $uuid) {
            $dbName = 'tenant_' . str_replace('-', '', $uuid);
        }

        // Extract metadata (any other tenant_* claims)
        $metadata = [];
        foreach ($payload as $key => $value) {
            if (str_starts_with($key, 'tenant_') &&
                !in_array($key, ['tenant_id', 'tenant_uuid', 'tenant_slug', 'tenant_db'])) {
                $metadata[substr($key, 7)] = $value; // Remove 'tenant_' prefix
            }
        }

        return new self(
            id: is_numeric($id) ? (int) $id : $id,
            uuid: $uuid,
            slug: $slug,
            dbName: $dbName,
            metadata: $metadata
        );
    }

    /**
     * Create Tenant from database row
     *
     * Expected columns:
     * - id (required)
     * - uuid
     * - slug
     * - db_name or database_name
     * - Any other columns become metadata
     *
     * @param array $row Database row
     * @return self
     * @throws \InvalidArgumentException If required id is missing
     */
    public static function fromDatabase(array $row): self
    {
        if (!isset($row['id'])) {
            throw new \InvalidArgumentException('Database row must contain id column');
        }

        $id = is_numeric($row['id']) ? (int) $row['id'] : $row['id'];
        $uuid = $row['uuid'] ?? null;
        $slug = $row['slug'] ?? null;
        $dbName = $row['db_name'] ?? $row['database_name'] ?? $row['biz_db_name'] ?? null;

        // Derive database name from UUID if not explicitly provided
        if (!$dbName && $uuid) {
            $dbName = 'tenant_' . str_replace('-', '', $uuid);
        }

        // All other columns become metadata
        $metadata = [];
        $reservedKeys = ['id', 'uuid', 'slug', 'db_name', 'database_name', 'biz_db_name'];
        foreach ($row as $key => $value) {
            if (!in_array($key, $reservedKeys)) {
                $metadata[$key] = $value;
            }
        }

        return new self(
            id: $id,
            uuid: $uuid,
            slug: $slug,
            dbName: $dbName,
            metadata: $metadata
        );
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
     * Check if tenant has specific metadata key
     *
     * @param string $key Metadata key
     * @return bool
     */
    public function has(string $key): bool
    {
        return isset($this->metadata[$key]);
    }

    /**
     * Convert tenant to array representation
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'db_name' => $this->dbName,
            'metadata' => $this->metadata
        ];
    }

    /**
     * Convert tenant to JSON
     *
     * @return string
     */
    public function toJson(): string
    {
        return json_encode($this->toArray());
    }
}
