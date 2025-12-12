<?php
/**
 * Generate JWT Keys CLI Tool
 * Usage: php stone generate-jwt
 */

require_once __DIR__ . '/generate-common.php';

class JwtKeyGenerator
{
    private string $envPath;

    public function __construct()
    {
        // Determine .env path (works in both vendor and project modes)
        $this->envPath = defined('ROOT_PATH') ? ROOT_PATH . '.env' : getcwd() . '/.env';
    }

    public function run(): void
    {
        $this->printBanner();

        // Check if JWT config already exists in .env
        if ($this->hasJwtConfig()) {
            echo "⚠️  JWT configuration already exists in .env\n";
            $overwrite = $this->ask('Do you want to regenerate? (yes/no)', 'no');
            if (strtolower($overwrite) !== 'yes' && strtolower($overwrite) !== 'y') {
                echo "✅ Keeping existing JWT configuration\n";
                exit(0);
            }
        }

        // Generate RSA keypair (public-private key pair)
        $this->generateRsaKeypair();

        echo "\n✅ JWT authentication setup complete!\n\n";
        echo "Next steps:\n";
        echo "  1. Review your .env file\n";
        echo "  2. Use Framework\\Auth\\RsaJwtHandler for JWT authentication\n";
        echo "  3. See docs/authentication.md for usage examples\n\n";
    }

    private function printBanner(): void
    {
        echo "\n";
        echo "╔═══════════════════════════════════════╗\n";
        echo "║   JWT Key Generation                  ║\n";
        echo "╚═══════════════════════════════════════╝\n";
        echo "\n";
    }

    private function hasJwtConfig(): bool
    {
        if (!file_exists($this->envPath)) {
            return false;
        }

        $envContent = file_get_contents($this->envPath);
        return strpos($envContent, 'JWT_PRIVATE_KEY_PATH') !== false;
    }

    private function generateRsaKeypair(): void
    {
        echo "\n🔑 Generating RSA keypair...\n";

        // Create keys directory if it doesn't exist
        $keysDir = defined('ROOT_PATH') ? ROOT_PATH . 'keys' : getcwd() . '/keys';
        if (!is_dir($keysDir)) {
            mkdir($keysDir, 0755, true);
            echo "📁 Created keys/ directory\n";
        }

        // Generate RSA keypair
        $config = [
            "digest_alg" => "sha256",
            "private_key_bits" => 2048,
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
        ];

        $res = openssl_pkey_new($config);
        if (!$res) {
            echo "❌ Failed to generate RSA keypair\n";
            exit(1);
        }

        openssl_pkey_export($res, $privKey);
        $pubKey = openssl_pkey_get_details($res)['key'];

        // Write keys to files
        $privateKeyPath = $keysDir . '/jwt-private.pem';
        $publicKeyPath = $keysDir . '/jwt-public.pem';

        file_put_contents($privateKeyPath, $privKey);
        file_put_contents($publicKeyPath, $pubKey);

        // Set proper permissions (600 for private key)
        chmod($privateKeyPath, 0600);
        chmod($publicKeyPath, 0644);

        echo "✅ RSA keypair generated:\n";
        echo "   Private: $privateKeyPath (chmod 600)\n";
        echo "   Public:  $publicKeyPath (chmod 644)\n";

        // Prompt for expiry
        $expiry = $this->ask('JWT token expiry in seconds', '3600');

        // Append to .env
        $jwtConfig = "\n# JWT Authentication (RSA)\n";
        $jwtConfig .= "JWT_PRIVATE_KEY_PATH=./keys/jwt-private.pem\n";
        $jwtConfig .= "JWT_PUBLIC_KEY_PATH=./keys/jwt-public.pem\n";
        $jwtConfig .= "JWT_EXPIRY=$expiry\n";

        $this->appendToEnv($jwtConfig);

        echo "✅ RSA key paths added to .env\n";
    }

    private function appendToEnv(string $content): void
    {
        // Create .env if it doesn't exist
        if (!file_exists($this->envPath)) {
            // Try to copy from .env.example
            $envExamplePath = dirname($this->envPath) . '/.env.example';
            if (file_exists($envExamplePath)) {
                copy($envExamplePath, $this->envPath);
                echo "📝 Created .env from .env.example\n";
            } else {
                file_put_contents($this->envPath, "");
                echo "📝 Created new .env file\n";
            }
        }

        // Remove existing JWT config if present
        $envContent = file_get_contents($this->envPath);
        $envContent = preg_replace(
            '/\n# JWT Authentication.*?\n(JWT_.*?\n)+/s',
            '',
            $envContent
        );

        // Append new JWT config
        file_put_contents($this->envPath, $envContent . $content);
    }

    private function ask(string $question, string $default = ''): string
    {
        $prompt = $default ? "$question [$default]: " : "$question: ";
        echo $prompt;

        $answer = trim(fgets(STDIN));
        return $answer ?: $default;
    }
}

// Run JWT key generator
$generator = new JwtKeyGenerator();
$generator->run();
