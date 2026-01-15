<?php

use StoneScriptPHP\Env;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Logger;
use StoneScriptPHP\CacheManager;

// ===========================
// Logging Functions (PSR-3 Compatible)
// ===========================

function log_debug(string $message, array $context = [])
{
    Logger::get_instance()->log_debug($message, $context);
}

function log_info(string $message, array $context = [])
{
    Logger::get_instance()->log_info($message, $context);
}

function log_notice(string $message, array $context = [])
{
    Logger::get_instance()->log_notice($message, $context);
}

function log_warning(string $message, array $context = [])
{
    Logger::get_instance()->log_warning($message, $context);
}

function log_error(string $message, array $context = [])
{
    Logger::get_instance()->log_error($message, $context);
}

function log_critical(string $message, array $context = [])
{
    Logger::get_instance()->log_critical($message, $context);
}

function log_alert(string $message, array $context = [])
{
    Logger::get_instance()->log_alert($message, $context);
}

function log_emergency(string $message, array $context = [])
{
    Logger::get_instance()->log_emergency($message, $context);
}

function log_request(string $method, string $uri, int $status_code, float $duration_ms)
{
    Logger::get_instance()->log_request($method, $uri, $status_code, $duration_ms);
}

function res_ok($data, $message = '') {
    return new ApiResponse('ok', $message, $data);
}

function res_not_ok($message) {
    return new ApiResponse('not ok', $message);
}

function res_error($message) {
    $method_and_url = $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'];
    log_error('res_error: ' . $method_and_url . ' - ' . $message);
    return new ApiResponse('error', $message);
    
    // if(DEBUG_MODE) {
    //     return new ApiResponse('error', $message);
    // } else {
    //     return new ApiResponse('error', 'server error.');
    // }
}


function init_env()
{
    $env_file_path = ROOT_PATH . '.env';
    if (!file_exists($env_file_path)) {
        $message = 'missing .env file';
        throw new \Exception($message);
    }

    $env_properties = array_keys(get_class_vars(Env::class));

    $missing_keys = [];

    $dotenv_settings = parse_ini_file($env_file_path);
    foreach ($env_properties as $key) {
        if (array_key_exists($key, $dotenv_settings)) {
            Env::$$key = $dotenv_settings[$key];
        } else {
            log_debug("missing setting in .env file [$key]");
            $missing_keys[] = $key;
        }
    }

    $num_missing_keys = count($missing_keys);
    if ($num_missing_keys > 0) {
        throw new \Exception($num_missing_keys . ' Settings missing in .env file');
    }
}

function cache(): \StoneScriptPHP\Cache
{
    return CacheManager::instance();
}

function cache_invalidator(): \StoneScriptPHP\CacheInvalidator
{
    return CacheManager::invalidator();
}

function cache_remember(string $key, callable $callback, ?int $ttl = null): mixed
{
    return cache()->remember($key, $callback, $ttl);
}

function cache_tags(array $tags): \StoneScriptPHP\CacheTaggedStore
{
    return cache()->tags($tags);
}

function cache_get(string $key, mixed $default = null): mixed
{
    return cache()->get($key, $default);
}

function cache_set(string $key, mixed $value, ?int $ttl = null): bool
{
    return cache()->set($key, $value, $ttl);
}

function cache_forget(string $key): bool
{
    return cache()->forget($key);
}

function cache_flush(): bool
{
    return cache()->flush();
}

// ===========================
// Environment Helper
// ===========================

/**
 * Get environment variable from Env singleton
 *
 * @param string $key Environment variable key
 * @param mixed $default Default value if not set
 * @return mixed
 */
function env(string $key, mixed $default = null): mixed
{
    $env = \StoneScriptPHP\Env::get_instance();
    return $env->$key ?? $default;
}

// ===========================
// Authentication Functions
// ===========================

/**
 * Get the authenticated user
 *
 * Usage:
 *   $user = auth();          // Get authenticated user object
 *   $userId = auth()->user_id;
 *   $email = auth()->email;
 *
 * @return StoneScriptPHP\Auth\AuthenticatedUser|null
 */
function auth(): ?StoneScriptPHP\Auth\AuthenticatedUser
{
    return StoneScriptPHP\Auth\AuthContext::getUser();
}

/**
 * Get the authenticated user ID
 *
 * @return int|null
 */
function auth_id(): ?int
{
    return StoneScriptPHP\Auth\AuthContext::id();
}

/**
 * Check if user is authenticated
 *
 * @return bool
 */
function auth_check(): bool
{
    return StoneScriptPHP\Auth\AuthContext::check();
}

/**
 * Load full user data from database
 *
 * Usage:
 *   // With custom loader function
 *   $dbUser = auth_load($db, function($user, $db) {
 *       $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
 *       $stmt->execute([$user->user_id]);
 *       return $stmt->fetch(PDO::FETCH_ASSOC);
 *   });
 *
 *   // With database function class
 *   $dbUser = auth_load_fn($db, FnGetUserById::class);
 *
 * @param PDO $db Database connection
 * @param callable $loaderFn Function to load user: fn(AuthenticatedUser, PDO): array|object|null
 * @return array|object|null
 */
function auth_load(PDO $db, callable $loaderFn): array|object|null
{
    $user = auth();
    if (!$user) {
        return null;
    }

    return StoneScriptPHP\Auth\UserLoader::load($user, $db, $loaderFn);
}

/**
 * Load user data using a database function class
 *
 * @param PDO $db Database connection
 * @param string $functionClass Database function class (e.g., FnGetUserById::class)
 * @param string $method Method to call (default: 'run')
 * @return mixed
 */
