<?php
/**
 * Generate Command Dispatcher
 * Routes to specific generators based on subcommand
 *
 * Usage: php stone generate <subcommand> [args]
 */

// Determine the root path
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

// Use $_SERVER['argv'] which may be modified by stone binary
$argv = $_SERVER['argv'];
$argc = $_SERVER['argc'];

// Show help if no subcommand
if ($argc === 1 || ($argc === 2 && in_array($argv[1], ['--help', '-h', 'help']))) {
    echo "Generate Command\n";
    echo "================\n\n";
    echo "Usage: php stone generate <subcommand> [args]\n\n";
    echo "Available subcommands:\n";
    echo "  route <method> <path>        Generate route handler\n";
    echo "  model <function.pgsql>       Generate model from PostgreSQL function\n";
    echo "  contract [ClassName]         Generate contracts from route handlers\n";
    echo "  client                       Generate TypeScript client\n";
    echo "  jwt                          Generate JWT RSA keypair\n";
    echo "  auth:email-password          Generate email/password authentication\n";
    echo "  auth:google                  Generate Google OAuth authentication\n";
    echo "  auth:linkedin                Generate LinkedIn OAuth authentication\n";
    echo "  auth:apple                   Generate Apple OAuth authentication\n";
    echo "  auth:api-key                 Generate API key authentication\n";
    echo "  cache:redis                  Generate Redis caching support\n\n";
    echo "Examples:\n";
    echo "  php stone generate route post /login\n";
    echo "  php stone generate contract\n";
    echo "  php stone generate auth:email-password\n";
    echo "  php stone generate cache:redis\n";
    exit(0);
}

// Get subcommand
$subcommand = $argv[1] ?? '';

// Map subcommands to their CLI files
$generators = [
    'route' => 'cli/generate-route.php',
    'model' => 'cli/generate-model.php',
    'contract' => 'cli/generate-contract.php',
    'client' => 'cli/generate-client.php',
    'jwt' => 'cli/generate-jwt-keys.php',
    'auth:email-password' => 'cli/generate-auth.php',
    'auth:google' => 'cli/generate-auth.php',
    'auth:linkedin' => 'cli/generate-auth.php',
    'auth:apple' => 'cli/generate-auth.php',
    'auth:api-key' => 'cli/generate-auth.php',
    'cache:redis' => 'cli/generate-cache-redis.php',
];

// Check if subcommand exists
if (!isset($generators[$subcommand])) {
    echo "Error: Unknown subcommand '$subcommand'\n";
    echo "Run 'php stone generate --help' for available subcommands\n";
    exit(1);
}

// Get the generator file
$generatorFile = ROOT_PATH . $generators[$subcommand];

if (!file_exists($generatorFile)) {
    echo "Error: Generator file not found: $generatorFile\n";
    exit(1);
}

// Remove 'generate' and subcommand from argv, keep remaining args
// Example: ['stone', 'generate', 'route', 'post', '/login']
//       => ['stone', 'route', 'post', '/login']
array_splice($_SERVER['argv'], 1, 1); // Remove 'generate'
$_SERVER['argc'] = count($_SERVER['argv']);

// Execute the generator
require $generatorFile;
