<?php

/**
 * Database Seeder CLI
 *
 * Seeds database with initial data
 *
 * Usage:
 *   php seed.php rbac     - Seed RBAC roles and permissions
 *   php seed.php custom   - Run custom seeder from seeders directory
 */

// Set up paths
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
$rootPath = rtrim(ROOT_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
if (!defined('SRC_PATH')) {
    define('SRC_PATH', $rootPath . 'src' . DIRECTORY_SEPARATOR);
}

// Load composer autoloader
require_once $rootPath . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

use StoneScriptPHP\Env;

// Use $_SERVER['argv'] which may be modified by stone binary
$argv = $_SERVER['argv'];
$argc = $_SERVER['argc'];

// Parse command
$command = $argv[1] ?? 'help';

// Find vendor path for framework templates
$vendorPath = $rootPath . 'vendor' . DIRECTORY_SEPARATOR . 'progalaxyelabs' . DIRECTORY_SEPARATOR . 'stonescriptphp' . DIRECTORY_SEPARATOR;
if (!is_dir($vendorPath)) {
    // Development mode - Framework is sibling directory
    $vendorPath = dirname($rootPath) . DIRECTORY_SEPARATOR . 'StoneScriptPHP' . DIRECTORY_SEPARATOR;
}

// Get database connection
function getDbConnection() {
    if (!file_exists(ROOT_PATH . '.env')) {
        echo "Error: .env file not found. Please create it first.\n";
        exit(1);
    }

    $env = Env::get_instance();

    $host = $env->DATABASE_HOST;
    $port = $env->DATABASE_PORT;
    $user = $env->DATABASE_USER;
    $password = $env->DATABASE_PASSWORD;
    $dbname = $env->DATABASE_DBNAME;

    $connection_string = join(' ', [
        "host=$host",
        "port=$port",
        "user=$user",
        "password=$password",
        "dbname=$dbname"
    ]);

    $conn = pg_connect($connection_string);

    if (!$conn) {
        echo "Error: Failed to connect to database.\n";
        exit(1);
    }

    return $conn;
}

// Execute SQL seeder file
function runSeeder($conn, $seederPath, $seederName) {
    if (!file_exists($seederPath)) {
        echo "Error: Seeder file not found: $seederPath\n";
        return false;
    }

    echo "Running seeder: $seederName\n";

    $sql = file_get_contents($seederPath);

    // Begin transaction
    pg_query($conn, "BEGIN");

    try {
        $result = pg_query($conn, $sql);

        if (!$result) {
            throw new Exception(pg_last_error($conn));
        }

        pg_query($conn, "COMMIT");
        echo "✓ Seeder executed successfully\n";
        return true;

    } catch (Exception $e) {
        pg_query($conn, "ROLLBACK");
        echo "✗ Seeder failed: " . $e->getMessage() . "\n";
        return false;
    }
}

try {
    switch ($command) {
        case 'rbac':
            echo "Seeding RBAC (roles & permissions)...\n\n";

            $conn = getDbConnection();

            // Check if RBAC tables exist
            $tablesQuery = "SELECT COUNT(*) FROM information_schema.tables
                           WHERE table_schema = 'public'
                           AND table_name IN ('roles', 'permissions', 'user_roles', 'role_permissions')";
            $result = pg_query($conn, $tablesQuery);
            $count = pg_fetch_result($result, 0, 0);

            if ($count < 4) {
                echo "Error: RBAC tables not found. Please run migrations first:\n";
                echo "  php stone migrate up\n";
                exit(1);
            }

            // Run RBAC seeder from framework templates
            $seederPath = $vendorPath . 'src' . DIRECTORY_SEPARATOR . 'Templates' . DIRECTORY_SEPARATOR . 'Seeders' . DIRECTORY_SEPARATOR . 'rbac_seed.sql';
            $success = runSeeder($conn, $seederPath, 'rbac_seed.sql');

            pg_close($conn);

            if ($success) {
                echo "\n✅ RBAC seeding complete!\n";
                echo "\nDefault roles created:\n";
                echo "  - super_admin (all permissions)\n";
                echo "  - admin (most permissions)\n";
                echo "  - moderator (content + user viewing)\n";
                echo "  - user (basic content)\n";
                echo "  - guest (read-only)\n";
                exit(0);
            } else {
                exit(1);
            }
            break;

        case 'help':
        default:
            echo "Database Seeder\n";
            echo "===============\n\n";
            echo "Usage: php seed.php <command>\n\n";
            echo "Available commands:\n";
            echo "  rbac     Seed RBAC roles and permissions\n";
            echo "  help     Show this help message\n";
            echo "\n";
            echo "Examples:\n";
            echo "  php seed.php rbac     # Seed default roles and permissions\n";
            echo "\n";
            exit(0);
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
