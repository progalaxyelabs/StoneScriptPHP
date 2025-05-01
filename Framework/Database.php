<?php

namespace Framework;

use DateTime;
use Error;
use Exception;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionProperty;
use ReflectionUnionType;
use Throwable;

class Database
{
    private static ?Database $_instance = null;
    // private $log;

    private \PgSql\Connection|false $connection;

    private function __construct()
    {
        $env = Env::get_instance();

        $host = $env->DATABASE_HOST;
        $port = $env->DATABASE_PORT;
        $user = $env->DATABASE_USER;
        $password = $env->DATABASE_PASSWORD;
        $dbname = $env->DATABASE_DBNAME;
        $timeout = $env->DATABASE_TIMEOUT;
        $appname = $env->DATABASE_APPNAME;

        $connection_string = join(' ', [
            "host=$host",
            "port=$port",
            "user=$user",
            "password=$password",
            "dbname=$dbname",
            "connect_timeout=$timeout",
            "options='--application_name=$appname'"
        ]);

        $env_start = microtime(true);
        $this->connection = pg_connect($connection_string);
        $env_init = microtime(true) - $env_start;
        log_debug(' env init took ' . ($env_init * 1000) . 'ms');
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
        $connection = self::get_instance()->connection;
        $dynamic_params_str = '';
        $dynamic_params_array = [];

        for ($i = 1; $i <= count($params); $i++) {
            $dynamic_params_array[] = ('$' . $i);
        }
        $dynamic_params_str = join(',', $dynamic_params_array);

        $query = "select * from $function_name" . "($dynamic_params_str)";

        // log_debug(__METHOD__ . ' query is ' . var_export($query, true));
        // log_debug(__METHOD__ . ' params are ' . var_export($dynamic_params_str, true));

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
        // log_debug(__METHOD__ . ' data is ' . var_export($data, true);

        return $rows;
    }

    public static function internal_query($sql): array
    {
        $connection = self::get_instance()->connection;

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

    public static function query($sql): string
    {
        $connection = self::get_instance()->connection;

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

        // $message =  pg_last_error($connection);
        // return $message;
        // return var_export($result, true);
        // if ($status === PGSQL_TUPLES_OK) {
        //     $rows = pg_fetch_all($result);
        //     return var_export($rows, true);
        // }
        // return $status;
    }


    public static function result_as_object(string $function_name, array $rows, string $class)
    {
        if (empty($rows)) {
            return null;
        }

        return self::array_to_class_object($function_name, $rows[0], $class, true);
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

    public static function copy_from(array $rows, string $tablename, string $delimiter): bool
    {
        $connection = self::get_instance()->connection;
        $result = pg_copy_from($connection, $tablename, $rows, $delimiter);
        return $result;
    }
}
