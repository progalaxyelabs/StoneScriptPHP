<?php
/**
 * Initialize StoneScriptPHP in Existing Project
 * For use when installed via: composer require progalaxyelabs/stonescriptphp
 *
 * Usage: vendor/bin/stone init [template]
 */

// Detect if we're running from vendor/bin (require mode)
$isVendorMode = strpos(__DIR__, 'vendor/progalaxyelabs/stonescriptphp') !== false;

if ($isVendorMode) {
    // Running from vendor/bin/stone - find project root
    $vendorDir = dirname(dirname(dirname(__DIR__)));
    $projectRoot = dirname($vendorDir);
    require_once $vendorDir . '/autoload.php';
} else {
    // Running from project root stone command
    require_once __DIR__ . '/../vendor/autoload.php';
    $projectRoot = dirname(__DIR__);
}

class Init {
    private string $projectRoot;
    private string $frameworkPath;
    private bool $isVendorMode;

    public function __construct(string $projectRoot, bool $isVendorMode)
    {
        $this->projectRoot = $projectRoot;
        $this->isVendorMode = $isVendorMode;

        if ($isVendorMode) {
            $this->frameworkPath = $projectRoot . '/vendor/progalaxyelabs/stonescriptphp';
        } else {
            $this->frameworkPath = $projectRoot;
        }
    }

    public function run(array $args): void
    {
        $this->printBanner();

        // Check if already initialized
        if ($this->isAlreadyInitialized()) {
            echo "⚠️  StoneScriptPHP already initialized in this directory\n";
            echo "Found: .env and src/App/Routes\n\n";

            $answer = $this->ask("Reinitialize anyway? This will overwrite files (y/N)", "n");
            if (strtolower($answer) !== 'y') {
                echo "Cancelled.\n";
                exit(0);
            }
        }

        // Template selection
        $template = $args[0] ?? null;
        if (!$template) {
            $template = $this->selectTemplate();
        }

        if ($template && $template !== 'skip') {
            $this->scaffoldFromTemplate($template);
        } else {
            $this->createMinimalStructure();
        }

        $this->generateEnv();
        $this->generateKeys();
        $this->createStoneWrapper();
        $this->showNextSteps();
    }

    private function isAlreadyInitialized(): bool
    {
        return file_exists($this->projectRoot . '/.env')
            && is_dir($this->projectRoot . '/src/App/Routes');
    }

    private function selectTemplate(): string
    {
        echo "\n📦 Choose a starter template:\n\n";
        echo "  1) Basic API - Simple REST API with PostgreSQL\n";
        echo "  2) Fullstack - Angular + API + Real-time notifications\n";
        echo "  3) Microservice - Lightweight service template\n";
        echo "  4) SaaS Boilerplate - Multi-tenant with subscriptions\n";
        echo "  5) Skip (minimal setup)\n\n";

        $choice = readline("Enter choice (1-5): ");

        $templates = [
            '1' => 'basic-api',
            '2' => 'fullstack-angular',
            '3' => 'microservice',
            '4' => 'saas-boilerplate',
            '5' => 'skip'
        ];

        return $templates[$choice] ?? 'skip';
    }

    private function scaffoldFromTemplate(string $template): void
    {
        $templatePath = $this->frameworkPath . '/starters/' . $template;

        if (!is_dir($templatePath)) {
            echo "❌ Template not found: $template\n";
            echo "Creating minimal structure instead...\n\n";
            $this->createMinimalStructure();
            return;
        }

        echo "\n📝 Scaffolding from $template template...\n";

        // Copy files (excluding .git, .gitkeep stays)
        $this->recursiveCopy($templatePath, $this->projectRoot, ['.git', '.gitkeep']);

        echo "✅ Template scaffolded successfully!\n";
        echo "📁 Files created from template\n\n";
    }

    private function createMinimalStructure(): void
    {
        echo "\n📁 Creating minimal project structure...\n\n";

        $directories = [
            'src/App/Routes',
            'src/App/Models',
            'src/App/Database/Migrations',
            'public',
            'logs',
            'keys'
        ];

        foreach ($directories as $dir) {
            $path = $this->projectRoot . '/' . $dir;
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
                echo "  ✓ Created $dir/\n";
            }
        }

        // Create public/index.php
        $this->createIndexFile();

