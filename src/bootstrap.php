<?php

/**
 * StoneScriptPHP Framework Bootstrap
 *
 * This file is loaded via composer autoload.files when the framework is installed.
 * It initializes the framework environment.
 */

use StoneScriptPHP\Env;
use StoneScriptPHP\ExceptionHandler;

// These constants should be defined by the application before loading composer autoloader
// If not defined, provide defaults (though this is not recommended)
if (!defined('INDEX_START_TIME')) {
    define('INDEX_START_TIME', microtime(true));
}

if (!defined('ROOT_PATH')) {
    // Try to detect the application root (4 levels up from vendor/progalaxyelabs/stonescriptphp)
    define('ROOT_PATH', realpath(__DIR__ . '/../../../..') . DIRECTORY_SEPARATOR);
}

if (!defined('SRC_PATH')) {
    define('SRC_PATH', ROOT_PATH . 'src' . DIRECTORY_SEPARATOR);
}

if (!defined('CONFIG_PATH')) {
    define('CONFIG_PATH', SRC_PATH . 'config' . DIRECTORY_SEPARATOR);
}

// Temporary DEBUG_MODE for early bootstrap (before .env loads)
if (!defined('DEBUG_MODE')) {
    define('DEBUG_MODE', ($_SERVER['DEBUG_MODE'] ?? 'false') === 'true');
}

// Note: functions.php is already loaded via composer autoload.files
// Note: All Framework classes are autoloaded via PSR-4

// Initialize environment from .env file OR environment variables
// .env file is optional - Docker environments can use env vars directly
try {
    $env = Env::get_instance();

    // Define DEBUG_MODE with actual value from env (if not already defined)
    if (!defined('DEBUG_MODE')) {
        define('DEBUG_MODE', $env->DEBUG_MODE);
    }

    // Set timezone from environment
    date_default_timezone_set($env->TIMEZONE);

    // Register global exception handler AFTER DEBUG_MODE is defined
    ExceptionHandler::getInstance()->register();

    // Configure error reporting based on DEBUG_MODE
    if (DEBUG_MODE) {
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
    } else {
        error_reporting(E_ALL);
        ini_set('display_errors', 0);
    }

    // Log the request
    $method_and_url = ($_SERVER['REQUEST_METHOD'] ?? 'CLI') . ' ' . ($_SERVER['REQUEST_URI'] ?? '');
    log_debug("---------- {$method_and_url} ----------");

    // Initialize timings array for performance tracking
    $timings = [];

    // Load auth configuration.
    //
    // NOTE (v5.7.0): this file lives at ROOT_PATH/config/auth.php — the platform's
    // API-project root (docker/api/), NOT src/config/. A platform's real, canonical
    // auth config is src/config/auth.php, loaded by Application::run(). This
    // ROOT_PATH file is ONLY an optional legacy override for the gateway_url /
    // jwks_endpoint / jwks_cache_ttl values below — kept for backward compatibility
    // with any platform that already has one, never required. Most platforms have
    // no such file, and $authConfig correctly stays [] for them; that is fine, because
    // gateway_url below is sourced from Env (DB_GATEWAY_URL), not from this file.
    $authConfig = [];
    $authConfigFile = ROOT_PATH . 'config/auth.php';
    if (file_exists($authConfigFile)) {
        $authConfig = require $authConfigFile;
    }

    // Registry for singleton services
    $GLOBALS['__stonescript_services'] = [];

    // Register TokenValidator as singleton
    $GLOBALS['__stonescript_services']['TokenValidator'] = function() use ($authConfig, $env) {
        static $instance = null;
        if ($instance === null) {
            // See stonescript_resolve_gateway_url() in helpers.php — resolves from
            // Env::$DB_GATEWAY_URL (framework-required, already fail-loud at boot) with
            // an optional legacy $authConfig['gateway_url'] override. No localhost default.
            $gatewayUrl = stonescript_resolve_gateway_url($authConfig, $env);
            // Real API: StoneScriptDB\Auth\TokenValidator(string $gatewayBaseUrl) — a
            // single-argument constructor. jwks_endpoint/jwks_cache_ttl are no longer
            // accepted (they belonged to a since-removed constructor signature) and are
            // intentionally ignored here rather than passed positionally into the wrong
            // parameters.
            $instance = new \StoneScriptDB\Auth\TokenValidator($gatewayUrl);
        }
        return $instance;
    };

    // Middleware alias registry
    $GLOBALS['__stonescript_middleware_aliases'] = [
        'auth' => \StoneScriptPHP\Auth\Middleware\ValidateJwtMiddleware::class,
        'auth.required' => \StoneScriptPHP\Auth\Middleware\RequireAuthMiddleware::class,
        'tenant.required' => \StoneScriptPHP\Auth\Middleware\RequireTenantMiddleware::class,
        'role' => \StoneScriptPHP\Auth\Middleware\RequireRoleMiddleware::class,
    ];
} catch (Exception $e) {
    // Env initialization failed (e.g., missing required vars like DB_GATEWAY_URL)
    // This is expected during initial setup (composer create-project) or CLI tools
    // that don't need full framework initialization
    // Store the error for later retrieval if needed
    $GLOBALS['__stonescript_bootstrap_error'] = $e->getMessage();
}
