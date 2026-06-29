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
    // Gateway v4 routing: both schema names are required.
    // DB_GATEWAY_SCHEMA_NAME     — main database schema (e.g. "main"). Always required.
    // DB_GATEWAY_TENANT_SCHEMA_NAME — tenant database schema (e.g. "tenant"). Required for multi-tenant platforms only.
    public string $DB_GATEWAY_SCHEMA_NAME;
    public ?string $DB_GATEWAY_TENANT_SCHEMA_NAME = null;
    public ?string $DB_GATEWAY_UUID = null;
    public ?string $DB_GATEWAY_ADMIN_TOKEN = null;
    public string $PLATFORM_ID = '';
    public string $SCHEMA_NAME = 'v1_0';
    public string $DATABASE_ID = 'main';
    public ?string $MAIN_SCHEMA_NAME = null;
    public ?string $TENANT_SCHEMA_NAME = null;

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
    // JWT_ISSUER: the 'iss' claim stamped on every JWT this platform mints.
    // MUST be set explicitly in .env (e.g. https://api.exampleapp.in).
    // Default is empty — RsaJwtHandler::generateToken() throws if empty.
    // The old default was 'example.com', removed because it silently produced
    // tokens with a placeholder issuer that worked locally but broke after
    // any issuer correction, creating hard-to-diagnose 401 errors.
    public string $JWT_ISSUER = '';
    public int $JWT_ACCESS_TOKEN_EXPIRY = 900;
    public int $JWT_REFRESH_TOKEN_EXPIRY = 15552000;

    // Authentication Mode (v2.2.0+) - Supports microservices architecture
    // Modes: 'builtin' (local RSA), 'external' (JWKS), 'hybrid' (validate external + issue own)
    public string $AUTH_MODE = 'builtin';
    public string $AUTH_SERVICE_URL = 'http://localhost:3139';

    // Issuer for JWT 'iss' claim validation (v3.6.0+).
    // In Docker: AUTH_SERVICE_URL = http://auth-container:3139 (container URL, for JWKS fetch)
    //            AUTH_ISSUER      = http://localhost:3139      (public URL stamped in JWT 'iss' claim)
    // Leave empty to fall back to AUTH_SERVICE_URL (single-host and local dev setups).
    public string $AUTH_ISSUER = '';

    // Platform identity sent to the auth service in every request (v3.6.0+).
    public string $PLATFORM_CODE = '';
    public ?string $EXTERNAL_AUTH_CLIENT_SECRET = null;

    public string $AUTH_COOKIE_DOMAIN = '';
    public ?bool $AUTH_COOKIE_SECURE = null;

    public string $ALLOWED_ORIGINS = 'http://localhost:3000,http://localhost:4200';

    // Redis / cache (v4.1.0+) — typed so they flow through the central secret
    // resolution chain instead of Cache.php reading $_ENV directly.
    public string $REDIS_HOST = '127.0.0.1';
    public int $REDIS_PORT = 6379;
    public ?string $REDIS_PASSWORD = null;
    public int $REDIS_DATABASE = 0;
    public string $REDIS_PREFIX = 'stonescript:';
    public int $CACHE_DEFAULT_TTL = 3600;
    public bool $CACHE_ENABLED = true;

    /**
     * Config keys that MUST be present and non-empty for the framework to
     * boot. A missing one fails fast at construction time with a clear
     * message rather than surfacing as a silent runtime 500.
     *
     * Note: these are also covered by the typed properties above; they are
     * listed here so the boot-time validation is explicit and auditable.
     *
     * @var string[]
     */
    protected array $requiredSecrets = [
        'DB_GATEWAY_URL',
        'DB_GATEWAY_PLATFORM',
    ];

    /**
     * Raw resolved string values for every key that went through the
     * secret-resolution chain, keyed by the original (un-lowercased) name.
     * Populated for typed properties during construction, and lazily for
     * ad-hoc keys requested via secret(). Used by secret() so callers can
     * read secrets that are NOT declared as typed properties (e.g.
     * REDIS_PASSWORD historically, JWT_PRIVATE_KEY) through one path.
     *
     * @var array<string, ?string>
     */
    protected array $resolved = [];

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

            // Resolve via the full chain:
            //   1. getenv(VAR) / $_ENV[VAR]           (existing behaviour)
            //   2. getenv(VAR_FILE) -> read that file (Docker secret convention)
            //   3. /run/secrets/<lowercase var>       (Docker Swarm default mount)
            // Tiers 2-3 read FILES inside the PHP worker, so they are immune to
            // PHP-FPM clear_env stripping bash-exported vars.
            $rawValue = $this->resolveRaw($propName);

            // Record the raw resolved value so secret() can serve it without
            // re-running the chain (and so ad-hoc, non-typed keys share the path).
            $this->resolved[$propName] = $rawValue;

            if ($rawValue !== null) {
                // Cast value based on property type
                $this->$propName = $this->castValue($rawValue, $property->getType());
            }
            // If nothing resolved, property keeps its default value
        }

        // Validate required secrets/config are present and non-empty.
        // Fail fast at boot with a clear message instead of a silent runtime 500.
        foreach ($this->requiredSecrets as $required) {
            $value = $this->resolved[$required] ?? $this->resolveRaw($required);
            if ($value === null || trim($value) === '') {
                throw new Exception(sprintf(
                    "Required configuration '%s' is missing or empty. "
                    . "Set the %s env var, the %s_FILE env var pointing at a file, "
                    . "or mount a Docker secret at /run/secrets/%s. "
                    . "(StoneScriptPHP v3+ uses gateway-only mode. Run: php stone setup)",
                    $required,
                    $required,
                    $required,
                    strtolower($required)
                ));
            }
        }
    }

    /**
     * Resolve a config/secret value through the native chain. Returns the
     * raw (uncast) string, or null when nothing resolves.
     *
     * Priority order (mirrors the Rust auth service config.rs and the Docker
     * community standard):
     *   1. getenv(VAR) (non-empty)  OR  $_ENV[VAR]
     *   2. getenv(VAR . "_FILE") -> read the file at that path
     *   3. /run/secrets/<lowercase VAR> -> read the file
     */
    protected function resolveRaw(string $name): ?string
    {
        // Tier 1: direct env var (existing behaviour, unchanged).
        $envValue = getenv($name);
        if ($envValue === false && isset($_ENV[$name])) {
            $envValue = $_ENV[$name];
        }
        if ($envValue !== false && $envValue !== '') {
            return $envValue;
        }

        // Tier 2: <VAR>_FILE convention — explicit file path passed via env.
        $filePathEnv = getenv($name . '_FILE');
        if ($filePathEnv === false && isset($_ENV[$name . '_FILE'])) {
            $filePathEnv = $_ENV[$name . '_FILE'];
        }
        if ($filePathEnv !== false && $filePathEnv !== '' && is_file($filePathEnv) && is_readable($filePathEnv)) {
            $contents = @file_get_contents($filePathEnv);
            if ($contents !== false) {
                return $this->trimSecret($contents);
            }
        }

        // Tier 3: /run/secrets/<lowercase var> — Docker Swarm default mount.
        $secretPath = '/run/secrets/' . strtolower($name);
        if (is_file($secretPath) && is_readable($secretPath)) {
            $contents = @file_get_contents($secretPath);
            if ($contents !== false) {
                return $this->trimSecret($contents);
            }
        }

        // Tier 1 returned an empty string (env var explicitly set to "") —
        // preserve that as-is so callers can distinguish "" from "unset".
        if ($envValue === '') {
            return '';
        }

        return null;
    }

    /**
     * Trim a single trailing newline (and surrounding whitespace) from a
     * secret file's contents. Secret files commonly have a trailing newline
     * that would corrupt tokens/keys if not stripped.
     */
    private function trimSecret(string $value): string
    {
        return rtrim($value, "\r\n");
    }

    /**
     * Resolve a config/secret by name through the native chain
     * (env -> <NAME>_FILE -> /run/secrets/<name>).
     *
     * Prefer this over raw getenv()/$_ENV[]/$_SERVER[] for ANY config or
     * secret access — it reads /run/secrets/<name> and <NAME>_FILE natively
     * and is immune to PHP-FPM clear_env. Works for keys that are NOT declared
     * as typed Env properties (e.g. JWT_PRIVATE_KEY, REDIS_PASSWORD).
     *
     * @param string      $name    The config/secret variable name.
     * @param string|null $default Returned when nothing resolves.
     */
    public static function secret(string $name, ?string $default = null): ?string
    {
        $env = self::get_instance();

        // Serve a previously-resolved typed property value if we have it.
        if (array_key_exists($name, $env->resolved)) {
            $value = $env->resolved[$name];
            return $value ?? $default;
        }

        // Ad-hoc key (not a typed property): run the chain and memoize.
        $value = $env->resolveRaw($name);
        $env->resolved[$name] = $value;
        return $value ?? $default;
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
