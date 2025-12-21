<?php

/**
 * Migration CLI Tool
 *
 * Commands:
 *   php migrate.php verify    - Check for database drift
 *   php migrate.php status    - Show migration status (coming soon)
 *   php migrate.php up        - Apply pending migrations (coming soon)
 *   php migrate.php down      - Rollback last migration (coming soon)
 *   php migrate.php generate  - Generate migration from changes (coming soon)
 */

// Set up paths
if (!defined('INDEX_START_TIME')) {
    define('INDEX_START_TIME', microtime(true));
}
date_default_timezone_set('UTC');
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
// Ensure ROOT_PATH has trailing separator
$rootPath = rtrim(ROOT_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
if (!defined('SRC_PATH')) {
    define('SRC_PATH', $rootPath . 'src' . DIRECTORY_SEPARATOR);
}
if (!defined('CONFIG_PATH')) {
    define('CONFIG_PATH', $rootPath . 'src' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR);
}

// Load composer autoloader
require_once $rootPath . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

use StoneScriptPHP\Migrations;
use StoneScriptPHP\Env;

// Define DEBUG_MODE for CLI (defaults to false)
if (!defined('DEBUG_MODE')) {
    define('DEBUG_MODE', false);
}

// Use $_SERVER['argv'] which may be modified by stone binary
$argv = $_SERVER['argv'];
$argc = $_SERVER['argc'];

// Parse command line arguments
$command = $argv[1] ?? 'help';

// Allow help command without .env file
if ($command !== 'help' && !file_exists($rootPath . '.env')) {
    echo "Error: .env file not found. Please create it from the 'env' template.\n";
    echo "Run 'php migrate.php help' for usage information.\n";
    exit(1);
}

try {
    switch ($command) {
        case 'verify':
            $migrations = new Migrations();
            $migrations->verify();
            exit($migrations->getExitCode());
            break;

        case 'status':
            $migrations = new Migrations();
            $migrations->status();
            exit(0);
            break;

        case 'up':
            $migrations = new Migrations();
            $executed = $migrations->up();
            exit(empty($executed) ? 1 : 0);
            break;

        case 'down':
            $migrations = new Migrations();
            $rolledBack = $migrations->down();
            exit(empty($rolledBack) ? 1 : 0);
            break;

        case 'generate':
            echo "Migration generate command - Coming soon!\n";
            echo "This will:\n";
            echo "  1. Run verify to detect changes\n";
            echo "  2. Generate timestamped migration file\n";
            echo "  3. Create both up and down migrations\n";
            exit(0);
            break;

        case 'help':
        default:
            echo "StoneScriptPHP Migration Tool\n";
            echo "==============================\n\n";
            echo "Usage: php migrate.php <command>\n\n";
            echo "Available commands:\n";
            echo "  verify     Check for database drift (compares DB with source files)\n";
            echo "  status     Show migration status [COMING SOON]\n";
            echo "  up         Apply pending migrations [COMING SOON]\n";
            echo "  down       Rollback last migration [COMING SOON]\n";
            echo "  generate   Generate migration from detected changes [COMING SOON]\n";
            echo "  help       Show this help message\n";
            echo "\n";
            echo "Examples:\n";
            echo "  php migrate.php verify     # Check if database matches source files\n";
            echo "\n";
            exit(0);
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
