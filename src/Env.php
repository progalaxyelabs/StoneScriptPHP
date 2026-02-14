<?php

namespace StoneScriptPHP;

use Exception;

class Env
{
    private static ?Env $_instance = null;

    // Environment variable values with typed properties and defaults
    public bool $DEBUG_MODE = false;
    public string $TIMEZONE = 'UTC';

    public string $APP_NAME = 'My API';
    public string $APP_ENV = 'development';
    public int $APP_PORT = 9100;

    // Database - Gateway Connection (Required in v3+)
    public string $DB_GATEWAY_URL;
    public string $DB_GATEWAY_PLATFORM;
    public ?string $DB_GATEWAY_TENANT_ID = null;

    public ?string $ZEPTOMAIL_BOUNCE_ADDRESS = null;
    public ?string $ZEPTOMAIL_SENDER_EMAIL = null;
    public ?string $ZEPTOMAIL_SENDER_NAME = null;
    public ?string $ZEPTOMAIL_SEND_MAIL_TOKEN = null;

    public bool $EMAIL_VERIFICATION_ENABLED = true;

    public ?string $CSRF_SECRET_KEY = null;
    public ?string $HCAPTCHA_SITE_KEY = null;
    public ?string $HCAPTCHA_SECRET_KEY = null;

    public string $JWT_PRIVATE_KEY_PATH = './keys/jwt-private.pem';
    public string $JWT_PUBLIC_KEY_PATH = './keys/jwt-public.pem';
    public ?string $JWT_PRIVATE_KEY_PASSPHRASE = null;
    public string $JWT_ISSUER = 'example.com';
    public int $JWT_ACCESS_TOKEN_EXPIRY = 900;
    public int $JWT_REFRESH_TOKEN_EXPIRY = 15552000;

    // Authentication Mode (v2.2.0+) - Supports microservices architecture
    // Modes: 'builtin' (local RSA), 'external' (JWKS), 'hybrid' (validate external + issue own)
    public string $AUTH_MODE = 'builtin';
    public string $AUTH_SERVICE_URL = 'http://localhost:3139';

    public string $AUTH_COOKIE_DOMAIN = '';
    public ?bool $AUTH_COOKIE_SECURE = null;

    public string $ALLOWED_ORIGINS = 'http://localhost:3000,http://localhost:4200';

    protected function __construct()
    {
        // Load .env file if it exists (optional for Docker environments)
        $env_file_path = ROOT_PATH . DIRECTORY_SEPARATOR . '.env';
        if (file_exists($env_file_path)) {
            $dotenv = \Dotenv\Dotenv::createImmutable(ROOT_PATH);
            $dotenv->load();
        }

        // Use reflection to discover all public properties and override with env vars
        $reflection = new \ReflectionClass($this);
        $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);

        foreach ($properties as $property) {
            $propName = $property->getName();

            // Check if environment variable is set
            $envValue = getenv($propName);
            if ($envValue === false && isset($_ENV[$propName])) {
                $envValue = $_ENV[$propName];
            }

            if ($envValue !== false) {
                // Cast value based on property type
                $this->$propName = $this->castValue($envValue, $property->getType());
            }
            // If no env var set, property keeps its default value
        }

        // Validate required gateway configuration (v3+ gateway-only)
        if (empty($this->DB_GATEWAY_URL)) {
            throw new Exception('DB_GATEWAY_URL is required. StoneScriptPHP v3+ uses gateway-only mode. Run: php stone setup');
        }
        if (empty($this->DB_GATEWAY_PLATFORM)) {
            throw new Exception('DB_GATEWAY_PLATFORM is required. Run: php stone setup');
        }
    }

    /**
     * Cast a string value from environment to the appropriate type
     */
    private function castValue(string $value, ?\ReflectionType $type)
    {
        if (!$type) {
            return $value;
        }

        $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : 'string';

        switch ($typeName) {
            case 'int':
                return (int) $value;
            case 'float':
                return (float) $value;
            case 'bool':
                $lower = strtolower(trim($value));
                return in_array($lower, ['true', '1', 'yes', 'on'], true);
            default:
                return $value;
        }
    }

    public static function get_instance(): static
    {
        if (!self::$_instance) {
            self::$_instance = new static();
        }

        return self::$_instance;
    }
}
