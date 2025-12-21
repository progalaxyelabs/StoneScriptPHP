<?php

/**
 * Auth Generator
 *
 * Scaffolds authentication for various providers using composable migrations.
 *
 * Usage:
 *   php generate auth:<provider>
 *
 * Examples:
 *   php generate auth:email-password
 *   php generate auth:google
 *   php generate auth:linkedin
 *   php generate auth:apple
 *   php generate auth:api-key
 */

if (!defined('ROOT_PATH')) {
    // Check if running from 'api' subdirectory (common structure: project/api/)
    $cliDir = __DIR__;
    $frameworkDir = dirname($cliDir);
    $potentialApiDir = dirname(dirname(dirname($frameworkDir))); // vendor/progalaxyelabs/stonescriptphp -> api

    if (basename($potentialApiDir) === 'api' && file_exists($potentialApiDir . DIRECTORY_SEPARATOR . 'composer.json')) {
        // We're in project/api/vendor/progalaxyelabs/stonescriptphp/cli
        define('ROOT_PATH', $potentialApiDir . DIRECTORY_SEPARATOR);
    } else {
        // Standard structure - go up from framework directory
        define('ROOT_PATH', dirname($frameworkDir) . DIRECTORY_SEPARATOR);
    }
}
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
    echo "Scaffolds authentication using composable migrations.\n\n";
    echo "Usage: php generate auth:<provider>\n\n";
    echo "Available providers:\n";
    echo "  email-password  Traditional email/password authentication\n";
    echo "  google          Google OAuth (Sign in with Google)\n";
    echo "  linkedin        LinkedIn OAuth (Sign in with LinkedIn)\n";
    echo "  apple           Apple OAuth (Sign in with Apple)\n";
    echo "  api-key         API key authentication\n\n";
    echo "Examples:\n";
    echo "  php generate auth:email-password\n";
    echo "  php generate auth:google\n\n";
    echo "This will create:\n";
    echo "  - Database migration in migrations/\n";
    echo "  - Route handlers in src/App/Routes/Auth/\n";
    echo "  - Updates src/config/routes.php\n";
    echo "  - Updates composer.json (if needed)\n";
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
    echo "Example: php generate auth:email-password\n";
    exit(1);
}

$provider = substr($authCommand, 5); // Remove 'auth:' prefix
$supportedProviders = ['email-password', 'google', 'linkedin', 'apple', 'api-key'];

if (!in_array($provider, $supportedProviders)) {
    echo "Error: Unsupported provider '$provider'\n";
    echo "Supported providers: " . implode(', ', $supportedProviders) . "\n";
    exit(1);
}

echo "Generating $provider authentication...\n\n";

