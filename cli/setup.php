<?php
/**
 * Interactive Project Setup
 * Runs after: composer create-project progalaxyelabs/stone-script-php my-api
 */

require_once __DIR__ . '/generate-common.php';

// Load autoloader - fix path detection issue
$projectRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR;
$vendorAutoload = $projectRoot . 'vendor/autoload.php';
if (file_exists($vendorAutoload)) {
    require_once $vendorAutoload;
}

class Setup {
    private bool $quiet = false;
    private object $env;  // stdClass to hold config values

    public function __construct()
    {
        $this->env = (object) [];  // Initialize as empty object
    }

    public function run(bool $quiet = false): void
    {
        $this->quiet = $quiet;

        if (!$quiet) {
            $this->printBanner();
        }

        $this->generateEnv();

        $this->generateKeys(
            $this->env->JWT_PRIVATE_KEY_PASSPHRASE ?? '',
            $this->env->JWT_PRIVATE_KEY_PATH ?? 'keys/jwt-private.pem',
            $this->env->JWT_PUBLIC_KEY_PATH ?? 'keys/jwt-public.pem'
        );

        if (!$quiet) {
            $this->showNextSteps();
        }
    }

    private function isEmptyProject(): bool
    {
        $coreFiles = ['src/App/Routes', 'src/App/Database'];
        foreach ($coreFiles as $file) {
            if (is_dir($file) && count(scandir($file)) > 2) {
                return false; // Already has code
            }
        }
        return true;
    }

