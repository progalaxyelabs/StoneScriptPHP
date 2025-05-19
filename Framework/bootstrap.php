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


set_error_handler(function (int $error_number, string $message, string $file, int $line_number) {
    Logger::get_instance()->log_php_error($error_number, $message, $file, $line_number);
});

set_exception_handler(function (Throwable $exception) {
    Logger::get_instance()->log_php_exception($exception);
});


include 'Logger.php';
include 'functions.php';
include 'error_handler.php';

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

include APP_PATH . 'Env.php';

init_env();

define('DEBUG_MODE', Env::$DEBUG_MODE);

date_default_timezone_set(Env::$TIMEZONE);

if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

$timings = [];