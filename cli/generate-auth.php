<?php

/**
 * Auth Generator
 *
 * Scaffolds OAuth authentication for various providers (Google, LinkedIn, Apple, etc.)
 *
 * Usage:
 *   php generate auth:<provider>
 *
 * Examples:
 *   php generate auth:google
 *   php generate auth:linkedin
 *   php generate auth:apple
 */

if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
if (!defined('SRC_PATH')) define('SRC_PATH', ROOT_PATH . 'src' . DIRECTORY_SEPARATOR);
if (!defined('CONFIG_PATH')) define('CONFIG_PATH', SRC_PATH . 'App' . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR);

// Find vendor path (Framework templates location)
$vendorPath = ROOT_PATH . 'vendor' . DIRECTORY_SEPARATOR . 'progalaxyelabs' . DIRECTORY_SEPARATOR . 'stonescriptphp' . DIRECTORY_SEPARATOR;
if (!is_dir($vendorPath)) {
    // Development mode - Framework is sibling directory
    $vendorPath = dirname(ROOT_PATH) . DIRECTORY_SEPARATOR . 'StoneScriptPHP' . DIRECTORY_SEPARATOR;
}

// Check for help flag
if ($argc === 1 || ($argc === 2 && in_array($argv[1], ['--help', '-h', 'help']))) {
    echo "Auth Generator\n";
    echo "==============\n\n";
    echo "Scaffolds OAuth authentication for various providers.\n\n";
    echo "Usage: php generate auth:<provider>\n\n";
    echo "Available providers:\n";
    echo "  google     Google OAuth (Sign in with Google)\n";
    echo "  linkedin   LinkedIn OAuth (Sign in with LinkedIn)\n";
    echo "  apple      Apple OAuth (Sign in with Apple)\n\n";
    echo "Examples:\n";
    echo "  php generate auth:google\n";
    echo "  php generate auth:linkedin\n\n";
    echo "This will create:\n";
    echo "  - Route handler in src/App/Routes/\n";
    echo "  - Config file in src/App/Config/\n";
    echo "  - PostgreSQL table in src/postgresql/tables/\n";
    echo "  - PostgreSQL function in src/postgresql/functions/\n";
    echo "  - Updates src/App/Config/routes.php\n";
    exit(0);
}

if ($argc !== 2) {
    echo "Error: Invalid number of arguments\n";
    echo "Usage: php generate auth:<provider>\n";
    echo "Run 'php generate auth --help' for more information.\n";
    exit(1);
}

$authCommand = $argv[1];
if (!str_starts_with($authCommand, 'auth:')) {
    echo "Error: Invalid command format\n";
    echo "Usage: php generate auth:<provider>\n";
    echo "Example: php generate auth:google\n";
    exit(1);
}

$provider = substr($authCommand, 5); // Remove 'auth:' prefix
$supportedProviders = ['google', 'linkedin', 'apple'];

if (!in_array($provider, $supportedProviders)) {
    echo "Error: Unsupported provider '$provider'\n";
    echo "Supported providers: " . implode(', ', $supportedProviders) . "\n";
    exit(1);
}

$templatePath = $vendorPath . 'Templates' . DIRECTORY_SEPARATOR . 'Auth' . DIRECTORY_SEPARATOR . $provider . DIRECTORY_SEPARATOR;

if (!is_dir($templatePath)) {
    echo "Error: Template not found for provider '$provider'\n";
    echo "Expected: $templatePath\n";
    exit(1);
}

echo "Generating $provider OAuth authentication...\n\n";

// Create necessary directories
$dirs = [
    'routes' => SRC_PATH . 'App' . DIRECTORY_SEPARATOR . 'Routes',
    'config' => CONFIG_PATH,
    'postgresql_tables' => SRC_PATH . 'postgresql' . DIRECTORY_SEPARATOR . 'tables',
    'postgresql_functions' => SRC_PATH . 'postgresql' . DIRECTORY_SEPARATOR . 'functions',
];

