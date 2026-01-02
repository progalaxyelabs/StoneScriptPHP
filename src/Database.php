<?php

namespace StoneScriptPHP;

use DateTime;
use Error;
use Exception;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionProperty;
use ReflectionUnionType;
use StoneScriptPHP\Database\ConnectionInterface;
use StoneScriptPHP\Database\DirectConnection;
use StoneScriptPHP\Database\GatewayConnection;
use Throwable;

class Database
{
    private static ?Database $_instance = null;

    private ?ConnectionInterface $connection = null;
    private ?string $connection_mode = null;

    private function __construct()
    {
        // Constructor no longer eagerly connects
        // Connection will be established lazily when first needed
    }

    /**
     * Initialize the connection based on environment configuration.
     */
    private function initConnection(): void
    {
        if ($this->connection !== null) {
            return;
        }

        $env = Env::get_instance();
        $this->connection_mode = $env->DB_CONNECTION_MODE;

        $start_time = microtime(true);

        if ($this->connection_mode === 'gateway') {
            $this->connection = GatewayConnection::fromEnv();
            log_debug('Database: Using gateway connection mode');
        } else {
            $this->connection = new DirectConnection();
            log_debug('Database: Using direct connection mode');
        }

        $elapsed_time = microtime(true) - $start_time;
        log_debug(__METHOD__ . " Connection initialized in " . ($elapsed_time * 1000) . "ms");
    }

    /**
     * Get the connection instance.
     *
     * @return ConnectionInterface
     */
    private function getConnectionInstance(): ConnectionInterface
    {
        $this->initConnection();
        return $this->connection;
    }

    /**
     * Get the direct connection for raw SQL operations.
     * Throws exception if not in direct mode.
     *
     * @return DirectConnection
     * @throws Exception If not in direct connection mode
     */
    private function getDirectConnection(): DirectConnection
    {
        $this->initConnection();

        if (!($this->connection instanceof DirectConnection)) {
            throw new Exception('This operation requires direct database connection mode. Set DB_CONNECTION_MODE=direct');
        }

        return $this->connection;
    }

    /**
     * Check if currently in gateway mode.
     *
     * @return bool
     */
    public static function isGatewayMode(): bool
    {
        $instance = self::get_instance();
        $instance->initConnection();
        return $instance->connection_mode === 'gateway';
    }

    /**
     * Get the current connection mode.
     *
     * @return string 'direct' or 'gateway'
     */
    public static function getConnectionMode(): string
    {
        $instance = self::get_instance();
        $instance->initConnection();
        return $instance->connection_mode;
    }

    private static function get_instance(): Database
    {
        $start_time = microtime(true);

        if (!self::$_instance) {
            self::$_instance = new Database();
        }

        $elapsed_time = microtime(true) - $start_time;

        log_debug(__METHOD__ . " Timing: took $elapsed_time");

        return self::$_instance;
    }

    public static function fn(string $function_name, array $params): array
    {
        $start_time = microtime(true);
        $data = self::_fn($function_name, $params);
        $elapsed_time = microtime(true) - $start_time;

        log_debug(__METHOD__ . " Timing: $function_name took $elapsed_time");
        return $data;
    }

    private static function _fn(string $function_name, array $params): array
    {
        $connection = self::get_instance()->getConnectionInstance();
        return $connection->callFunction($function_name, $params);
    }

    /**
     * Execute a raw SQL query and return results as array.
     * Only available in direct connection mode.
     *
     * @param string $sql The SQL query to execute
     * @return array The result rows
     * @throws Exception If in gateway mode
     */
    public static function internal_query($sql): array
    {
        $directConnection = self::get_instance()->getDirectConnection();
        $connection = $directConnection->getConnection();

        $result = pg_query($connection, $sql);
        if ($result === false) {
            $message = pg_last_error($connection);
            log_debug($message);
            return [];
        }

        $data = [];
        $status = pg_result_status($result);
        switch ($status) {
            case PGSQL_EMPTY_QUERY:
                $message = 'Empty Query';
                break;
            case PGSQL_COMMAND_OK:
                $message = 'Ok';
                break;
            case PGSQL_TUPLES_OK:
                $rows = pg_fetch_all($result);
                $message = 'Fetched ' . count($rows) . ' rows';
                $data = $rows;
                break;
            case PGSQL_COPY_OUT:
                $message = 'Copy OUT';
                break;
            case PGSQL_COPY_IN:
                $message = 'Copy IN';
                break;
            case PGSQL_BAD_RESPONSE:
                $message =  pg_last_error($connection);
                $message = 'Bad Response: ' . $message;
                break;
            case PGSQL_NONFATAL_ERROR:
                $message =  pg_last_error($connection);
                $message = 'Non Fatal Error:'  . $message;
                break;
            case PGSQL_FATAL_ERROR:
                $message =  pg_last_error($connection);
                $message = 'Fatal Error: ' . $message;
                break;
            default:
                $message =  pg_last_error($connection);
                $message = 'Unknown result status ' . $message;
                break;
        }

        log_debug($message);
        return $data;
    }

