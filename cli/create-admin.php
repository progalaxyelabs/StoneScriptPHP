<?php

/**
 * Create Admin User CLI
 *
 * Creates a system administrator user with super_admin role
 *
 * Usage:
 *   php create-admin.php                    - Interactive mode
 *   php create-admin.php --email=... --password=... --name=...   - Non-interactive
 */

// Set up paths
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
$rootPath = rtrim(ROOT_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

// Load composer autoloader
require_once $rootPath . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

use StoneScriptPHP\Env;

// Use $_SERVER['argv'] which may be modified by stone binary
$argv = $_SERVER['argv'];
$argc = $_SERVER['argc'];

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

// Parse command line arguments
$options = [];
for ($i = 1; $i < $argc; $i++) {
    if (preg_match('/^--([^=]+)=(.*)$/', $argv[$i], $matches)) {
        $options[$matches[1]] = $matches[2];
    }
}

// Interactive mode
if (empty($options)) {
    echo "Create System Administrator\n";
    echo "============================\n\n";

    echo "Enter admin email: ";
    $email = trim(fgets(STDIN));

    echo "Enter admin name: ";
    $name = trim(fgets(STDIN));

    echo "Enter password: ";
    // Disable echo for password input (Unix/Linux/Mac only)
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        system('stty -echo');
    }
    $password = trim(fgets(STDIN));
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        system('stty echo');
    }
    echo "\n";

    echo "Confirm password: ";
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        system('stty -echo');
    }
    $passwordConfirm = trim(fgets(STDIN));
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        system('stty echo');
    }
    echo "\n\n";

    if ($password !== $passwordConfirm) {
        echo "Error: Passwords do not match.\n";
        exit(1);
    }
} else {
    // Non-interactive mode
    $email = $options['email'] ?? '';
    $name = $options['name'] ?? '';
    $password = $options['password'] ?? '';
}

// Validate inputs
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo "Error: Valid email is required.\n";
    exit(1);
}

if (empty($name)) {
    echo "Error: Name is required.\n";
    exit(1);
}

if (empty($password) || strlen($password) < 8) {
    echo "Error: Password must be at least 8 characters.\n";
    exit(1);
}

try {
    $conn = getDbConnection();

    // Check if required tables exist
    $tablesQuery = "SELECT COUNT(*) FROM information_schema.tables
                   WHERE table_schema = 'public'
                   AND table_name IN ('users', 'roles', 'user_roles')";
    $result = pg_query($conn, $tablesQuery);
    $count = pg_fetch_result($result, 0, 0);

    if ($count < 3) {
        echo "Error: Required tables not found. Please run migrations first:\n";
        echo "  php stone migrate up\n";
        exit(1);
    }

    // Check if email/password auth is available
    $columnsQuery = "SELECT column_name FROM information_schema.columns
                    WHERE table_schema = 'public'
                    AND table_name = 'users'
                    AND column_name = 'password_hash'";
    $result = pg_query($conn, $columnsQuery);

    if (pg_num_rows($result) === 0) {
        echo "Error: Email/password authentication not enabled. Please run:\n";
        echo "  php stone generate auth:email-password\n";
        echo "  php stone migrate up\n";
        exit(1);
    }

    // Check if super_admin role exists
    $roleQuery = "SELECT role_id FROM roles WHERE name = 'super_admin'";
    $result = pg_query($conn, $roleQuery);

    if (pg_num_rows($result) === 0) {
        echo "Error: super_admin role not found. Please seed RBAC first:\n";
        echo "  php stone seed rbac\n";
        exit(1);
    }

    $superAdminRoleId = pg_fetch_result($result, 0, 0);

    // Check if email already exists
    $checkQuery = "SELECT user_id FROM users WHERE email = $1";
    $result = pg_query_params($conn, $checkQuery, [$email]);

    if (pg_num_rows($result) > 0) {
        echo "Error: User with email '$email' already exists.\n";
        exit(1);
    }

    // Hash password
    $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    // Begin transaction
    pg_query($conn, "BEGIN");

    // Insert user
    $insertUserQuery = "INSERT INTO users (email, display_name, password_hash, email_verified)
                       VALUES ($1, $2, $3, true)
                       RETURNING user_id";
    $result = pg_query_params($conn, $insertUserQuery, [$email, $name, $passwordHash]);

    if (!$result) {
        throw new Exception("Failed to create user: " . pg_last_error($conn));
    }

    $userId = pg_fetch_result($result, 0, 0);

    // Assign super_admin role
    $assignRoleQuery = "INSERT INTO user_roles (user_id, role_id) VALUES ($1, $2)";
    $result = pg_query_params($conn, $assignRoleQuery, [$userId, $superAdminRoleId]);

    if (!$result) {
        throw new Exception("Failed to assign super_admin role: " . pg_last_error($conn));
    }

    pg_query($conn, "COMMIT");
    pg_close($conn);

    echo "✅ System administrator created successfully!\n\n";
    echo "Details:\n";
    echo "  User ID: $userId\n";
    echo "  Email: $email\n";
    echo "  Name: $name\n";
    echo "  Role: super_admin\n\n";
    echo "You can now login with these credentials.\n";

    exit(0);

} catch (Exception $e) {
    if (isset($conn)) {
        pg_query($conn, "ROLLBACK");
        pg_close($conn);
    }
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