foreach ($dirs as $name => $dir) {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            echo "Error: Failed to create $name directory: $dir\n";
            exit(1);
        }
        echo "Created directory: $dir\n";
    }
}

// Provider-specific naming
$providerCap = ucfirst($provider);
$routeClassName = $providerCap . 'OauthRoute';
$configFileName = $provider . '-oauth.php';

// Copy template files
$files = [
    'route' => [
        'template' => $templatePath . $providerCap . 'OauthRoute.php.template',
        'destination' => $dirs['routes'] . DIRECTORY_SEPARATOR . $routeClassName . '.php',
        'name' => 'Route handler'
    ],
    'config' => [
        'template' => $templatePath . $provider . '-oauth.php.template',
        'destination' => $dirs['config'] . $configFileName,
        'name' => 'Config file'
    ],
    'table' => [
        'template' => $templatePath . 'oauth_users.pgsql.template',
        'destination' => $dirs['postgresql_tables'] . DIRECTORY_SEPARATOR . 'oauth_users.pgsql',
        'name' => 'PostgreSQL table'
    ],
    'function' => [
        'template' => $templatePath . 'upsert_oauth_user.pgsql.template',
        'destination' => $dirs['postgresql_functions'] . DIRECTORY_SEPARATOR . 'upsert_oauth_user.pgsql',
        'name' => 'PostgreSQL function'
    ],
];

foreach ($files as $type => $file) {
    if (!file_exists($file['template'])) {
        echo "Warning: Template not found: {$file['template']}\n";
        continue;
    }

    if (file_exists($file['destination'])) {
        echo "Skipped (already exists): {$file['name']} - {$file['destination']}\n";
        continue;
    }

    $content = file_get_contents($file['template']);
    file_put_contents($file['destination'], $content);
    echo "✓ Created {$file['name']}: {$file['destination']}\n";
}

// Update routes.php
$routesConfigPath = CONFIG_PATH . 'routes.php';

if (!file_exists($routesConfigPath)) {
    echo "\nWarning: routes.php not found at $routesConfigPath\n";
    echo "You'll need to manually add the route:\n";
    echo "  'POST' => [\n";
    echo "    '/{$provider}-oauth' => \\App\\Routes\\{$routeClassName}::class,\n";
    echo "  ]\n";
} else {
    $routesContent = file_get_contents($routesConfigPath);
    $routePath = "/{$provider}-oauth";
    $routeEntry = "        '$routePath' => \\App\\Routes\\$routeClassName::class,\n";

    // Check if route already exists
    if (strpos($routesContent, $routePath) !== false) {
        echo "\nSkipped: Route already exists in routes.php\n";
    } else {
        // Add to POST method array
        $pattern = "/('POST'\s*=>\s*\[)([^\]]*?)(\s*\])/s";

        if (preg_match($pattern, $routesContent)) {
            $routesContent = preg_replace($pattern, "$1$2$routeEntry$3", $routesContent);
            file_put_contents($routesConfigPath, $routesContent);
            echo "✓ Updated routes.php with POST $routePath\n";
        } else {
            echo "\nWarning: Could not automatically update routes.php\n";
            echo "Please add manually:\n";
            echo "  'POST' => [\n";
            echo "    '$routePath' => \\App\\Routes\\$routeClassName::class,\n";
            echo "  ]\n";
        }
    }
}

echo "\n✅ $providerCap OAuth setup complete!\n\n";
echo "Next steps:\n";
echo "1. Run database migrations:\n";
echo "   php stone migrate\n\n";
echo "2. Generate typed PHP model from PostgreSQL function:\n";
echo "   php stone generate model upsert_oauth_user.pgsql\n\n";
echo "3. Update the route handler to use the generated model:\n";
echo "   Edit: {$dirs['routes']}" . DIRECTORY_SEPARATOR . "$routeClassName.php\n\n";
echo "4. Configure $providerCap OAuth credentials:\n";
echo "   Edit: {$dirs['config']}$configFileName\n\n";
echo "5. Add GOOGLE_CLIENT_ID to your .env file\n\n";
