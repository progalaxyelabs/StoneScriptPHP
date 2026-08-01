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

    // Database transport mode: gateway (default) | direct | pgandroid.
    // Selects the DbTransport implementation Database::fn() dispatches
    // through — see src/Db/DbTransport.php. Validated at boot (constructor,
    // below); an unrecognized value fails loud rather than surfacing as a
    // confusing error deep inside Database::initConnection().
    public string $DB_MODE = 'gateway';

    // Database - Direct Connection (DB_MODE=direct only). Needs the
    // pdo_pgsql PHP extension. Ignored in gateway/pgandroid mode.
    public string $DB_HOST = 'localhost';
    public int $DB_PORT = 5432;
    public string $DB_NAME = '';
    public string $DB_USER = 'postgres';
    public ?string $DB_PASSWORD = null;

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
    // Optional explicit override for the per-platform scoped bearer token
    // (format ssdb_pt_...) gateway v4.1.0+ requires on POST /admin/database/create,
    // POST /v2/migrate, POST /v2/migrate-all, GET /v2/migrate-all/{job_id}. When
    // unset, TenantProvisioner auto-provisions + caches this token at runtime via
    // POST /admin/platform-token using DB_GATEWAY_ADMIN_TOKEN — platforms do not
    // need to set this unless they want to pin a specific token (e.g. to avoid the
    // re-provision round trip, or after an explicit rotation).
    public ?string $DB_GATEWAY_PLATFORM_TOKEN = null;
    public string $PLATFORM_ID = '';
    public string $SCHEMA_NAME = 'v1_0';
    public string $DATABASE_ID = 'main';
    public ?string $MAIN_SCHEMA_NAME = null;
    public ?string $TENANT_SCHEMA_NAME = null;

    public ?string $ZEPTOMAIL_BOUNCE_ADDRESS = null;
    public ?string $ZEPTOMAIL_SENDER_EMAIL = null;
    public ?string $ZEPTOMAIL_SENDER_NAME = null;
    public ?string $ZEPTOMAIL_SEND_MAIL_TOKEN = null;

    // Test-domain email routing (MailerFactory) — mirrors the fleet's central
    // auth service's provider routing. When a recipient's domain matches TEST_EMAIL_DOMAIN and
    // MAILPIT_SMTP_* is configured, MailerFactory routes the email to Mailpit
    // (plain SMTP, no auth/TLS) instead of the production provider (ZeptoMail),
    // so test-address mail is captured in Mailpit in dev — and, if you wire
    // MAILPIT_SMTP_* in prod too, in prod. The Mailpit path needs symfony/mailer
    // (a require-dev dependency), so it is a dev/test feature by default; a
    // prod --no-dev build without symfony/mailer simply falls back to ZeptoMail.
    public ?string $TEST_EMAIL_DOMAIN = null;
    public ?string $MAILPIT_SMTP_HOST = null;
    public ?string $MAILPIT_SMTP_PORT = null;

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
    // No default. AUTH_SERVICE_URL is only required in 'external'/'hybrid' AUTH_MODE
    // (see Application::buildJwtHandler / buildAuthRouteOptions and
    // ExternalAuthConfig::__construct, which throw a loud RuntimeException when this
    // is empty). The old default of 'http://localhost:3139' silently pointed every
    // platform that forgot to set this env var at loopback instead of the real auth
    // service, and the failure only surfaced as an opaque "Failed to connect to
    // localhost port 3139" deep in a curl call — not at boot, where it belongs.
    public string $AUTH_SERVICE_URL = '';

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

    // No hardcoded guess here on purpose (was 'http://localhost:3000,http://localhost:4200'
    // — a generic default that happened to match nobody's real dev port and let CORS
    // silently, permanently fail for any platform that forgot to configure this).
    // Real default is computed in __construct() from src/config/allowed-origins.php
    // (if the app defines one), BEFORE the env-var override loop below runs — so an
    // explicit ALLOWED_ORIGINS env var still always wins. If neither is set, this
    // stays empty: CorsMiddleware then allows no origin at all (fail-closed), never
    // '*' — Access-Control-Allow-Credentials is always sent true, and browsers reject
    // (and it would be unsafe regardless of browser behavior) Access-Control-Allow-Origin:
    // * combined with credentials.
    public string $ALLOWED_ORIGINS = '';

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
     * boot WHEN DB_MODE=gateway (the default). A missing one fails fast at
     * construction time with a clear message rather than surfacing as a
     * silent runtime 500.
     *
     * Note: these are also covered by the typed properties above; they are
     * listed here so the boot-time validation is explicit and auditable.
     * DB_MODE=direct / pgandroid have no eager entry here — their own
     * required config (DB_NAME for direct; nothing for pgandroid, whose
     * bridge is host-provided) is validated lazily inside
     * Database::initConnection(), the same pattern DB_GATEWAY_SCHEMA_NAME
     * already uses for gateway mode (see Database.php).
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
        // Break a real reentrancy hazard: get_instance() only assigns
        // self::$_instance AFTER `new static()` (this constructor) fully
        // returns. src/config/allowed-origins.php is `require`d further down,
        // and it's an app-owned file this class doesn't control the contents
        // of — if it ever called Env::get_instance() itself (not an
        // unreasonable thing for someone to try), self::$_instance would
        // still be null at that point, triggering a second `new static()`,
        // which would read allowed-origins.php again, which would call
        // get_instance() again — infinite recursion, hard crash at boot.
        // Assigning $this here, before any app-level file is read, means a
        // reentrant get_instance() call gets back this same (still
        // mid-construction) instance instead of recursing. Any property read
        // through that reentrant path before this constructor finishes will
        // see not-yet-fully-resolved values (class defaults / whatever has
        // been resolved so far) rather than final ones — an acceptable, far
        // safer tradeoff than a boot crash. allowed-origins.php should use
        // getenv() directly (like every real platform's copy already does)
        // rather than Env::get_instance(), precisely to avoid depending on
        // this partially-initialized window at all.
        self::$_instance = $this;

        // Load .env file if it exists (optional for Docker environments)
        $env_file_path = ROOT_PATH . DIRECTORY_SEPARATOR . '.env';
        if (file_exists($env_file_path)) {
            $dotenv = \Dotenv\Dotenv::createImmutable(ROOT_PATH);
            $dotenv->load();
        }

        // ALLOWED_ORIGINS base: src/config/allowed-origins.php, if the app defines
        // one — read BEFORE the env-var override loop below, so an explicit
        // ALLOWED_ORIGINS env var (set via docker-compose/.env/CLI/secret) always
        // wins over it, matching how every other typed property here already
        // resolves (env > default). This is the file every platform already gets
        // from the skeleton; it was previously scaffolded but never actually read
        // by the framework. Config file wins over the hardcoded class default
        // (now empty); env var wins over the config file.
        if (defined('CONFIG_PATH')) {
            $fileOrigins = $this->loadOriginsFromFile(CONFIG_PATH . 'allowed-origins.php');
            if ($fileOrigins !== null) {
                $this->ALLOWED_ORIGINS = $fileOrigins;
            }
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

        // DB_MODE selects the database transport (gateway | direct |
        // pgandroid). Fail loud at boot on an unrecognized value — same
        // discipline as the required-secret checks below — instead of
        // deferring to a confusing failure deep inside
        // Database::initConnection().
        $validDbModes = ['gateway', 'direct', 'pgandroid'];
        if (!in_array($this->DB_MODE, $validDbModes, true)) {
            throw new Exception(sprintf(
                "Invalid DB_MODE '%s'. Must be one of: %s.",
                $this->DB_MODE,
                implode(', ', $validDbModes)
            ));
        }

        // Validate required secrets/config are present and non-empty.
        // Fail fast at boot with a clear message instead of a silent runtime 500.
        // Only DB_MODE=gateway (the default) has eager requirements here —
        // direct/pgandroid validate their own connection config lazily in
        // Database::initConnection() (see requiredSecrets docblock above).
        if ($this->DB_MODE === 'gateway') {
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
    }

    /**
     * Read an app-owned "list of strings" config file (e.g. allowed-origins.php)
     * and return it as a comma-joined string, or null if the file doesn't
     * exist / doesn't return a non-empty array. Extracted as its own method
     * (rather than inlined in __construct()) specifically so it's testable
     * against an arbitrary path — CONFIG_PATH is a global constant defined
     * once at bootstrap and can't be swapped per-test.
     *
     * The file is validated at the TOKEN level, BEFORE it is ever executed —
     * only a bare `return [...]` array-literal-of-strings shape is accepted.
     * This is not just a style rule: this method runs mid-Env::__construct()
     * (before get_instance() is safe to call reentrantly, before most of
     * Env's own properties are resolved), so any function/method call in
     * this file — Env::get_instance(), getenv(), array_merge(), anything —
     * runs in that same unsafe, partially-initialized window. Rejecting the
     * file at the token level means the code inside it is guaranteed to
     * never execute at all, not just "discouraged" by a comment.
     */
    protected function loadOriginsFromFile(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        $source = @file_get_contents($path);
        if ($source === false) {
            return null;
        }

        if (!$this->isPureArrayReturnSource($source)) {
            log_warning(
                "Env: '$path' is not a plain `return [...]` array of string literals — " .
                'ignoring it. Config files under src/config/ that Env reads at construction ' .
                'time must not call any function (including Env::get_instance() itself, ' .
                'getenv(), array_merge(), etc.) — this file is read before Env is safely ' .
                're-entrant. Falling back to the ALLOWED_ORIGINS env var / empty default.'
            );
            return null;
        }

        // Safe to execute now — validated above to contain nothing but a
        // literal array return.
        $origins = include $path;
        if (!is_array($origins) || empty($origins)) {
            return null;
        }

        return implode(',', $origins);
    }

    /**
     * Token-level check: $source must be nothing but an opening <?php tag,
     * optional comments/whitespace, and a single `return <array literal of
     * scalars>;` — no function/method calls, no `::`/`->`, no variables, no
     * `new`/`function`/`fn`/`include`/`require`/`static`. Deliberately
     * conservative (reject-by-default): anything this doesn't explicitly
     * recognize as safe array-literal syntax is rejected, not allowed
     * through.
     */
    private function isPureArrayReturnSource(string $source): bool
    {
        $tokens = @token_get_all($source);
        if ($tokens === false) {
            return false;
        }

        $forbiddenTokenIds = [
            T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_VARIABLE, T_FUNCTION, T_FN,
            T_NEW, T_STATIC, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE,
        ];
        if (defined('T_NULLSAFE_OBJECT_OPERATOR')) {
            $forbiddenTokenIds[] = T_NULLSAFE_OBJECT_OPERATOR;
        }

        $lastBareword = null; // text of the most recent T_STRING, for the "name(" function-call check below

        foreach ($tokens as $token) {
            if (!is_array($token)) {
                // Single-character structural token: ( ) [ ] , ; - etc.
                if ($token === '(' && $lastBareword !== null && strtolower($lastBareword) !== 'array') {
                    return false; // bareword immediately followed by '(' and it isn't the `array(...)` literal form — a function call
                }
                $lastBareword = null;
                continue;
            }

            [$id, $text] = $token;

            if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG], true)) {
                continue;
            }

            if (in_array($id, $forbiddenTokenIds, true)) {
                return false;
            }

            if ($id === T_STRING) {
                $lastBareword = $text;
                continue;
            }

            $lastBareword = null;
        }

        return true;
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
