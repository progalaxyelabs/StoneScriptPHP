<?php

/**
 * Migration CLI Tool (Gateway Mode - v3+)
 *
 * In StoneScriptPHP v3+, migrations are handled via gateway registration.
 * This command registers your schema with the gateway, which automatically
 * applies migrations and deploys functions.
 *
 * Usage:
 *   php stone migrate          - Show help (use the gateway:* commands to register/migrate)
 *   php stone migrate status   - Show gateway connection status
 */

// Set up paths
if (!defined('INDEX_START_TIME')) {
    define('INDEX_START_TIME', microtime(true));
}
date_default_timezone_set('UTC');
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
$rootPath = rtrim(ROOT_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

// Load composer autoloader
require_once $rootPath . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
require_once __DIR__ . '/helpers/cli-guard.php';
cli_install_guard();


use StoneScriptPHP\Env;
use StoneScriptPHP\Database;
use StoneScriptDB\GatewayClient;
use StoneScriptDB\GatewayException;

// Define DEBUG_MODE for CLI (defaults to false)
if (!defined('DEBUG_MODE')) {
    define('DEBUG_MODE', false);
}

// Use $_SERVER['argv'] which may be modified by stone binary
$argv = $_SERVER['argv'];
$argc = $_SERVER['argc'];

// Parse command line arguments
$command = $argv[1] ?? '';

// Allow help command without .env file
if (!in_array($command, ['help', ''], true) && !file_exists($rootPath . '.env')) {
    echo "Error: .env file not found. Run: php stone setup\n";
    exit(1);
}

try {
    switch ($command) {
        case 'register':
            // The old schema-registration flow lived in a script that no longer ships.
            echo "Error: 'php stone migrate register' was removed.\n\n";
            echo "Use the gateway commands instead:\n";
            echo "  php stone gateway:register-main   # one-time: register the platform + create the main database\n";
            echo "  php stone gateway:migrate-main    # apply schema changes to the main database\n";
            exit(1);

        case 'status':
            // Show gateway connection status
            echo "=== Gateway Connection Status ===\n\n";

            $env = Env::get_instance();
            echo "Gateway URL: {$env->DB_GATEWAY_URL}\n";
            echo "Platform: {$env->DB_GATEWAY_PLATFORM}\n";
            echo "Tenant ID: " . ($env->DB_GATEWAY_TENANT_ID ?? '<main>') . "\n\n";

            $client = Database::getGatewayClient();

            echo "Health Check: ";
            if ($client->healthCheck()) {
                echo "✓ Gateway is reachable\n";
            } else {
                echo "✗ Gateway unavailable\n";
                echo "Error: " . ($client->getLastError() ?? 'Connection failed') . "\n";
                exit(1);
            }

            echo "Connected: " . ($client->isConnected() ? 'Yes' : 'Not yet') . "\n\n";

            echo "To register/migrate the schema, run: php stone gateway:register-main (once), then php stone gateway:migrate-main\n";
            exit(0);
            break;

        case 'help':
        default:
            // Unknown subcommand (or none): print the help but fail, so scripts notice. Explicit
            // `help` is a success.
            $helpExit = ($command === 'help') ? 0 : 2;
            echo "StoneScriptPHP Migration Tool (v3+ Gateway Mode)\n";
            echo "=================================================\n\n";
            echo "Usage: php stone migrate [command]\n\n";
            echo "Available commands:\n";
            echo "  status     Show gateway connection status\n";
            echo "  help       Show this help message (also the default)\n";
            echo "\n";
            echo "Examples:\n";
            echo "  php stone migrate status                # Check gateway connection\n";
            echo "  php stone gateway:register-main         # One-time: register platform + create main database\n";
            echo "  php stone gateway:migrate-main          # Apply schema changes to the main database\n";
            echo "  php stone gateway:migrate-tenant --database-id=<id>   # Migrate one tenant database\n";
            echo "\n";
            echo "Note: StoneScriptPHP uses gateway-only mode; schema changes go through the gateway:* commands.\n";
            echo "\n";
            exit($helpExit);
    }
} catch (Exception $e) {
    echo "Error: " . \StoneScriptPHP\Persistence\SafeDbContext::message($e) . "\n";
    exit(1);
}
