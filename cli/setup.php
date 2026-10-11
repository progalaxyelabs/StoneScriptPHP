<?php
/**
 * Interactive Project Setup
 * Runs after: composer create-project progalaxyelabs/stonescriptphp-server my-api
 *
 * The .env this writes is derived from the framework's own Env schema (the public typed
 * properties of App\Env, else StoneScriptPHP\Env) via cli/generate-env.php, so it contains
 * exactly the keys the running framework reads - there is no second hand-maintained list.
 */

require_once __DIR__ . '/generate-common.php';

// `php stone` defines ROOT_PATH (the project root, also in vendor mode) and has already
// loaded the autoloader. Direct invocation falls back to the legacy layout detection.
if (defined('ROOT_PATH')) {
    $projectRoot = ROOT_PATH;
} else {
    $projectRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR;
    if (str_ends_with(str_replace('\\', '/', __DIR__), '/vendor/progalaxyelabs/stonescriptphp/cli')) {
        $projectRoot = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR;
    }
}
$vendorAutoload = $projectRoot . 'vendor/autoload.php';
if (!class_exists('StoneScriptPHP\\Env') && file_exists($vendorAutoload)) {
    require_once $vendorAutoload;
}
// All relative paths below (.env, keys/) are relative to the project root.
chdir($projectRoot);