    private function showTemplateSelection(): void
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
            '4' => 'saas-boilerplate'
        ];

        if (isset($templates[$choice])) {
            $this->scaffoldFromTemplate($templates[$choice]);
        }
    }

    private function scaffoldFromTemplate(string $template): void
    {
        $vendorPath = __DIR__ . '/../../starters/' . $template;

        if (!is_dir($vendorPath)) {
            echo "❌ Template not found\n";
            return;
        }

        echo "\n📝 Scaffolding from $template template...\n";

        // Copy files (excluding .git, .gitignore stays)
        $this->recursiveCopy($vendorPath, getcwd(), ['.git', '.gitkeep']);

        echo "✅ Template scaffolded successfully!\n";
        echo "📁 Files created from template\n\n";
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
        echo "║   StoneScriptPHP Project Setup        ║\n";
        echo "╚═══════════════════════════════════════╝\n";
        echo "\n";
    }

    private function generateEnv(): void
    {
        // In quiet mode, load existing .env or use defaults
        if ($this->quiet) {
            $this->loadExistingEnvOrDefaults();
            return;
        }

        echo "📝 Generating .env file...\n\n";

        // Application
        $this->env->APP_NAME = $this->ask('Project name', 'My API');
        $this->env->APP_ENV = $this->ask('Environment', 'development');
        $this->env->APP_PORT = $this->ask('Port', '9100');

        // Database
        echo "\n📊 Database Configuration:\n";
        $this->env->DATABASE_HOST = $this->ask('Database host', 'localhost');
        $this->env->DATABASE_PORT = $this->ask('Database port', '5432');
        $this->env->DATABASE_DBNAME = $this->ask('Database name', strtolower(str_replace(' ', '_', $this->env->APP_NAME)));
        $this->env->DATABASE_USER = $this->ask('Database user', 'postgres');
        $this->env->DATABASE_PASSWORD = $this->ask('Database password', '', true);

        // JWT
        echo "\n🔐 JWT Configuration:\n";
        $this->env->JWT_ISSUER = $this->ask('JWT issuer (your domain)', 'example.com');
        $this->env->JWT_ACCESS_TOKEN_EXPIRY = $this->ask('Access token expiry (seconds)', '900');
        $this->env->JWT_REFRESH_TOKEN_EXPIRY = $this->ask('Refresh token expiry (seconds)', '15552000');

        // JWT Keys
        $this->env->JWT_PRIVATE_KEY_PATH = $this->ask('JWT private key path', './keys/jwt-private.pem');
        $this->env->JWT_PUBLIC_KEY_PATH = $this->ask('JWT public key path', './keys/jwt-public.pem');

        $usePassphrase = $this->ask('Use passphrase-protected private key? (yes/no)', 'no');
        if (strtolower($usePassphrase) === 'yes' || strtolower($usePassphrase) === 'y') {
            $this->env->JWT_PRIVATE_KEY_PASSPHRASE = $this->ask('Enter passphrase for private key', '', true);
        } else {
            $this->env->JWT_PRIVATE_KEY_PASSPHRASE = '';
        }

        // CORS
        echo "\n🌐 CORS Configuration:\n";
        $this->env->ALLOWED_ORIGINS = $this->ask('Allowed origins (comma-separated)', 'http://localhost:3000,http://localhost:4200');

        // Redis (optional)
        echo "\n💾 Caching Configuration:\n";
        $enableRedis = $this->ask('Enable Redis caching? (yes/no)', 'no');
        if (strtolower($enableRedis) === 'yes' || strtolower($enableRedis) === 'y') {
            $this->env->REDIS_ENABLED = 'true';
            $this->env->REDIS_HOST = $this->ask('Redis host', 'localhost');
            $this->env->REDIS_PORT = $this->ask('Redis port', '6379');
        } else {
            $this->env->REDIS_ENABLED = 'false';
        }

        // Write .env file
        $envContent = $this->buildEnvContent();
        file_put_contents('.env', $envContent);

        echo "\n✅ .env file created!\n\n";
    }

    private function loadExistingEnvOrDefaults(): void
    {
        $envFile = '.env';

        // If .env already exists, skip creation
        if (file_exists($envFile)) {
            // Always show skip message, even in quiet mode (important info)
            echo "ℹ️  .env file already exists, skipping creation\n";
            echo "  To regenerate, delete .env and run setup again\n\n";

            // Still load existing values into $this->env for key generation
            $existingEnvData = parse_ini_file($envFile) ?: [];
            foreach ($existingEnvData as $key => $value) {
                $this->env->$key = $value;
            }
            return;
        }

        // App\Env must exist - fail hard if it doesn't
        if (!class_exists('App\\Env')) {
            echo "❌ Error: App\\Env class not found!\n";
            echo "Make sure you have src/App/Env.php in your project.\n";
            exit(1);
        }

        // Use reflection to discover all public properties and their defaults
        $reflectionClass = new \ReflectionClass('App\\Env');
        $properties = $reflectionClass->getProperties(\ReflectionProperty::IS_PUBLIC);

        // Populate $this->env with values from:
        // Priority: 1) System environment variables, 2) Property defaults
        foreach ($properties as $property) {
            $propName = $property->getName();

            // Get default value from property
            $defaultValue = $property->getDefaultValue();

            // Check system environment variable first (Docker-friendly)
            $systemEnvValue = getenv($propName);

            if ($systemEnvValue !== false) {
                // System environment variable exists - use it
                $this->env->$propName = $systemEnvValue;
            } else {
                // Use property default
                $this->env->$propName = $defaultValue;
            }
        }

        // Write .env file
        $envContent = $this->buildEnvContent();
        file_put_contents('.env', $envContent);
    }

    private function generateKeys(string $passphrase = '', string $privateKeyPath = 'keys/jwt-private.pem', string $publicKeyPath = 'keys/jwt-public.pem'): void
    {
        // Skip key generation if keys already exist
        if (file_exists($privateKeyPath) && file_exists($publicKeyPath)) {
            // Always show skip message, even in quiet mode (important info)
            echo "ℹ️  JWT keypair already exists, skipping generation\n";
            echo "  Private key: $privateKeyPath\n";
            echo "  Public key: $publicKeyPath\n\n";
            return;
        }

        if (!$this->quiet) {
            echo "🔐 Generating JWT keypair...\n";
        }

        // Create keys directory if it doesn't exist
        $keysDir = dirname($privateKeyPath);
        if (!is_dir($keysDir)) {
            mkdir($keysDir, 0755, true);
        }

        $config = [
            "digest_alg" => "sha256",
            "private_key_bits" => 2048,
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
        ];

        $res = openssl_pkey_new($config);

        // Export with or without passphrase
        if (!empty($passphrase)) {
            openssl_pkey_export($res, $privKey, $passphrase);
            if (!$this->quiet) {
                echo "  ✓ Private key encrypted with passphrase\n";
            }
        } else {
            openssl_pkey_export($res, $privKey);
        }

        $pubKey = openssl_pkey_get_details($res)['key'];

        file_put_contents($privateKeyPath, $privKey);
        file_put_contents($publicKeyPath, $pubKey);

        chmod($privateKeyPath, 0600);

        if (!$this->quiet) {
            echo "  ✓ Private key: $privateKeyPath\n";
            echo "  ✓ Public key: $publicKeyPath\n";
            echo "✅ JWT keypair generated!\n\n";
        }
    }

    private function showNextSteps(): void
    {
        echo "🎉 Setup complete!\n\n";
        echo "Next steps:\n";
        echo "  1. Create database: psql -c 'CREATE DATABASE " . ($_ENV['DB_NAME'] ?? 'mydb') . "'\n";
        echo "  2. Start server: php stone serve\n";
        echo "  3. Generate your first route: php stone generate route login\n";
        echo "  4. Run migrations: php stone migrate verify\n\n";
        echo "Documentation: https://github.com/progalaxyelabs/StoneScriptPHP\n\n";
    }

    private function ask(string $question, string $default = '', bool $password = false): string
    {
        $prompt = $default ? "$question [$default]: " : "$question: ";
        echo $prompt;

        if ($password) {
            system('stty -echo');
        }

        $answer = trim(fgets(STDIN));

        if ($password) {
            system('stty echo');
            echo "\n";
        }

        return $answer ?: $default;
    }

    private function buildEnvContent(): string
    {
        $lines = [];

        // Simply iterate through all properties in $this->env
        foreach ($this->env as $key => $value) {
            // Skip null values
            if ($value === null) {
                continue;
            }

            // Quote string values that contain spaces
            if (is_string($value) && strpos($value, ' ') !== false) {
                $value = "\"$value\"";
            }

            $lines[] = "$key=$value";
        }

        return implode("\n", $lines);
    }
}

// Parse command line arguments
$quiet = in_array('--quiet', $argv) || in_array('-q', $argv);

// Run setup
$setup = new Setup();
$setup->run($quiet);
