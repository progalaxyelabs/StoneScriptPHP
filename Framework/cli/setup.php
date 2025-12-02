<?php
/**
 * Interactive Project Setup
 * Runs after: composer create-project progalaxyelabs/stone-script-php my-api
 */

require_once __DIR__ . '/../../vendor/autoload.php';

class Setup {
    public function run(): void
    {
        $this->printBanner();

        // Template selection for fresh projects
        if ($this->isEmptyProject()) {
            $this->showTemplateSelection();
        }

        $this->generateEnv();
        $this->generateKeys();
        $this->showNextSteps();
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
        @mkdir($dst, 0755, true);

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
        echo "📝 Generating .env file...\n\n";

        $config = [];

        // Application
        $config['APP_NAME'] = $this->ask('Project name', 'My API');
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
        $config['ALLOWED_ORIGINS'] = $this->ask('Allowed origins (comma-separated)', 'http://localhost:3000,http://localhost:4200');

        // Write .env file
        $envContent = $this->buildEnvContent($config);
        file_put_contents('.env', $envContent);

        echo "\n✅ .env file created!\n\n";
    }

    private function generateKeys(): void
    {
        echo "🔐 Generating JWT keypair...\n";

        if (!is_dir('keys')) {
            mkdir('keys', 0755, true);
        }

        $config = [
            "digest_alg" => "sha256",
            "private_key_bits" => 2048,
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
        ];

        $res = openssl_pkey_new($config);
        openssl_pkey_export($res, $privKey);
        $pubKey = openssl_pkey_get_details($res)['key'];

        file_put_contents('keys/jwt-private.pem', $privKey);
        file_put_contents('keys/jwt-public.pem', $pubKey);

        chmod('keys/jwt-private.pem', 0600);

        echo "✅ JWT keypair generated!\n\n";
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
ALLOWED_ORIGINS={$config['ALLOWED_ORIGINS']}
ENV;
    }
}

// Run setup
$setup = new Setup();
$setup->run();
