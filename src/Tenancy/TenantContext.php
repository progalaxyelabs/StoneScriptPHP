<?php

namespace Framework\Tenancy;

/**
 * Tenant Context - Global Singleton
 *
 * Stores the current tenant for the request lifecycle.
 * Similar to AuthContext, this provides global access to the current tenant
 * after it has been resolved by TenantMiddleware.
 *
 * Usage:
 *   TenantContext::setTenant($tenant);
 *   $currentTenant = TenantContext::getTenant();
 *   $tenantId = TenantContext::id();
 */
class TenantContext
{
    /**
     * Current tenant instance
     */
    private static ?Tenant $tenant = null;

    /**
     * Set the current tenant
     *
     * @param Tenant $tenant
     * @return void
     */
    public static function setTenant(Tenant $tenant): void
    {
        self::$tenant = $tenant;
    }

    /**
     * Get the current tenant
     *
     * @return Tenant|null
     */
    public static function getTenant(): ?Tenant
    {
        return self::$tenant;
    }

    /**
     * Get current tenant ID
     *
     * @return int|string|null
     */
    public static function id(): int|string|null
    {
        return self::$tenant?->id;
    }

    /**
     * Get current tenant UUID
     *
     * @return string|null
     */
    public static function uuid(): ?string
    {
        return self::$tenant?->uuid;
    }

    /**
     * Get current tenant slug
     *
     * @return string|null
     */
    public static function slug(): ?string
    {
        return self::$tenant?->slug;
    }

    /**
     * Get current tenant database name
     *
     * @return string|null
     */
    public static function dbName(): ?string
    {
        return self::$tenant?->dbName;
    }

    /**
     * Check if tenant context is set
     *
     * @return bool
     */
    public static function check(): bool
    {
        return self::$tenant !== null;
    }

    /**
     * Check if no tenant context is set (guest)
     *
     * @return bool
     */
    public static function guest(): bool
    {
        return self::$tenant === null;
    }

    /**
     * Get tenant metadata value
     *
     * @param string $key Metadata key
     * @param mixed $default Default value
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$tenant?->get($key, $default);
    }

    /**
     * Clear the current tenant context
     *
     * Should be called at the end of request lifecycle
     *
     * @return void
     */
    public static function clear(): void
    {
        self::$tenant = null;
    }
}
