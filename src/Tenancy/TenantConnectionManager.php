<?php

namespace StoneScriptPHP\Tenancy;

use PDO;
use PDOException;
use StoneScriptPHP\Database\DbConnectionPool;

/**
 * Tenant Connection Manager
 *
 * Wrapper around global DbConnectionPool for backward compatibility.
 * Delegates all connection management to the global pool.
 *
 * @deprecated Use Framework\Database\DbConnectionPool::getInstance() or tenant_db() function instead
 */
class TenantConnectionManager
{
    /**
     * Get database connection for a tenant
     *
     * Delegates to global DbConnectionPool
     *
     * @param string $tenantDbName Tenant database name
     * @param array $config Database configuration
     * @return PDO
     * @throws PDOException If connection fails
     */
    public static function getConnection(string $tenantDbName, array $config): PDO
    {
        $pool = DbConnectionPool::getInstance();

        // Set config if provided
        if (!empty($config)) {
            $pool->setConfig($config);
        }

        return $pool->getConnection($tenantDbName, $config);
    }

    /**
     * Get connection for current tenant from TenantContext
     *
     * @param array $config Database configuration
     * @return PDO|null PDO instance or null if no tenant context
     * @throws PDOException If connection fails
     */
    public static function getCurrentTenantConnection(array $config): ?PDO
    {
        $tenant = TenantContext::getTenant();

        if (!$tenant || !$tenant->dbName) {
            return null;
        }

        return self::getConnection($tenant->dbName, $config);
    }

    /**
     * Check if connection exists in pool
     *
     * @param string $tenantDbName Tenant database name
     * @return bool
     */
    public static function hasConnection(string $tenantDbName): bool
    {
        return DbConnectionPool::getInstance()->hasConnection($tenantDbName);
    }

    /**
     * Close specific tenant connection
     *
     * @param string $tenantDbName Tenant database name
     * @return void
     */
    public static function closeConnection(string $tenantDbName): void
    {
        DbConnectionPool::getInstance()->closeConnection($tenantDbName);
    }

    /**
     * Close all tenant connections
     *
     * @return void
     */
    public static function closeAll(): void
    {
        DbConnectionPool::getInstance()->closeAll();
    }

    /**
     * Get count of active connections
     *
     * @return int
     */
    public static function getConnectionCount(): int
    {
        return DbConnectionPool::getInstance()->getConnectionCount();
    }

    /**
     * Get list of tenant database names with active connections
     *
     * @return array
     */
    public static function getActiveTenants(): array
    {
        return DbConnectionPool::getInstance()->getActiveConnections();
    }
}
