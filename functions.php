<?php

use App\Env;
use Framework\ApiResponse;
use Framework\Logger;
use Framework\CacheManager;

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

function cache(): \Framework\Cache
{
    return CacheManager::instance();
}

function cache_invalidator(): \Framework\CacheInvalidator
{
    return CacheManager::invalidator();
}

function cache_remember(string $key, callable $callback, ?int $ttl = null): mixed
{
    return cache()->remember($key, $callback, $ttl);
}

function cache_tags(array $tags): \Framework\CacheTaggedStore
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
 * @return Framework\Auth\AuthenticatedUser|null
 */
function auth(): ?Framework\Auth\AuthenticatedUser
{
    return Framework\Auth\AuthContext::getUser();
}

/**
 * Get the authenticated user ID
 *
 * @return int|null
 */
function auth_id(): ?int
{
    return Framework\Auth\AuthContext::id();
}

/**
 * Check if user is authenticated
 *
 * @return bool
 */
function auth_check(): bool
{
    return Framework\Auth\AuthContext::check();
}
