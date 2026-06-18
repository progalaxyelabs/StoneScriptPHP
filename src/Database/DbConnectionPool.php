<?php

namespace StoneScriptPHP\Database;

use PDO;
use PDOException;

/**
 * Global Database Connection Pool
 *
 * Manages all database connections (tenant and non-tenant) with connection pooling.
 * This is a singleton that maintains connections across the application lifecycle.
 */
class DbConnectionPool
{
    private static ?self $instance = null;
    private array $connections = [];
    private array $config = [];

    private function __construct() {}

    /**
     * Get singleton instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Set global database configuration
     *
     * @param array $config Database configuration
     * @return void
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    /**
     * Get database configuration
     *
     * @return array
     */
    public function getConfig(): array
    {
        if (empty($this->config)) {
            // Try to load from environment variables.
            // Route through Env::secret() so values (notably the DB_PASSWORD
            // secret) flow through the central resolution chain
            // (env -> *_FILE -> /run/secrets) and stay immune to FPM clear_env.
            $this->config = [
                'driver' => \StoneScriptPHP\Env::secret('DB_DRIVER', 'pgsql'),
                'host' => \StoneScriptPHP\Env::secret('DB_HOST', 'localhost'),
                'port' => (int) \StoneScriptPHP\Env::secret('DB_PORT', '5432'),
                'user' => \StoneScriptPHP\Env::secret('DB_USER', 'postgres'),
                'password' => \StoneScriptPHP\Env::secret('DB_PASSWORD', ''),
                'charset' => \StoneScriptPHP\Env::secret('DB_CHARSET', 'utf8'),
            ];
        }
        return $this->config;
    }

    /**
     * Get connection to a specific database
     *
     * @param string $database Database name
     * @param array|null $customConfig Optional custom configuration
     * @return PDO
     * @throws PDOException
     */
    public function getConnection(string $database, ?array $customConfig = null): PDO
    {
        // Check if connection exists and is alive
        if (isset($this->connections[$database])) {
            try {
                $this->connections[$database]->query('SELECT 1');
                return $this->connections[$database];
            } catch (PDOException $e) {
                // Connection is dead, remove it
                unset($this->connections[$database]);
            }
        }

        // Create new connection
        $config = $customConfig ?? $this->getConfig();
        $pdo = $this->createConnection($database, $config);

        // Cache the connection
        $this->connections[$database] = $pdo;

        return $pdo;
    }

    /**
     * Create a new PDO connection
     *
     * @param string $database Database name
     * @param array $config Configuration
     * @return PDO
     * @throws PDOException
     */
    private function createConnection(string $database, array $config): PDO
    {
        $driver = $config['driver'] ?? 'pgsql';
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 5432;
        $user = $config['user'] ?? 'postgres';
        $password = $config['password'] ?? '';
        $charset = $config['charset'] ?? 'utf8';

        // Build DSN based on driver
        $dsn = match ($driver) {
            'pgsql' => sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $database),
            'mysql' => sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset),
            'sqlite' => sprintf('sqlite:%s', $database),
            default => throw new \InvalidArgumentException("Unsupported database driver: {$driver}")
        };

        // PDO options
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        // Add custom options
        if (isset($config['options']) && is_array($config['options'])) {
            $options = array_replace($options, $config['options']);
        }

        try {
            $pdo = new PDO($dsn, $user, $password, $options);

            if (function_exists('log_debug')) {
                log_debug("DbConnectionPool: Connected to database '{$database}'");
            }

            return $pdo;
        } catch (PDOException $e) {
            if (function_exists('log_error')) {
                log_error("DbConnectionPool: Failed to connect to '{$database}' - {$e->getMessage()}");
            }
            throw $e;
        }
    }

    /**
     * Check if connection exists
     *
     * @param string $database
     * @return bool
     */
    public function hasConnection(string $database): bool
    {
        return isset($this->connections[$database]);
    }

    /**
     * Close specific connection
     *
     * @param string $database
     * @return void
     */
    public function closeConnection(string $database): void
    {
        if (isset($this->connections[$database])) {
            unset($this->connections[$database]);

            if (function_exists('log_debug')) {
                log_debug("DbConnectionPool: Closed connection to '{$database}'");
            }
        }
    }

    /**
     * Close all connections
     *
     * @return void
     */
    public function closeAll(): void
    {
        $count = count($this->connections);
        $this->connections = [];

        if (function_exists('log_debug') && $count > 0) {
            log_debug("DbConnectionPool: Closed {$count} database connections");
        }
    }

    /**
     * Get count of active connections
     *
     * @return int
     */
    public function getConnectionCount(): int
    {
        return count($this->connections);
    }

    /**
     * Get list of databases with active connections
     *
     * @return array
     */
    public function getActiveConnections(): array
    {
        return array_keys($this->connections);
    }
}
