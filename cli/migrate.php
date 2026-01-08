<?php

/**
 * Migration CLI Tool (Gateway Mode - v3+)
 *
 * In StoneScriptPHP v3+, migrations are handled via gateway registration.
 * This command registers your schema with the gateway, which automatically
 * applies migrations and deploys functions.
 *
 * Usage:
 *   php stone migrate          - Register/update schema with gateway
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
$command = $argv[1] ?? 'register';

// Allow help command without .env file
if ($command !== 'help' && !file_exists($rootPath . '.env')) {
    echo "Error: .env file not found. Run: php stone setup\n";
    exit(1);
}

try {
    switch ($command) {
        case 'register':
        case '':
            // Default: Register schema with gateway
            echo "=== StoneScriptPHP Schema Registration ===\n\n";

            $env = Env::get_instance();
            echo "Gateway URL: {$env->DB_GATEWAY_URL}\n";
            echo "Platform: {$env->DB_GATEWAY_PLATFORM}\n";
            echo "Tenant ID: " . ($env->DB_GATEWAY_TENANT_ID ?? '<main>') . "\n\n";

            // Call gateway:register script
            echo "Registering schema with gateway...\n\n";
            $registerScript = $rootPath . 'vendor/progalaxyelabs/stonescriptphp/cli/gateway-register.php';

            if (!file_exists($registerScript)) {
                throw new Exception("gateway-register.php not found. Please run: composer install");
            }

            // Include and execute the registration script
            require $registerScript;
            break;

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

            echo "To register/update schema, run: php stone migrate\n";
            exit(0);
            break;

        case 'help':
        default:
            echo "StoneScriptPHP Migration Tool (v3+ Gateway Mode)\n";
            echo "=================================================\n\n";
            echo "Usage: php stone migrate [command]\n\n";
            echo "Available commands:\n";
            echo "  (default)  Register schema with gateway (applies migrations & deploys functions)\n";
            echo "  status     Show gateway connection status\n";
            echo "  help       Show this help message\n";
            echo "\n";
            echo "Examples:\n";
            echo "  php stone migrate          # Register/update schema with gateway\n";
            echo "  php stone migrate status   # Check gateway connection\n";
            echo "\n";
            echo "Note: StoneScriptPHP v3+ uses gateway-only mode.\n";
            echo "Migrations are applied automatically when you register your schema.\n";
            echo "\n";
            exit(0);
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
