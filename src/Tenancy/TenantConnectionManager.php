<?php

namespace Framework\Tenancy;

use PDO;
use PDOException;

/**
 * Tenant Connection Manager
 *
 * Manages database connection pooling for multi-tenant applications.
 * Caches connections per tenant database to avoid creating new connections
 * for every request.
 *
 * Supports per-tenant database isolation strategy where each tenant has
 * a dedicated PostgreSQL database.
 *
 * Usage:
 *   $config = ['driver' => 'pgsql', 'host' => 'localhost', ...];
 *   $pdo = TenantConnectionManager::getConnection('tenant_123abc', $config);
 */
class TenantConnectionManager
{
    /**
     * Connection pool cache
     * Key: tenant database name
     * Value: PDO instance
     */
    private static array $connections = [];

    /**
     * Get database connection for a tenant
     *
     * Returns cached connection if exists, otherwise creates a new one.
     *
     * @param string $tenantDbName Tenant database name
     * @param array $config Database configuration
     * @return PDO
     * @throws PDOException If connection fails
     */
    public static function getConnection(string $tenantDbName, array $config): PDO
    {
        // Return cached connection if exists
        if (isset(self::$connections[$tenantDbName])) {
            try {
                // Test if connection is still alive
                self::$connections[$tenantDbName]->query('SELECT 1');
                return self::$connections[$tenantDbName];
            } catch (PDOException $e) {
                // Connection is dead, remove from cache and create new one
                unset(self::$connections[$tenantDbName]);
            }
        }

        // Create new connection
        $pdo = self::createConnection($tenantDbName, $config);

        // Cache the connection
        self::$connections[$tenantDbName] = $pdo;

        return $pdo;
    }

    /**
     * Create a new PDO connection
     *
     * @param string $tenantDbName Tenant database name
     * @param array $config Database configuration
     * @return PDO
     * @throws PDOException If connection fails
     */
    private static function createConnection(string $tenantDbName, array $config): PDO
    {
        $driver = $config['driver'] ?? 'pgsql';
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 5432;
        $user = $config['user'] ?? 'postgres';
        $password = $config['password'] ?? '';
        $charset = $config['charset'] ?? 'utf8';

        // Build DSN
        $dsn = match ($driver) {
            'pgsql' => sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $host,
                $port,
                $tenantDbName
            ),
            'mysql' => sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $host,
                $port,
                $tenantDbName,
                $charset
            ),
            'sqlite' => sprintf('sqlite:%s', $tenantDbName),
            default => throw new \InvalidArgumentException("Unsupported database driver: {$driver}")
        };

        // PDO options
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        // Add custom options from config
        if (isset($config['options']) && is_array($config['options'])) {
            $options = array_replace($options, $config['options']);
        }

        try {
            $pdo = new PDO($dsn, $user, $password, $options);

            // Log successful connection (debug level)
            if (function_exists('log_debug')) {
                log_debug("TenantConnectionManager: Connected to {$tenantDbName}");
            }

            return $pdo;
        } catch (PDOException $e) {
            // Log error
            if (function_exists('log_error')) {
                log_error("TenantConnectionManager: Failed to connect to {$tenantDbName} - {$e->getMessage()}");
            }

            throw $e;
        }
    }

    /**
     * Get connection for current tenant from TenantContext
     *
     * Convenience method that automatically uses current tenant's database
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
        return isset(self::$connections[$tenantDbName]);
    }

    /**
     * Close specific tenant connection
     *
     * @param string $tenantDbName Tenant database name
     * @return void
     */
    public static function closeConnection(string $tenantDbName): void
    {
        if (isset(self::$connections[$tenantDbName])) {
            unset(self::$connections[$tenantDbName]);

            if (function_exists('log_debug')) {
                log_debug("TenantConnectionManager: Closed connection to {$tenantDbName}");
            }
        }
    }

    /**
     * Close all tenant connections
     *
     * Should be called at application shutdown or for testing cleanup
     *
     * @return void
     */
    public static function closeAll(): void
    {
        $count = count(self::$connections);
        self::$connections = [];

        if (function_exists('log_debug') && $count > 0) {
            log_debug("TenantConnectionManager: Closed {$count} tenant connections");
        }
    }

    /**
     * Get count of active connections
     *
     * @return int
     */
    public static function getConnectionCount(): int
    {
        return count(self::$connections);
    }

    /**
     * Get list of tenant database names with active connections
     *
     * @return array
     */
    public static function getActiveTenants(): array
    {
        return array_keys(self::$connections);
    }
}
