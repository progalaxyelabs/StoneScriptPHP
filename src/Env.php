<?php

namespace Framework;

use Exception;

class Env
{
    private static ?Env $_instance = null;

    // Environment variable values
    public $DEBUG_MODE;
    public $TIMEZONE;

    public $APP_NAME;
    public $APP_ENV;
    public $APP_PORT;

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

    public $EMAIL_VERIFICATION_ENABLED;

    public $CSRF_SECRET_KEY;
    public $HCAPTCHA_SITE_KEY;
    public $HCAPTCHA_SECRET_KEY;

    public $JWT_PRIVATE_KEY_PATH;
    public $JWT_PUBLIC_KEY_PATH;
    public $JWT_PRIVATE_KEY_PASSPHRASE;
    public $JWT_ISSUER;
    public $JWT_ACCESS_TOKEN_EXPIRY;
    public $JWT_REFRESH_TOKEN_EXPIRY;

    public $ALLOWED_ORIGINS;

    /**
     * Define the environment variable schema
     * Each entry contains: type, required, default, and description
     *
     * Supported types: string, int, bool, float
     *
     * @return array Schema definition
     */
    public function getSchema(): array
    {
        return [
            'DEBUG_MODE' => [
                'type' => 'bool',
                'required' => false,
                'default' => false,
                'description' => 'Enable debug mode for detailed error reporting'
            ],
            'TIMEZONE' => [
                'type' => 'string',
                'required' => false,
                'default' => 'UTC',
                'description' => 'Default timezone for the application'
            ],
            'APP_NAME' => [
                'type' => 'string',
                'required' => false,
                'default' => 'My API',
                'description' => 'Application name'
            ],
            'APP_ENV' => [
                'type' => 'string',
                'required' => false,
                'default' => 'development',
                'description' => 'Application environment (development, production, etc.)'
            ],
            'APP_PORT' => [
                'type' => 'int',
                'required' => false,
                'default' => 9100,
                'description' => 'Application server port'
            ],
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
            'EMAIL_VERIFICATION_ENABLED' => [
                'type' => 'bool',
                'required' => false,
                'default' => true,
                'description' => 'Enable/disable email verification on registration'
            ],
            'CSRF_SECRET_KEY' => [
                'type' => 'string',
                'required' => false,
                'default' => null,
                'description' => 'Secret key for CSRF token generation (64-char hex string)'
            ],
            'HCAPTCHA_SITE_KEY' => [
                'type' => 'string',
                'required' => false,
                'default' => null,
                'description' => 'hCaptcha site key (get from https://www.hcaptcha.com/)'
            ],
            'HCAPTCHA_SECRET_KEY' => [
                'type' => 'string',
                'required' => false,
                'default' => null,
                'description' => 'hCaptcha secret key (get from https://www.hcaptcha.com/)'
            ],
            'JWT_PRIVATE_KEY_PATH' => [
                'type' => 'string',
                'required' => false,
                'default' => './keys/jwt-private.pem',
                'description' => 'Path to JWT private key file (relative to project root)'
            ],
            'JWT_PUBLIC_KEY_PATH' => [
                'type' => 'string',
                'required' => false,
                'default' => './keys/jwt-public.pem',
                'description' => 'Path to JWT public key file (relative to project root)'
            ],
            'JWT_PRIVATE_KEY_PASSPHRASE' => [
                'type' => 'string',
                'required' => false,
                'default' => null,
                'description' => 'Passphrase for encrypted JWT private key (leave empty for unencrypted)'
            ],
            'JWT_ISSUER' => [
                'type' => 'string',
                'required' => false,
                'default' => 'example.com',
                'description' => 'JWT token issuer (your domain name)'
            ],
            'JWT_ACCESS_TOKEN_EXPIRY' => [
                'type' => 'int',
                'required' => false,
                'default' => 900,
                'description' => 'JWT access token expiry in seconds (default: 900 = 15 minutes)'
            ],
            'JWT_REFRESH_TOKEN_EXPIRY' => [
                'type' => 'int',
                'required' => false,
                'default' => 15552000,
                'description' => 'JWT refresh token expiry in seconds (default: 15552000 = 180 days)'
            ],
            'ALLOWED_ORIGINS' => [
                'type' => 'string',
                'required' => false,
                'default' => 'http://localhost:3000,http://localhost:4200',
                'description' => 'Comma-separated list of allowed CORS origins'
            ],
        ];
    }

    protected function __construct()
    {
        $env_file_path = ROOT_PATH . DIRECTORY_SEPARATOR . '.env';
        if (!file_exists($env_file_path)) {
            $message = 'missing .env file. Run: php stone setup';
            throw new Exception($message);
        }

        $schema = $this->getSchema();
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
            // Check if App\Env exists and use it, otherwise use Framework\Env
            if (class_exists('App\\Env')) {
                self::$_instance = new \App\Env();
            } else {
                self::$_instance = new static();
            }
        }

        return self::$_instance;
    }
}