    /**
     * Execute a raw SQL query and return status string.
     * Only available in direct connection mode.
     *
     * @param string $sql The SQL query to execute
     * @return string The result status or data
     * @throws Exception If in gateway mode
     */
    public static function query($sql): string
    {
        $directConnection = self::get_instance()->getDirectConnection();
        $connection = $directConnection->getConnection();

        $result = pg_query($connection, $sql);
        if ($result === false) {
            $message = pg_last_error($connection);
            log_debug($message);
            return $message;
        }

        // $status = pg_result_status($result, PGSQL_STATUS_STRING);
        $status = pg_result_status($result);
        switch ($status) {
            case PGSQL_EMPTY_QUERY:
                return 'Empty Query';
            case PGSQL_COMMAND_OK:
                return 'Ok';
            case PGSQL_TUPLES_OK:
                $rows = pg_fetch_all($result);
                return var_export($rows, true);
            case PGSQL_COPY_OUT:
                return 'Copy OUT';
            case PGSQL_COPY_IN:
                return 'Copy IN';
            case PGSQL_BAD_RESPONSE:
                $message =  pg_last_error($connection);
                return 'Bad Response: ' . $message;
            case PGSQL_NONFATAL_ERROR:
                $message =  pg_last_error($connection);
                return 'Non Fatal Error:'  . $message;
            case PGSQL_FATAL_ERROR:
                $message =  pg_last_error($connection);
                return 'Fatal Error: ' . $message;
            default:
                $message =  pg_last_error($connection);
                return 'Unknown result status ' . $message;
        }
    }


    public static function result_as_object(string $function_name, array $rows, string $class)
    {
        if (empty($rows)) {
            return null;
        }

        return self::array_to_class_object($function_name, $rows[0], $class, true);
    }

    /**
     * Convert PostgreSQL function result to a single model object.
     * Use this for functions that return exactly one row (get by ID, create, update).
     *
     * @param string $function_name Name of the database function (for error context)
     * @param array $rows Result rows from Database::fn()
     * @param string $class Fully qualified class name for mapping
     * @return object|null Single instance of $class or null if no rows
     */
    public static function result_as_single(string $function_name, array $rows, string $class): ?object
    {
        if (empty($rows)) {
            return null;
        }

        return self::array_to_class_object($function_name, $rows[0], $class);
    }

    public static function result_as_table(string $function_name, array $rows, string $class): array
    {
        $data = [];
        foreach ($rows as $row) {
            $data[] = self::array_to_class_object($function_name, $row, $class);
        }

        return $data;
    }

    public static function array_to_class_object(string $function_name, array $row, string $class, bool $as_out_param = false): object
    {
        $instance = new $class();
        $reflect = new ReflectionClass($instance);
        $properties   = $reflect->getProperties(ReflectionProperty::IS_PUBLIC);
        $missing_properties = [];

        foreach ($properties as $property) {
            $p_name = $property->getName();
            $reflect_type = $property->getType();

            if (
                ($reflect_type === null)
                || ($reflect_type instanceof ReflectionUnionType)
                || (($reflect_type instanceof ReflectionIntersectionType)
                )
            ) {
                throw "Unsupported type for property [$p_name]";
            }

            $p_type = $reflect_type->getName();
            $p_nullable = $reflect_type->allowsNull();

            log_debug(__METHOD__ . " property [$p_name] type is [$p_type]" .  ($p_nullable ? " and allows null" : ""));

            // log_debug(__METHOD__ . ' ' . var_export($row, true));

            $row_key = $p_name;
            if ($as_out_param) {
                $row_key = 'o_' . $p_name;
            }
            if (array_key_exists($row_key, $row)) {
                if ($row[$row_key] === null) {
                    if ($p_type === 'int') {
                        $instance->$p_name = 0;
                    } else if ($p_type === 'bool') {
                        $instance->$p_name = false;
                    } else {
                        $instance->$p_name = '';
                    }
                } else if ($p_type === 'DateTime') {
                    $instance->$p_name = new DateTime($row[$row_key]);
                } else if ($p_type === 'bool') {
                    $instance->$p_name = ($row[$row_key] === 't');
                } else {
                    $instance->$p_name = $row[$row_key];
                }
            } else {
                $is_out_param = $as_out_param ? 'true' : 'false';
                log_debug(" expected [$row_key] with type [$p_type] as out param is [$is_out_param]  from class [$class] but not found in db function [$function_name] result");
                $missing_properties[] = $p_name;
            }
        }

        if (count($missing_properties) > 0) {
            throw new Exception("mismatch in function result fields and class properties");
        }

        return $instance;
    }

    /**
     * Copy data from an array to a table using COPY FROM.
     * Only available in direct connection mode.
     *
     * @param array $rows The rows to copy
     * @param string $tablename The target table name
     * @param string $delimiter The field delimiter
     * @return bool True on success, false on failure
     * @throws Exception If in gateway mode
     */
    public static function copy_from(array $rows, string $tablename, string $delimiter): bool
    {
        $directConnection = self::get_instance()->getDirectConnection();
        return $directConnection->copyFrom($rows, $tablename, $delimiter);
    }

    /**
     * Get the underlying connection object.
     * Useful for advanced operations or testing.
     *
     * @return ConnectionInterface
     */
    public static function getConnection(): ConnectionInterface
    {
        return self::get_instance()->getConnectionInstance();
    }
}
