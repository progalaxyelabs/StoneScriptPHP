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

// Check if .env file exists before initializing
// During setup (composer create-project), .env doesn't exist yet
$envFile = ROOT_PATH . '.env';
if (file_exists($envFile)) {
    // Initialize environment from .env file
    $env = Env::get_instance();

    // Define DEBUG_MODE with actual value from .env (if not already defined)
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
}