        // Create example route
        $this->createExampleRoute();

        echo "\n✅ Minimal structure created\n\n";
    }

    private function createIndexFile(): void
    {
        $indexPath = $this->projectRoot . '/public/index.php';
        if (!file_exists($indexPath)) {
            $indexContent = <<<'PHP'
<?php
/**
 * StoneScriptPHP Application Entry Point
 */

require_once __DIR__ . '/../vendor/autoload.php';

use StoneScriptPHP\Router;
use StoneScriptPHP\Database;

// Load environment variables
if (file_exists(__DIR__ . '/../.env')) {
    $env = parse_ini_file(__DIR__ . '/../.env');
    foreach ($env as $key => $value) {
        $_ENV[$key] = $value;
    }
}

// Initialize database connection
Database::init();

// Initialize router and handle request
$router = new Router();
$router->handleRequest();
PHP;

            file_put_contents($indexPath, $indexContent);
            echo "  ✓ Created public/index.php\n";
        }
    }

    private function createExampleRoute(): void
    {
        $routePath = $this->projectRoot . '/src/App/Routes/HealthRoute.php';
        if (!file_exists($routePath)) {
            $routeContent = <<<'PHP'
<?php

namespace App\Routes;

use StoneScriptPHP\Attributes\Route;
use StoneScriptPHP\Attributes\GET;

class HealthRoute
{
    #[Route('/health')]
    #[GET]
    public function health(): array
    {
        return [
            'status' => 'ok',
            'timestamp' => time(),
            'message' => 'StoneScriptPHP is running!'
        ];
    }
}
PHP;

            file_put_contents($routePath, $routeContent);
            echo "  ✓ Created src/App/Routes/HealthRoute.php\n";
        }
    }

    private function recursiveCopy(string $src, string $dst, array $exclude = []): void
    {
        $dir = opendir($src);

        if (!is_dir($dst)) {
            if (!mkdir($dst, 0755, true) && !is_dir($dst)) {
                throw new \RuntimeException("Failed to create directory: $dst");
            }
        }

        while (($file = readdir($dir)) !== false) {
            if ($file == '.' || $file == '..' || in_array($file, $exclude)) {
                continue;
            }

            $srcPath = $src . '/' . $file;
            $dstPath = $dst . '/' . $file;

            if (is_dir($srcPath)) {
                $this->recursiveCopy($srcPath, $dstPath, $exclude);
            } else {
                copy($srcPath, $dstPath);
            }
        }

        closedir($dir);
    }

    private function printBanner(): void
    {
        echo "\n";
        echo "╔═══════════════════════════════════════╗\n";
        echo "║   StoneScriptPHP Init                 ║\n";
        echo "╚═══════════════════════════════════════╝\n";
        echo "\n";
    }

    private function generateEnv(): void
    {
        $envPath = $this->projectRoot . '/.env';

        if (file_exists($envPath)) {
            echo "⚠️  .env file already exists\n";
            $answer = $this->ask("Overwrite .env? (y/N)", "n");
            if (strtolower($answer) !== 'y') {
                echo "Skipped .env generation\n\n";
                return;
            }
        }

        echo "📝 Generating .env file...\n\n";

        $config = [];

        // Application
        $config['APP_NAME'] = $this->ask('Project name', basename($this->projectRoot));
        $config['APP_ENV'] = $this->ask('Environment', 'development');
        $config['APP_PORT'] = $this->ask('Port', '9100');

        // Database
        echo "\n📊 Database Configuration:\n";
        $config['DB_HOST'] = $this->ask('Database host', 'localhost');
        $config['DB_PORT'] = $this->ask('Database port', '5432');
        $config['DB_NAME'] = $this->ask('Database name', strtolower(str_replace(' ', '_', $config['APP_NAME'])));
        $config['DB_USER'] = $this->ask('Database user', 'postgres');
        $config['DB_PASS'] = $this->ask('Database password', '', true);

        // JWT
        echo "\n🔐 JWT Configuration:\n";
        $config['JWT_EXPIRY'] = $this->ask('JWT token expiry (seconds)', '3600');

        // CORS
        echo "\n🌐 CORS Configuration:\n";
        $config['CORS_ORIGINS'] = $this->ask('Allowed origins (comma-separated)', 'http://localhost:3000,http://localhost:4200');

        // Write .env file
        $envContent = $this->buildEnvContent($config);
        file_put_contents($envPath, $envContent);

        echo "\n✅ .env file created!\n\n";
    }

    private function generateKeys(): void
    {
        $keysDir = $this->projectRoot . '/keys';
        $privateKeyPath = $keysDir . '/jwt-private.pem';
        $publicKeyPath = $keysDir . '/jwt-public.pem';

        if (file_exists($privateKeyPath)) {
            echo "⚠️  JWT keypair already exists\n";
            $answer = $this->ask("Regenerate keypair? (y/N)", "n");
            if (strtolower($answer) !== 'y') {
                echo "Skipped key generation\n\n";
                return;
            }
        }

        echo "🔐 Generating JWT keypair...\n";

        if (!is_dir($keysDir)) {
            mkdir($keysDir, 0755, true);
        }

        $config = [
            "digest_alg" => "sha256",
            "private_key_bits" => 2048,
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
        ];

        $res = openssl_pkey_new($config);
        openssl_pkey_export($res, $privKey);
        $pubKey = openssl_pkey_get_details($res)['key'];

        file_put_contents($privateKeyPath, $privKey);
        file_put_contents($publicKeyPath, $pubKey);

        chmod($privateKeyPath, 0600);

        echo "✅ JWT keypair generated!\n\n";
    }

    private function createStoneWrapper(): void
    {
        // Only create wrapper in vendor mode
        if (!$this->isVendorMode) {
            return;
        }

        $stonePath = $this->projectRoot . '/stone';

        if (file_exists($stonePath)) {
            return; // Already exists
        }

        echo "📝 Creating stone wrapper script...\n";

        $wrapperContent = <<<'BASH'
#!/usr/bin/env bash
# StoneScriptPHP CLI Wrapper
# This allows you to run `./stone` instead of `vendor/bin/stone`

exec vendor/bin/stone "$@"
BASH;

        file_put_contents($stonePath, $wrapperContent);
        chmod($stonePath, 0755);

        echo "✅ Created ./stone wrapper (you can now run: ./stone serve)\n\n";
    }

    private function showNextSteps(): void
    {
        echo "🎉 Initialization complete!\n\n";
        echo "Next steps:\n";

        $dbName = $_ENV['DB_NAME'] ?? 'mydb';
        echo "  1. Create database: psql -c 'CREATE DATABASE $dbName'\n";

        if ($this->isVendorMode) {
            echo "  2. Start server: vendor/bin/stone serve (or ./stone serve)\n";
            echo "  3. Generate route: vendor/bin/stone generate route login\n";
            echo "  4. Run migrations: vendor/bin/stone migrate verify\n";
        } else {
            echo "  2. Start server: php stone serve\n";
            echo "  3. Generate route: php stone generate route login\n";
            echo "  4. Run migrations: php stone migrate verify\n";
        }

        echo "\n📖 Documentation: https://github.com/progalaxyelabs/StoneScriptPHP\n\n";
    }

    private function ask(string $question, string $default = '', bool $password = false): string
    {
        $prompt = $default ? "$question [$default]: " : "$question: ";
        echo $prompt;

        if ($password) {
            system('stty -echo 2>/dev/null');
        }

        $answer = trim(fgets(STDIN));

        if ($password) {
            system('stty echo 2>/dev/null');
            echo "\n";
        }

        return $answer ?: $default;
    }

    private function buildEnvContent(array $config): string
    {
        return <<<ENV
# Application
APP_NAME="{$config['APP_NAME']}"
APP_ENV={$config['APP_ENV']}
APP_PORT={$config['APP_PORT']}

# Database
DB_HOST={$config['DB_HOST']}
DB_PORT={$config['DB_PORT']}
DB_NAME={$config['DB_NAME']}
DB_USER={$config['DB_USER']}
DB_PASS={$config['DB_PASS']}

# JWT
JWT_PRIVATE_KEY_PATH=./keys/jwt-private.pem
JWT_PUBLIC_KEY_PATH=./keys/jwt-public.pem
JWT_EXPIRY={$config['JWT_EXPIRY']}

# CORS
CORS_ORIGINS={$config['CORS_ORIGINS']}
ENV;
    }
}

// Run init
$init = new Init($projectRoot, $isVendorMode);
$init->run(array_slice($argv, 1));
