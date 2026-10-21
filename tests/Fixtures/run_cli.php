<?php
// Runs a framework CLI script the way `php stone` does: composer autoload first, then the script.
// argv[1] = cli script path
$root = dirname(__DIR__, 2);
define('ROOT_PATH', $root . DIRECTORY_SEPARATOR);
define('SRC_PATH', $root . '/src' . DIRECTORY_SEPARATOR);
define('CONFIG_PATH', SRC_PATH . 'config' . DIRECTORY_SEPARATOR);
require $root . '/vendor/autoload.php';
$script = $argv[1];
$argv = array_merge([$script], array_slice($argv, 2));
$_SERVER['argv'] = $argv;
require $script;