// Create necessary directories
$dirs = [
    'routes' => SRC_PATH . 'App' . DIRECTORY_SEPARATOR . 'Routes' . DIRECTORY_SEPARATOR . 'Auth',
    'migrations' => ROOT_PATH . 'migrations',
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

// Map provider to migration template
$migrationMapping = [
    'email-password' => 'auth-email-password',
    'google' => 'auth-oauth',
    'linkedin' => 'auth-oauth',
    'apple' => 'auth-oauth',
    'api-key' => 'auth-api-key',
];

$migrationDir = $migrationMapping[$provider];
$templatesPath = $vendorPath . 'src' . DIRECTORY_SEPARATOR . 'Templates' . DIRECTORY_SEPARATOR . 'Migrations' . DIRECTORY_SEPARATOR;

// Check if base users table migration exists
$baseUsersMigrationPath = $dirs['migrations'] . DIRECTORY_SEPARATOR . '001_create_users_table.sql';
if (!file_exists($baseUsersMigrationPath)) {
    echo "→ Creating base users table migration...\n";
    $baseTemplate = $templatesPath . 'base' . DIRECTORY_SEPARATOR . '001_create_users_table.sql';

    if (file_exists($baseTemplate)) {
        copy($baseTemplate, $baseUsersMigrationPath);
        echo "  ✓ Created: 001_create_users_table.sql\n";
    } else {
        echo "  ❌ Error: Base template not found at $baseTemplate\n";
        exit(1);
    }
}

// Copy provider-specific migration
$providerMigrations = glob($templatesPath . $migrationDir . DIRECTORY_SEPARATOR . '*.sql');

if (empty($providerMigrations)) {
    echo "❌ Error: No migration templates found for $provider\n";
    exit(1);
}

foreach ($providerMigrations as $migrationTemplate) {
    $migrationName = basename($migrationTemplate);
    $migrationDestination = $dirs['migrations'] . DIRECTORY_SEPARATOR . $migrationName;

    if (file_exists($migrationDestination)) {
        echo "→ Skipped (already exists): $migrationName\n";
        continue;
    }

    copy($migrationTemplate, $migrationDestination);
    echo "→ Created migration: $migrationName\n";
}

// Copy route templates based on provider
$routeTemplatesPath = $vendorPath . 'src' . DIRECTORY_SEPARATOR . 'Templates' . DIRECTORY_SEPARATOR . 'Auth' . DIRECTORY_SEPARATOR;

if ($provider === 'email-password') {
    $routeTemplates = [
        'LoginRoute.php.template',
        'RegisterRoute.php.template',
        'VerifyEmailRoute.php.template',
        'ResendVerificationRoute.php.template',
        'PasswordResetRoute.php.template',
        'PasswordResetConfirmRoute.php.template',
    ];

    echo "\n→ Creating route handlers...\n";
    foreach ($routeTemplates as $template) {
        $templatePath = $routeTemplatesPath . 'email-password' . DIRECTORY_SEPARATOR . $template;
        $routeName = str_replace('.template', '', $template);
        $routeDestination = $dirs['routes'] . DIRECTORY_SEPARATOR . $routeName;

        if (file_exists($routeDestination)) {
            echo "  Skipped (already exists): $routeName\n";
            continue;
        }

        if (file_exists($templatePath)) {
            copy($templatePath, $routeDestination);
            echo "  ✓ Created: $routeName\n";
        }
    }
} elseif (in_array($provider, ['google', 'linkedin', 'apple'])) {
    $providerCap = ucfirst($provider);
    $routeName = $providerCap . 'OauthRoute.php';
    $templatePath = $routeTemplatesPath . $provider . DIRECTORY_SEPARATOR . $routeName . '.template';
    $routeDestination = $dirs['routes'] . DIRECTORY_SEPARATOR . $routeName;

    echo "\n→ Creating route handler...\n";
    if (file_exists($routeDestination)) {
        echo "  Skipped (already exists): $routeName\n";
    } elseif (file_exists($templatePath)) {
        copy($templatePath, $routeDestination);
        echo "  ✓ Created: $routeName\n";
    }

    // Update composer.json to add OAuth client dependency
    if ($provider === 'google') {
        echo "\n→ Updating composer.json...\n";
        $composerPath = ROOT_PATH . 'composer.json';
        if (file_exists($composerPath)) {
            $composerContent = file_get_contents($composerPath);
            $composer = json_decode($composerContent, true);

            if (!isset($composer['require']['google/apiclient'])) {
                echo "  Running: composer require google/apiclient\n";
                exec('cd ' . escapeshellarg(ROOT_PATH) . ' && composer require google/apiclient', $output, $returnCode);

                if ($returnCode === 0) {
                    echo "  ✓ Added google/apiclient to composer.json\n";
                } else {
                    echo "  ⚠️  Failed to add google/apiclient automatically. Please run:\n";
                    echo "     composer require google/apiclient\n";
                }
            } else {
                echo "  ✓ google/apiclient already in composer.json\n";
            }
        }
    }
}

echo "\n✅ $provider authentication scaffolding complete!\n\n";
echo "Next steps:\n";
echo "1. Run migrations to create database tables:\n";
echo "   php stone migrate up\n\n";
echo "2. Check migration status:\n";
echo "   php stone migrate status\n\n";

if ($provider === 'email-password') {
    echo "3. Configure routes in src/config/routes.php:\n";
    echo "   'POST' => [\n";
    echo "       '/auth/register' => \\App\\Routes\\Auth\\RegisterRoute::class,\n";
    echo "       '/auth/login' => \\App\\Routes\\Auth\\LoginRoute::class,\n";
    echo "       '/auth/verify-email' => \\App\\Routes\\Auth\\VerifyEmailRoute::class,\n";
    echo "       '/auth/password-reset' => \\App\\Routes\\Auth\\PasswordResetRoute::class,\n";
    echo "   ]\n\n";
} elseif (in_array($provider, ['google', 'linkedin', 'apple'])) {
    $providerCap = ucfirst($provider);
    echo "3. Configure route in src/config/routes.php:\n";
    echo "   'POST' => [\n";
    echo "       '/auth/$provider' => \\App\\Routes\\Auth\\{$providerCap}OauthRoute::class,\n";
    echo "   ]\n\n";
    echo "4. Add {$providerCap} OAuth credentials to .env:\n";
    echo "   " . strtoupper($provider) . "_CLIENT_ID=your_client_id\n";
    echo "   " . strtoupper($provider) . "_CLIENT_SECRET=your_client_secret\n\n";
}
