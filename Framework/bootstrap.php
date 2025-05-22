<?php

use App\Env;
use Framework\Logger;
use function Framework\e500;

define('INDEX_START_TIME', microtime(true));
define('ROOT_PATH', realpath('..' . DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
define('SRC_PATH', ROOT_PATH . 'src' . DIRECTORY_SEPARATOR);
define('APP_PATH', SRC_PATH . 'App' . DIRECTORY_SEPARATOR);
define('CONFIG_PATH', SRC_PATH . 'config' . DIRECTORY_SEPARATOR);
define('FRAMEWORK_PATH', ROOT_PATH . 'Framework' . DIRECTORY_SEPARATOR);

include APP_PATH . 'Env.php';

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
            $missing_keys[] = $key;
        }
    }

    $num_missing_keys = count($missing_keys);
    if ($num_missing_keys > 0) {
        throw new \Exception($num_missing_keys . ' Settings missing in .env file');
    }
}

init_env();

define('DEBUG_MODE', Env::$DEBUG_MODE);
date_default_timezone_set(Env::$TIMEZONE);

include 'Logger.php';
include 'functions.php';
include 'error_handler.php';


set_error_handler(function (int $error_number, string $message, string $file, int $line_number) {
    Logger::get_instance()->log_php_error($error_number, $message, $file, $line_number);
});

set_exception_handler(function (Throwable $exception) {
    Logger::get_instance()->log_php_exception($exception);
});

spl_autoload_register(function ($class) {
    // log_debug('spl_autolaod_register: ' . $class);
    if (str_starts_with($class, 'App\\')) {
        $path = SRC_PATH . str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
    } else if (str_starts_with($class, 'Framework\\')) {
        $path = ROOT_PATH . str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
    } else {
        log_error('spl_autolaod_register: Unknown class ' . $class);
        return;
    }

    if (DEBUG_MODE) {
        include $path;
    } else {
        try {
            include $path;
        } catch (Error $e) {
            // e500($e->getMessage());
            log_error($e->getMessage());
            e500('Failed to load file');
        }
    }
});


$method_and_url = $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'];
log_debug("---------- {$method_and_url} ----------}");

if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

$timings = [];
