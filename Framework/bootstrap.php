<?php

use Framework\Logger;
use function Framework\e500;

define('INDEX_START_TIME', microtime(true));

date_default_timezone_set('UTC');

define('DEBUG_MODE', 0);

if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

set_error_handler(function(int $error_number, string $message, string $file, int $line_number) {
    Logger::get_instance()->log_php_error($error_number, $message, $file, $line_number);
});

set_exception_handler(function(Throwable $exception) {
    Logger::get_instance()->log_php_exception($exception);
});

$timings = [];

define('ROOT_PATH', realpath('..' . DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
define('SRC_PATH', ROOT_PATH . 'src' . DIRECTORY_SEPARATOR);
define('CONFIG_PATH', SRC_PATH . 'config' . DIRECTORY_SEPARATOR);
define('FRAMEWORK_PATH', ROOT_PATH . 'Framework' . DIRECTORY_SEPARATOR);


include 'functions.php';
include FRAMEWORK_PATH . 'error_handler.php';


spl_autoload_register(function ($class) {
    if(str_starts_with($class, 'App\\')) {
        $path = SRC_PATH . str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';    
    } else if(str_starts_with($class, 'Framework\\')) {
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


