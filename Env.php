<?php

namespace Framework;

use Exception;

class Env
{
    private static ?Env $_instance = null;

    // Environment variable values
    public $DATABASE_HOST;
    public $DATABASE_PORT;
    public $DATABASE_USER;
    public $DATABASE_PASSWORD;
    public $DATABASE_DBNAME;
    public $DATABASE_TIMEOUT;
    public $DATABASE_APPNAME;

    public $ZEPTOMAIL_BOUNCE_ADDRESS;
    public $ZEPTOMAIL_SENDER_EMAIL;
    public $ZEPTOMAIL_SENDER_NAME;
    public $ZEPTOMAIL_SEND_MAIL_TOKEN;

    /**
     * Define the environment variable schema
     * Each entry contains: type, required, default, and description
     *
     * Supported types: string, int, bool, float
     *
     * @return array Schema definition
     */
    public static function getSchema(): array
    {
        return [
            'DATABASE_HOST' => [
                'type' => 'string',
                'required' => true,
                'default' => 'localhost',
                'description' => 'Database host address'
            ],
            'DATABASE_PORT' => [
                'type' => 'int',
                'required' => true,
                'default' => 5432,
                'description' => 'Database port number'
            ],
            'DATABASE_USER' => [
                'type' => 'string',
                'required' => true,
                'default' => null,
                'description' => 'Database username'
            ],
            'DATABASE_PASSWORD' => [
                'type' => 'string',
                'required' => true,
                'default' => null,
                'description' => 'Database password'
            ],
            'DATABASE_DBNAME' => [
                'type' => 'string',
                'required' => true,
                'default' => null,
                'description' => 'Database name'
            ],
            'DATABASE_TIMEOUT' => [
                'type' => 'int',
                'required' => false,
                'default' => 30,
                'description' => 'Database connection timeout in seconds'
            ],
            'DATABASE_APPNAME' => [
                'type' => 'string',
                'required' => false,
                'default' => 'StoneScriptPHP',
                'description' => 'Application name for database connections'
            ],
            'ZEPTOMAIL_BOUNCE_ADDRESS' => [
                'type' => 'string',
                'required' => false,
                'default' => null,
                'description' => 'ZeptoMail bounce email address'
            ],
            'ZEPTOMAIL_SENDER_EMAIL' => [
                'type' => 'string',
                'required' => false,
                'default' => null,
                'description' => 'ZeptoMail sender email address'
            ],
            'ZEPTOMAIL_SENDER_NAME' => [
                'type' => 'string',
                'required' => false,
                'default' => null,
                'description' => 'ZeptoMail sender name'
            ],
            'ZEPTOMAIL_SEND_MAIL_TOKEN' => [
                'type' => 'string',
                'required' => false,
                'default' => null,
                'description' => 'ZeptoMail API token'
            ],
        ];
    }

    private function __construct()
    {
        $env_file_path = ROOT_PATH . DIRECTORY_SEPARATOR . '.env';
        if (!file_exists($env_file_path)) {
            $message = 'missing .env file. Run: php Framework/cli/generate-env.php';
            throw new Exception($message);
        }

        $schema = self::getSchema();
        $missing_keys = [];
        $type_errors = [];

        $env = parse_ini_file($env_file_path);

        foreach ($schema as $key => $config) {
            if (array_key_exists($key, $env)) {
                // Value exists in .env, validate and set it
                $value = $env[$key];
                $validatedValue = $this->validateAndCast($key, $value, $config['type']);

                if ($validatedValue === false && $config['type'] !== 'bool') {
                    $type_errors[] = "$key (expected {$config['type']}, got: $value)";
                } else {
                    $this->$key = $validatedValue;
                }
            } elseif (isset($config['default']) && $config['default'] !== null) {
                // Use default value
                $this->$key = $config['default'];
            } elseif ($config['required']) {
                // Required but missing
                log_debug("missing required setting in .env file [$key]");
                $missing_keys[] = $key;
            } else {
                // Optional and not set
                $this->$key = null;
            }
        }

        if (count($type_errors) > 0) {
            throw new Exception('Type validation errors in .env: ' . implode(', ', $type_errors));
        }

        if (count($missing_keys) > 0) {
            throw new Exception(count($missing_keys) . ' required settings missing in .env file: ' . implode(', ', $missing_keys));
        }
    }

    /**
     * Validate and cast a value to the expected type
     *
     * @param string $key Variable name
     * @param mixed $value Raw value from .env
     * @param string $type Expected type
     * @return mixed Casted value or false on error
     */
    private function validateAndCast(string $key, $value, string $type)
    {
        switch ($type) {
            case 'string':
                return (string) $value;

            case 'int':
                if (!is_numeric($value)) {
                    return false;
                }
                return (int) $value;

            case 'float':
                if (!is_numeric($value)) {
                    return false;
                }
                return (float) $value;

            case 'bool':
                $lower = strtolower(trim($value));
                if (in_array($lower, ['true', '1', 'yes', 'on'])) {
                    return true;
                } elseif (in_array($lower, ['false', '0', 'no', 'off', ''])) {
                    return false;
                }
                return false;

            default:
                log_debug("Unknown type for $key: $type");
                return $value;
        }
    }

    public static function get_instance(): Env
    {
        if (!self::$_instance) {
            self::$_instance = new Env();
        }

        return self::$_instance;
    }
}
