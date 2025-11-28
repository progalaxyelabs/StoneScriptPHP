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
define('INDEX_START_TIME', microtime(true));
date_default_timezone_set('UTC');
define('ROOT_PATH', realpath(__DIR__ . '/../../') . DIRECTORY_SEPARATOR);
define('SRC_PATH', ROOT_PATH . 'src' . DIRECTORY_SEPARATOR);
define('CONFIG_PATH', SRC_PATH . 'config' . DIRECTORY_SEPARATOR);
define('FRAMEWORK_PATH', ROOT_PATH . 'Framework' . DIRECTORY_SEPARATOR);

// Load framework
require_once FRAMEWORK_PATH . 'Env.php';
require_once FRAMEWORK_PATH . 'Migrations.php';

use Framework\Migrations;
use Framework\Env;

// Parse command line arguments
$command = $argv[1] ?? 'help';

// Allow help command without .env file
if ($command !== 'help' && !file_exists(ROOT_PATH . '.env')) {
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
            echo "Migration status command - Coming soon!\n";
            echo "This will show:\n";
            echo "  - Applied migrations\n";
            echo "  - Pending migrations\n";
            echo "  - Current database state\n";
            exit(0);
            break;

        case 'up':
            echo "Migration up command - Coming soon!\n";
            echo "This will apply all pending migrations.\n";
            exit(0);
            break;

        case 'down':
            echo "Migration down command - Coming soon!\n";
            echo "This will rollback the last migration.\n";
            exit(0);
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
