<?php
/**
 * Generate Redis Caching Support
 * Adds Redis configuration to .env and docker-compose.yaml
 * Usage: php stone generate cache:redis
 */

require_once __DIR__ . '/generate-common.php';

class RedisCacheGenerator {
    private string $projectRoot;

    public function __construct()
    {
        $this->projectRoot = getcwd();
    }

    public function run(): void
    {
        echo "\n";
        echo "╔═══════════════════════════════════════╗\n";
        echo "║   Redis Caching Setup                 ║\n";
        echo "╚═══════════════════════════════════════╝\n";
        echo "\n";

        // 1. Update .env file
        $this->updateEnvFile();

        // 2. Update docker-compose.yaml
        $this->updateDockerCompose();

        // 3. Show next steps
        $this->showNextSteps();
    }

    private function updateEnvFile(): void
    {
        echo "📝 Updating .env file...\n";

        $envFile = $this->projectRoot . '/.env';

        if (!file_exists($envFile)) {
            echo "  ⚠️  .env file not found. Run 'php stone setup' first.\n";
            exit(1);
        }

        $envContent = file_get_contents($envFile);

        // Check if Redis is already configured
        if (strpos($envContent, 'REDIS_ENABLED=true') !== false) {
            echo "  ℹ️  Redis already enabled in .env\n";
            return;
        }

        // Ask for Redis configuration
        $redisHost = $this->ask('Redis host', 'localhost');
        $redisPort = $this->ask('Redis port', '6379');

        // Update or add Redis configuration
        if (strpos($envContent, 'REDIS_ENABLED') !== false) {
            // Replace existing Redis section
            $envContent = preg_replace(
                '/REDIS_ENABLED=false\n# REDIS_HOST=.*\n# REDIS_PORT=.*/',
                "REDIS_ENABLED=true\nREDIS_HOST=$redisHost\nREDIS_PORT=$redisPort",
                $envContent
            );
        } else {
            // Append Redis configuration
            $redisConfig = "\n# Redis Caching\nREDIS_ENABLED=true\nREDIS_HOST=$redisHost\nREDIS_PORT=$redisPort\n";
            $envContent .= $redisConfig;
        }

        file_put_contents($envFile, $envContent);
        echo "  ✓ Updated .env with Redis configuration\n";
    }

    private function updateDockerCompose(): void
    {
        echo "\n🐳 Updating docker-compose.yaml...\n";

        $dockerComposeFile = $this->projectRoot . '/docker-compose.yaml';

        if (!file_exists($dockerComposeFile)) {
            echo "  ℹ️  docker-compose.yaml not found, skipping Docker setup\n";
            return;
        }

        $dockerContent = file_get_contents($dockerComposeFile);

        // Check if Redis service already exists
        if (strpos($dockerContent, 'redis:') !== false && strpos($dockerContent, 'image: redis:') !== false) {
            echo "  ℹ️  Redis service already exists in docker-compose.yaml\n";

            // Just uncomment if it's commented
            if (strpos($dockerContent, '# redis:') !== false) {
                $dockerContent = str_replace('# redis:', 'redis:', $dockerContent);
                $dockerContent = preg_replace('/#   /', '  ', $dockerContent);
                file_put_contents($dockerComposeFile, $dockerContent);
                echo "  ✓ Uncommented Redis service in docker-compose.yaml\n";
            }

            return;
        }

        // Add Redis environment variables to app service
        $redisEnvVars = <<<YAML

      # Redis
      REDIS_ENABLED: \${REDIS_ENABLED:-true}
      REDIS_HOST: redis
      REDIS_PORT: 6379
YAML;

        // Insert before ports section
        $dockerContent = str_replace(
            "    ports:\n      - \"\${APP_PORT:-8000}:8000\"",
            "$redisEnvVars\n    ports:\n      - \"\${APP_PORT:-8000}:8000\"",
            $dockerContent
        );

        // Add Redis service and volume
        $redisService = <<<YAML


  # Redis Cache
  redis:
    image: redis:7-alpine
    container_name: stonescriptphp-redis
    restart: unless-stopped
    ports:
      - "\${REDIS_PORT:-6379}:6379"
    volumes:
      - redis_data:/data
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 10s
      timeout: 3s
      retries: 3
YAML;

        // Insert before volumes section
        $dockerContent = str_replace(
            "\nvolumes:",
            "$redisService\n\nvolumes:",
            $dockerContent
        );

        // Add redis_data volume
        $dockerContent = str_replace(
            "volumes:\n  postgres_data:\n    driver: local",
            "volumes:\n  postgres_data:\n    driver: local\n  redis_data:\n    driver: local",
            $dockerContent
        );

        file_put_contents($dockerComposeFile, $dockerContent);
        echo "  ✓ Added Redis service to docker-compose.yaml\n";
    }

    private function showNextSteps(): void
    {
        echo "\n✅ Redis caching configured!\n\n";
        echo "Next steps:\n";
        echo "  1. Install ext-redis PHP extension (if not already installed):\n";
        echo "     Ubuntu/Debian: sudo apt-get install php-redis\n";
        echo "     macOS: brew install php-redis\n";
        echo "     Or via PECL: pecl install redis\n\n";
        echo "  2. Restart Docker containers (if using Docker):\n";
        echo "     docker compose down && docker compose up -d\n\n";
        echo "  3. Test Redis connection in your application:\n";
        echo "     Use StoneScriptPHP\\Cache class for caching\n\n";
    }

    private function ask(string $question, string $default = ''): string
    {
        $prompt = $default ? "$question [$default]: " : "$question: ";
        echo $prompt;

        $answer = trim(fgets(STDIN));

        return $answer ?: $default;
    }
}

// Run generator
$generator = new RedisCacheGenerator();
$generator->run();
