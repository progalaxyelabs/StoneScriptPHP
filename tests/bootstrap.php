<?php

// Test bootstrap file
// Loads minimal framework setup for testing

date_default_timezone_set('UTC');

define('DEBUG_MODE', 1);
define('ROOT_PATH', realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR);
define('SRC_PATH', ROOT_PATH . 'src' . DIRECTORY_SEPARATOR);
define('CONFIG_PATH', SRC_PATH . 'config' . DIRECTORY_SEPARATOR);
define('FRAMEWORK_PATH', ROOT_PATH . 'Framework' . DIRECTORY_SEPARATOR);

// Load composer autoloader
require_once ROOT_PATH . 'vendor/autoload.php';

// Load framework functions
require_once FRAMEWORK_PATH . 'functions.php';
require_once FRAMEWORK_PATH . 'error_handler.php';

// Setup custom autoloader for Framework and App classes
spl_autoload_register(function ($class) {
    $path = null;

    if(str_starts_with($class, 'App\\')) {
        // App\Routes\HomeRoute -> src/App/Routes/HomeRoute.php
        $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $class);
        $path = SRC_PATH . $relativePath . '.php';
    } else if(str_starts_with($class, 'Framework\\')) {
        // Framework\ApiResponse -> Framework/ApiResponse.php
        $className = substr($class, strlen('Framework\\'));
        $path = FRAMEWORK_PATH . str_replace('\\', DIRECTORY_SEPARATOR, $className) . '.php';
    } else if(str_starts_with($class, 'Tests\\Fixtures\\')) {
        // Tests\Fixtures\ExceptionThrowingRoute -> tests/_fixtures/ExceptionThrowingRoute.php
        $className = substr($class, strlen('Tests\\Fixtures\\'));
        $path = ROOT_PATH . 'tests/_fixtures/' . str_replace('\\', DIRECTORY_SEPARATOR, $className) . '.php';
    } else {
        return;
    }

    if ($path && file_exists($path)) {
        require_once $path;
    }
});
