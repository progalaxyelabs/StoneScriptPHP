<?php

// Test bootstrap.
//
// composer's autoload.files (composer.json `autoload.files`) loads
// src/bootstrap.php during `vendor/autoload.php`, and that file already
// defines ROOT_PATH/SRC_PATH/CONFIG_PATH/DEBUG_MODE — guessing ROOT_PATH
// from a vendor-install layout. When PHPUnit runs from the framework's
// own checkout, that guess is wrong (resolves to the parent monorepo dir).
//
// We work around this by:
//   1) Loading composer autoload from a path computed from __DIR__,
//      not from any pre-existing ROOT_PATH constant.
//   2) Guarding test-side defines with `defined()` checks so we don't
//      redefine and trigger constant-redefinition warnings.

date_default_timezone_set('UTC');

$frameworkRoot = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR;

require_once $frameworkRoot . 'vendor/autoload.php';

if (!defined('DEBUG_MODE'))  define('DEBUG_MODE', 1);
if (!defined('ROOT_PATH'))   define('ROOT_PATH', $frameworkRoot);
if (!defined('SRC_PATH'))    define('SRC_PATH', $frameworkRoot . 'src' . DIRECTORY_SEPARATOR);
if (!defined('CONFIG_PATH')) define('CONFIG_PATH', SRC_PATH . 'config' . DIRECTORY_SEPARATOR);

// StoneScriptPHP\* and Tests\* are autoloaded via composer PSR-4.
// App\* is used by a handful of test fixtures under src/App/* — register a
// minimal autoloader so those still resolve.
spl_autoload_register(function ($class) use ($frameworkRoot) {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = $frameworkRoot . 'src' . DIRECTORY_SEPARATOR
          . str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
    if (file_exists($path)) {
        require_once $path;
    }
});