// Reuse the schema helpers (buildSchemaFromReflection / generateEnvFile) without running the generator CLI.
if (!defined('STONESCRIPTPHP_ENV_GENERATOR_LIB_ONLY')) {
    define('STONESCRIPTPHP_ENV_GENERATOR_LIB_ONLY', true);
}
require_once __DIR__ . '/generate-env.php';

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

    /** @return array<string, array{type:string, required:bool, default:?string, description:string}> */
    private function envSchema(): array
    {
        $envClass = class_exists('App\\Env') ? 'App\\Env' : 'StoneScriptPHP\\Env';
        return buildSchemaFromReflection(new \ReflectionClass($envClass));
    }

    /** Default for a schema key, as the string that would be written to .env. */
    private function schemaDefault(string $key, string $fallback = ''): string
    {
        $default = $this->envSchema()[$key]['default'] ?? null;
        return $default === null || $default === '' ? $fallback : (string) $default;
    }

    private function generateEnv(): void
    {
        // In quiet mode, load existing .env or use defaults
        if ($this->quiet) {
            $this->loadExistingEnvOrDefaults();
            return;
        }

        if (file_exists('.env')) {
            echo "ℹ️  .env file already exists, skipping creation\n";
            echo "  To regenerate, delete .env and run setup again\n\n";
            $this->env = (object) $this->readEnvFile('.env');
            return;
        }

        echo "📝 Generating .env file...\n\n";

        // Application
        $this->env->APP_NAME = $this->ask('Project name', 'My API');
        $this->env->APP_ENV = $this->ask('Environment', 'development');
        $this->env->APP_PORT = $this->ask('Port', $this->schemaDefault('APP_PORT', '9100'));

        // Database (gateway-only: the API never connects to PostgreSQL directly)
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($this->env->APP_NAME)), '_');
        echo "\n📊 StoneScriptDB Gateway:\n";
        $this->env->DB_GATEWAY_URL = $this->ask('Gateway URL', 'http://localhost:9000');
        $this->env->DB_GATEWAY_PLATFORM = $this->ask('Platform code registered in the gateway', $slug ?: 'my_api');
        $this->env->DB_GATEWAY_SCHEMA_NAME = $this->ask('Main database schema name', 'main');
        $adminToken = $this->ask('Gateway admin token (optional, leave empty to skip)', '', true);
        if ($adminToken !== '') {
            $this->env->DB_GATEWAY_ADMIN_TOKEN = $adminToken;
        }

        // JWT
        echo "\n🔐 JWT Configuration:\n";
        $this->env->JWT_ISSUER = $this->ask(
            'JWT issuer (public base URL of this API)',
            'http://localhost:' . $this->env->APP_PORT
        );
        $this->env->JWT_ACCESS_TOKEN_EXPIRY = $this->ask('Access token expiry (seconds)', $this->schemaDefault('JWT_ACCESS_TOKEN_EXPIRY', '900'));
        $this->env->JWT_REFRESH_TOKEN_EXPIRY = $this->ask('Refresh token expiry (seconds)', $this->schemaDefault('JWT_REFRESH_TOKEN_EXPIRY', '15552000'));

        // JWT Keys
        $this->env->JWT_PRIVATE_KEY_PATH = $this->ask('JWT private key path', $this->schemaDefault('JWT_PRIVATE_KEY_PATH', './keys/jwt-private.pem'));
        $this->env->JWT_PUBLIC_KEY_PATH = $this->ask('JWT public key path', $this->schemaDefault('JWT_PUBLIC_KEY_PATH', './keys/jwt-public.pem'));

        $usePassphrase = $this->ask('Use passphrase-protected private key? (yes/no)', 'no');
        if (strtolower($usePassphrase) === 'yes' || strtolower($usePassphrase) === 'y') {
            $this->env->JWT_PRIVATE_KEY_PASSPHRASE = $this->ask('Enter passphrase for private key', '', true);
        }

        // CORS
        echo "\n🌐 CORS Configuration:\n";
        $this->env->ALLOWED_ORIGINS = $this->ask('Allowed origins (comma-separated)', 'http://localhost:3000,http://localhost:4200');

        // Redis (optional)
        echo "\n💾 Caching Configuration:\n";
        $enableRedis = $this->ask('Enable Redis caching? (yes/no)', 'no');
        if (strtolower($enableRedis) === 'yes' || strtolower($enableRedis) === 'y') {
            $this->env->CACHE_ENABLED = 'true';
            $this->env->REDIS_HOST = $this->ask('Redis host', 'localhost');
            $this->env->REDIS_PORT = $this->ask('Redis port', $this->schemaDefault('REDIS_PORT', '6379'));
        } else {
            $this->env->CACHE_ENABLED = 'false';
        }

        $this->writeEnvFile();

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
            $this->env = (object) $this->readEnvFile($envFile);
            return;
        }

        // Priority: 1) real environment variables (Docker-friendly), 2) schema defaults
        // (applied by the generator for anything not set here).
        foreach (array_keys($this->envSchema()) as $key) {
            $systemEnvValue = getenv($key);
            if ($systemEnvValue !== false && $systemEnvValue !== '') {
                $this->env->$key = $systemEnvValue;
            }
        }

        $this->writeEnvFile();
    }

    /**
     * Parse an existing .env with the same parser the framework boots with (phpdotenv),
     * never parse_ini_file(): dotenv syntax (unquoted spaces, ${VAR}, export) is not INI.
     *
     * @return array<string, string>
     */
    private function readEnvFile(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            echo "❌ Error: cannot read $path\n";
            exit(1);
        }
        try {
            return array_map(
                static fn($v) => (string) $v,
                \Dotenv\Dotenv::parse($contents)
            );
        } catch (\Dotenv\Exception\ExceptionInterface $e) {
            // Never print phpdotenv's own text: it quotes the offending line, which may be a secret.
            echo "❌ Error: $path is not a valid dotenv file.\n";
            echo "  Use '# comment' lines and KEY=value pairs; double-quote values that contain spaces.\n";
            exit(1);
        }
    }

    /** Write .env from the collected answers; every key must exist in the Env schema. */
    private function writeEnvFile(): void
    {
        $answers = (array) $this->env;
        $schema = $this->envSchema();
        $unknown = array_diff(array_keys($answers), array_keys($schema));
        if ($unknown !== []) {
            throw new \LogicException(
                'Setup produced settings the framework does not read: ' . implode(', ', $unknown)
            );
        }
        generateEnvFile('.env', false, false, $answers);
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
        echo "  1. Make sure the StoneScriptDB Gateway is running and the platform is registered ("
            . "DB_GATEWAY_URL / DB_GATEWAY_PLATFORM in .env)\n";
        echo "  2. Start server: php stone serve\n";
        echo "  3. Generate your first route: php stone generate route POST /hello\n";
        echo "  4. Check migrations: php stone migrate status\n\n";
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
}

// Parse command line arguments
$quiet = in_array('--quiet', $argv) || in_array('-q', $argv);

// Run setup
$setup = new Setup();
$setup->run($quiet);
