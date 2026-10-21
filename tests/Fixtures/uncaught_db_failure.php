<?php
// Child process: makes a Database::fn() call fail through a gateway that echoes data, and lets the exception
// go UNCAUGHT. argv[1] = gateway base URL, argv[2] = "framework" (framework handler installed) | "bare" (plain PHP).
declare(strict_types=1);

$mode = $argv[2] ?? 'framework';
putenv('DB_GATEWAY_URL=' . $argv[1]);
putenv('DB_GATEWAY_PLATFORM=p');
putenv('DB_GATEWAY_SCHEMA_NAME=main');
putenv('DB_GATEWAY_TENANT_SCHEMA_NAME=tenant');
putenv('DEBUG_MODE=true');
define('DEBUG_MODE', true);
$root = dirname(__DIR__, 2);
define('ROOT_PATH', $root . DIRECTORY_SEPARATOR);
define('SRC_PATH', $root . '/src' . DIRECTORY_SEPARATOR);
define('CONFIG_PATH', SRC_PATH . 'config' . DIRECTORY_SEPARATOR);
require $root . '/vendor/autoload.php';

ini_set('display_errors', '1');
ini_set('log_errors', '0');
if ($mode === 'bare') {
    // Make sure no framework handler is active, exactly as in a worker script that skipped bootstrap.
    restore_exception_handler();
    ini_set('zend.exception_ignore_args', '0');
}

\StoneScriptPHP\Database::fn('create_user', ['jane.sentinel@example.test', '+919876500123', '$2y$10$sentinelhashvalue']);