function auth_load_fn(PDO $db, string $functionClass, string $method = 'run'): mixed
{
    $user = auth();
    if (!$user) {
        return null;
    }

    return StoneScriptPHP\Auth\UserLoader::loadWithFunction($user, $db, $functionClass, $method);
}

/**
 * Load user from database and merge with JWT claims
 *
 * @param PDO $db Database connection
 * @param callable $loaderFn Function to load user
 * @return array Merged user data
 */
function auth_load_merge(PDO $db, callable $loaderFn): array
{
    $user = auth();
    if (!$user) {
        return [];
    }

    return StoneScriptPHP\Auth\UserLoader::loadAndMerge($user, $db, $loaderFn);
}

// ===========================
// Multi-Tenancy Helper Functions
// ===========================

/**
 * Get current tenant
 *
 * @return StoneScriptPHP\Tenancy\Tenant|null
 */
function tenant(): ?StoneScriptPHP\Tenancy\Tenant
{
    return StoneScriptPHP\Tenancy\TenantContext::getTenant();
}

/**
 * Get current tenant ID
 *
 * @return int|string|null
 */
function tenant_id(): int|string|null
{
    return StoneScriptPHP\Tenancy\TenantContext::id();
}

/**
 * Get current tenant UUID
 *
 * @return string|null
 */
function tenant_uuid(): ?string
{
    return StoneScriptPHP\Tenancy\TenantContext::uuid();
}

/**
 * Get current tenant slug
 *
 * @return string|null
 */
function tenant_slug(): ?string
{
    return StoneScriptPHP\Tenancy\TenantContext::slug();
}

/**
 * Get current tenant database name
 *
 * @return string|null
 */
function tenant_db_name(): ?string
{
    return StoneScriptPHP\Tenancy\TenantContext::dbName();
}

/**
 * Check if tenant context is set
 *
 * @return bool
 */
function tenant_check(): bool
{
    return StoneScriptPHP\Tenancy\TenantContext::check();
}

/**
 * Get tenant database connection
 *
 * Returns a PDO connection to the current tenant's database.
 * Automatically uses global connection pooling for performance.
 *
 * @param array|null $config Database configuration (optional, uses global pool config if not provided)
 * @return PDO|null PDO connection or null if no tenant context
 */
function tenant_db(?array $config = null): ?PDO
{
    $tenant = tenant();

    if (!$tenant || !$tenant->dbName) {
        return null;
    }

    $pool = StoneScriptPHP\Database\DbConnectionPool::getInstance();

    // Set config if provided
    if ($config !== null) {
        $pool->setConfig($config);
    }

    return $pool->getConnection($tenant->dbName, $config);
}

/**
 * Get tenant metadata value
 *
 * @param string $key Metadata key
 * @param mixed $default Default value if key doesn't exist
 * @return mixed
 */
function tenant_get(string $key, mixed $default = null): mixed
{
    return StoneScriptPHP\Tenancy\TenantContext::get($key, $default);
}

// ===========================
// Database Connection Pool Functions
// ===========================

/**
 * Get database connection from global pool
 *
 * Returns a pooled PDO connection to any database.
 * Use this for non-tenant databases (auth, shared, etc.)
 *
 * @param string $database Database name
 * @param array|null $config Database configuration (optional, uses global pool config if not provided)
 * @return PDO
 */
function db_connection(string $database, ?array $config = null): PDO
{
    $pool = StoneScriptPHP\Database\DbConnectionPool::getInstance();

    // Set config if provided
    if ($config !== null) {
        $pool->setConfig($config);
    }

    return $pool->getConnection($database, $config);
}

/**
 * Set global database configuration
 *
 * Configure the connection pool once at application startup
 *
 * @param array $config Database configuration
 * @return void
 */
function db_set_config(array $config): void
{
    StoneScriptPHP\Database\DbConnectionPool::getInstance()->setConfig($config);
}

/**
 * Get database connection pool statistics
 *
 * @return array
 */
function db_pool_stats(): array
{
    $pool = StoneScriptPHP\Database\DbConnectionPool::getInstance();
    return [
        'active_connections' => $pool->getConnectionCount(),
        'databases' => $pool->getActiveConnections()
    ];
}

// ===========================
// Service Container Functions
// ===========================

/**
 * Get a singleton service from the service registry
 *
 * @param string $name Service name
 * @return mixed
 * @throws Exception if service not found
 */
function service(string $name): mixed
{
    if (!isset($GLOBALS['__stonescript_services'][$name])) {
        throw new Exception("Service not found: $name");
    }

    $factory = $GLOBALS['__stonescript_services'][$name];
    return $factory();
}

/**
 * Register a singleton service
 *
 * @param string $name Service name
 * @param callable $factory Factory function that returns the service instance
 * @return void
 */
function register_service(string $name, callable $factory): void
{
    $GLOBALS['__stonescript_services'][$name] = $factory;
}

// ===========================
// Middleware Functions
// ===========================

/**
 * Get middleware class by alias
 *
 * @param string $alias Middleware alias
 * @return string Middleware class name
 * @throws Exception if middleware alias not found
 */
function middleware(string $alias): string
{
    if (!isset($GLOBALS['__stonescript_middleware_aliases'][$alias])) {
        throw new Exception("Middleware alias not found: $alias");
    }

    return $GLOBALS['__stonescript_middleware_aliases'][$alias];
}

/**
 * Register a middleware alias
 *
 * @param string $alias Middleware alias
 * @param string $className Middleware class name
 * @return void
 */
function register_middleware(string $alias, string $className): void
{
    $GLOBALS['__stonescript_middleware_aliases'][$alias] = $className;
}
