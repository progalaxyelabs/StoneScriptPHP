<?php

namespace StoneScriptPHP\Database;

use Error;
use Exception;
use StoneScriptPHP\Env;
use Throwable;

/**
 * Direct PostgreSQL connection implementation.
 *
 * Uses pg_connect() for direct database connections.
 * Maintains lazy connection behavior - only connects when first needed.
 */
class DirectConnection implements ConnectionInterface
{
    private \PgSql\Connection|false|null $connection = null;
    private bool $connection_attempted = false;
    private ?string $connection_error = null;

    /**
     * Establish the database connection lazily.
     */
    private function connect(): void
    {
        if ($this->connection_attempted) {
            return; // Already attempted connection (success or failure)
        }

        $this->connection_attempted = true;

        try {
            $env = Env::get_instance();

            $host = $env->DATABASE_HOST;
            $port = $env->DATABASE_PORT;
            $user = $env->DATABASE_USER;
            $password = $env->DATABASE_PASSWORD;
            $dbname = $env->DATABASE_DBNAME;
            $appname = $env->DATABASE_APPNAME;

            $connection_string = join(' ', [
                "host=$host",
                "port=$port",
                "user=$user",
                "password=$password",
                "dbname=$dbname",
                "options='--application_name=$appname'"
            ]);

            $env_start = microtime(true);

            // Suppress warnings temporarily to get clean error message
            set_error_handler(function ($errno, $errstr) {
                // Convert warning to exception for proper error handling
                throw new \ErrorException($errstr, 0, $errno);
            }, E_WARNING);

            try {
                $this->connection = pg_connect($connection_string);
                restore_error_handler();

                if ($this->connection === false) {
                    $this->connection_error = 'Failed to connect to PostgreSQL database';
                    log_debug('Database connection failed: ' . $this->connection_error);
                } else {
                    $env_init = microtime(true) - $env_start;
                    log_debug(' Database connection established in ' . ($env_init * 1000) . 'ms');
                }
            } catch (\ErrorException $e) {
                restore_error_handler();
                $this->connection = false;
                $this->connection_error = 'Database connection failed: ' . $e->getMessage();
                log_debug($this->connection_error);
            }
        } catch (\Throwable $e) {
            $this->connection = false;
            $this->connection_error = $e->getMessage();
            log_debug('Database connection exception: ' . $this->connection_error);
        }
    }

    /**
     * Get the raw PostgreSQL connection resource.
     *
     * @return \PgSql\Connection|false
     * @throws Exception If connection is not available
     */
    public function getConnection(): \PgSql\Connection|false
    {
        if (!$this->connection_attempted) {
            $this->connect();
        }

        if ($this->connection === false || $this->connection === null) {
            throw new \Exception(
                'Database connection not available. ' .
                ($this->connection_error ? 'Error: ' . $this->connection_error : 'Connection not established.')
            );
        }

        return $this->connection;
    }

    /**
     * {@inheritdoc}
     */
    public function callFunction(string $function_name, array $params): array
    {
        $connection = $this->getConnection();
        $dynamic_params_str = '';
        $dynamic_params_array = [];

        for ($i = 1; $i <= count($params); $i++) {
            $dynamic_params_array[] = ('$' . $i);
        }
        $dynamic_params_str = join(',', $dynamic_params_array);

        $query = "select * from $function_name" . "($dynamic_params_str)";

        try {
            $result = pg_query_params($connection, $query, $params);
        } catch (Exception $exception) {
            log_debug(__METHOD__ . ' Exception: ' . $exception->getMessage());
        } catch (Error $error) {
            log_debug(__METHOD__ . ' Error: ' . $error->getMessage());
        } catch (Throwable $throwable) {
            log_debug(__METHOD__ . ' Throws: ' . $throwable->getMessage());
        }

        $data = [];

        if ($result === false) {
            $error_message = pg_last_error($connection);
            log_debug(__METHOD__ . ' query failed: ' . $error_message);
            return $data;
        }

        $rows = pg_fetch_all($result);

        return $rows;
    }

    /**
     * {@inheritdoc}
     */
    public function isConnected(): bool
    {
        if (!$this->connection_attempted) {
            return false;
        }

        return $this->connection !== false && $this->connection !== null;
    }

    /**
     * Execute a raw SQL query.
     *
     * @param string $sql The SQL query to execute
     * @return \PgSql\Result|false The result resource or false on failure
     */
    public function query(string $sql): \PgSql\Result|false
    {
        $connection = $this->getConnection();
        return pg_query($connection, $sql);
    }

    /**
     * Get the last error message from the connection.
     *
     * @return string The error message
     */
    public function getLastError(): string
    {
        if ($this->connection === false || $this->connection === null) {
            return $this->connection_error ?? 'No connection';
        }
        return pg_last_error($this->connection);
    }

    /**
     * Copy data from an array to a table using COPY FROM.
     *
     * @param array $rows The rows to copy
     * @param string $tablename The target table name
     * @param string $delimiter The field delimiter
     * @return bool True on success, false on failure
     */
    public function copyFrom(array $rows, string $tablename, string $delimiter): bool
    {
        $connection = $this->getConnection();
        return pg_copy_from($connection, $tablename, $rows, $delimiter);
    }
}
