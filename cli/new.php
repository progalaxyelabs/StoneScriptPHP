<?php
/**
 * StoneScriptPHP Project Generator
 * Creates new projects with interactive setup wizard
 * Usage: php stone new <project-name> [options]
 */

// Use $_SERVER['argv'] and $_SERVER['argc'] which are set by the stone dispatcher
$argc = $_SERVER['argc'];
$argv = $_SERVER['argv'];

// Display help if requested
// When called via 'stone new', argv[0] is the script path, argv[1] is the first arg
if ($argc === 1 || ($argc >= 2 && in_array($argv[1] ?? '', ['--help', '-h', 'help']))) {
    echo "\n";
    echo "┌─────────────────────────────────────────────────────────┐\n";
    echo "│         StoneScriptPHP Project Generator               │\n";
    echo "└─────────────────────────────────────────────────────────┘\n";
    echo "\n";
    echo "Usage:\n";
    echo "  stone new <project-name> [options]\n";
    echo "\n";
    echo "Arguments:\n";
    echo "  project-name         Name of the new project\n";
    echo "\n";
    echo "Options:\n";
    echo "  --template=TYPE      Project template type\n";
    echo "                       - api (default): Backend API only\n";
    echo "                       - web: Frontend only\n";
    echo "                       - fullstack: Complete full-stack app\n";
    echo "  --skip-setup         Skip interactive setup wizard\n";
    echo "  --git                Initialize git repository\n";
    echo "  --skip-install       Skip composer install\n";
    echo "  --help, -h           Show this help message\n";
    echo "\n";
    echo "Examples:\n";
    echo "  stone new my-api\n";
    echo "  stone new my-api --template=api --git\n";
    echo "  stone new my-app --template=fullstack\n";
    echo "  stone new my-web --template=web --skip-setup\n";
    echo "\n";
    exit(0);
}

class ProjectGenerator
{
    private string $projectName;
    private string $template;
    private bool $skipSetup;
    private bool $initGit;
    private bool $skipInstall;
    private string $projectDir;
    private string $frameworkPath;

    public function __construct(array $argv)
    {
        $this->frameworkPath = dirname(__DIR__);
        $this->parseArguments($argv);
    }

    private function parseArguments(array $argv): void
    {
        // Get project name (argv[0] when called via stone, argv[1] when called directly)
        $this->projectName = $argv[0] ?? '';

        if (empty($this->projectName)) {
            $this->error("Error: Project name is required");
            echo "Run 'stone new --help' for usage information\n";
            exit(1);
        }

        // Validate project name
        if (!preg_match('/^[a-z0-9-_]+$/i', $this->projectName)) {
            $this->error("Error: Project name can only contain letters, numbers, hyphens, and underscores");
            exit(1);
        }

        // Set defaults
        $this->template = 'api';
        $this->skipSetup = false;
        $this->initGit = false;
        $this->skipInstall = false;

        // Parse options
        for ($i = 1; $i < count($argv); $i++) {
            if (str_starts_with($argv[$i], '--template=')) {
                $this->template = substr($argv[$i], 11);
            } elseif ($argv[$i] === '--skip-setup') {
                $this->skipSetup = true;
            } elseif ($argv[$i] === '--git') {
                $this->initGit = true;
            } elseif ($argv[$i] === '--skip-install') {
                $this->skipInstall = true;
            }
        }

        // Validate template
        if (!in_array($this->template, ['api', 'web', 'fullstack'])) {
            $this->error("Error: Invalid template '{$this->template}'. Valid options: api, web, fullstack");
            exit(1);
        }

        // Set project directory
        $this->projectDir = getcwd() . DIRECTORY_SEPARATOR . $this->projectName;
    }

    public function run(): void
    {
        $this->printBanner();
        $this->checkPrerequisites();
        $this->createProjectDirectory();
        $this->copyFrameworkFiles();
        $this->createProjectStructure();
        $this->generateConfigFiles();
        $this->updateComposerJson();

        if (!$this->skipInstall) {
            $this->installDependencies();
        }

        if ($this->initGit) {
            $this->initializeGit();
        }

        if (!$this->skipSetup) {
            $this->runSetup();
        }

        $this->printSuccess();
    }

    private function printBanner(): void
    {
        echo "\n";
        echo "╔═══════════════════════════════════════════════════════════╗\n";
        echo "║                                                           ║\n";
        echo "║           🚀 StoneScriptPHP Project Generator             ║\n";
        echo "║                                                           ║\n";
        echo "╚═══════════════════════════════════════════════════════════╝\n";
        echo "\n";
        echo $this->info("Creating new project: {$this->projectName}") . "\n";
        echo $this->info("Template: {$this->template}") . "\n";
        echo "\n";
    }

    private function checkPrerequisites(): void
    {
        echo "→ Checking prerequisites...\n";

        // Check if project directory already exists
        if (is_dir($this->projectDir)) {
            $this->error("Error: Directory already exists: {$this->projectDir}");
            exit(1);
        }

        // Check if we can write to current directory
        if (!is_writable(getcwd())) {
            $this->error("Error: Current directory is not writable");
            exit(1);
        }

        echo $this->success("  ✓ All checks passed") . "\n\n";
    }

    private function createProjectDirectory(): void
    {
        echo "→ Creating project directory...\n";

        if (!mkdir($this->projectDir, 0755, true)) {
            $this->error("Error: Failed to create project directory");
            exit(1);
        }

        echo $this->success("  ✓ Created {$this->projectDir}") . "\n\n";
    }

    private function copyFrameworkFiles(): void
    {
        echo "→ Setting up framework via Composer...\n";

        // NOTE: The framework is now installed via Composer, not copied directly
        // The composer.json created by this generator will require progalaxyelabs/stonescriptphp
        // which provides the StoneScriptPHP namespace in vendor/

        // Copy stone CLI script if it exists
        $stoneScript = $this->frameworkPath . DIRECTORY_SEPARATOR . 'stone';
        if (file_exists($stoneScript)) {
            copy(
                $stoneScript,
                $this->projectDir . DIRECTORY_SEPARATOR . 'stone'
            );
            chmod($this->projectDir . DIRECTORY_SEPARATOR . 'stone', 0755);
            echo $this->success("  ✓ CLI tool (stone)") . "\n";
        }

        // Copy public directory if it exists
        $publicDir = $this->frameworkPath . DIRECTORY_SEPARATOR . 'public';
        if (is_dir($publicDir)) {
            $this->recursiveCopy(
                $publicDir,
                $this->projectDir . DIRECTORY_SEPARATOR . 'public'
            );
            echo $this->success("  ✓ Public directory") . "\n";
        }

        echo $this->success("  ✓ Framework will be installed via Composer") . "\n";
        echo "\n";
    }

    private function createProjectStructure(): void
    {
        echo "→ Creating project structure...\n";

        $directories = [
            'src',
            'src/App',
            'src/App/Routes',
            'src/App/Contracts',
            'src/App/DTO',
            'src/App/Models',
            'src/App/Lib',
            'src/config',
            'src/postgresql',
            'src/postgresql/tables',
            'src/postgresql/functions',
            'src/postgresql/seeds',
            'tests',
            'tests/Unit',
            'tests/Feature',
        ];

        foreach ($directories as $dir) {
            $fullPath = $this->projectDir . DIRECTORY_SEPARATOR . $dir;
            if (!is_dir($fullPath)) {
                mkdir($fullPath, 0755, true);
            }
        }

        echo $this->success("  ✓ Directory structure created") . "\n";

        // Create initial files
        $this->createInitialFiles();
        echo $this->success("  ✓ Initial files created") . "\n\n";
    }

    private function createInitialFiles(): void
    {
        // Create Env.php
        $envPhp = <<<'PHP'
<?php

namespace App;

class Env
{
    public static string $DATABASE_HOST = 'localhost';
    public static string $DATABASE_PORT = '5432';
    public static string $DATABASE_NAME = '';
    public static string $DATABASE_USER = '';
    public static string $DATABASE_PASSWORD = '';
    public static string $JWT_SECRET = '';
    public static string $JWT_PUBLIC_KEY = '';
    public static string $JWT_PRIVATE_KEY = '';
    public static string $ENVIRONMENT = 'development';
}

PHP;
        file_put_contents(
            $this->projectDir . '/src/App/Env.php',
            $envPhp
        );

        // Create routes.php
        $routesPhp = <<<'PHP'
<?php

use App\Routes\HomeRoute;

return [
    'GET' => [
        '/' => HomeRoute::class,
    ],
    'POST' => [
        // Add your POST routes here
    ],
    'PUT' => [
        // Add your PUT routes here
    ],
    'DELETE' => [
        // Add your DELETE routes here
    ],
];

PHP;
        file_put_contents(
            $this->projectDir . '/src/config/routes.php',
            $routesPhp
        );

        // Create allowed-origins.php
        $allowedOriginsPhp = <<<'PHP'
<?php

return [
    'http://localhost:4200',
    'http://localhost:3000',
];

PHP;
        file_put_contents(
            $this->projectDir . '/src/config/allowed-origins.php',
            $allowedOriginsPhp
        );

        // Create HomeRoute.php
        $homeRoutePhp = <<<'PHP'
<?php

namespace App\Routes;

use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\IRouteHandler;

class HomeRoute implements IRouteHandler
{
    function validation_rules(): array
    {
        return [];
    }

    function process(): ApiResponse
    {
        return res_ok([
            'message' => 'Welcome to StoneScriptPHP!',
            'version' => '1.0.0',
            'docs' => 'https://github.com/progalaxy-labs/StoneScriptPHP'
        ], 'API is running');
    }
}

PHP;
        file_put_contents(
            $this->projectDir . '/src/App/Routes/HomeRoute.php',
            $homeRoutePhp
        );

        // Create README.md
        $readme = <<<MD
# {$this->projectName}

A StoneScriptPHP application.

## Quick Start

### Development Server
```bash
composer serve
```

### Database Migration
```bash
composer migrate
```

### Generate Route
```bash
php stone generate route /api/users
```

## Project Structure

- `vendor/` - Composer dependencies (includes StoneScriptPHP framework)
- `src/App/Routes/` - Route handlers
- `src/App/Contracts/` - Route interfaces
- `src/App/DTO/` - Data transfer objects
- `src/App/Models/` - Database models
- `src/config/` - Configuration files
- `src/postgresql/` - Database schema and migrations
- `public/` - Public web root
- `stone` - CLI tool entry point
- `tests/` - Test files

## Documentation

- [StoneScriptPHP Documentation](https://github.com/progalaxy-labs/StoneScriptPHP)
- [CLI Usage](./CLI-USAGE.md)

## License

MIT

MD;
        file_put_contents(
            $this->projectDir . '/README.md',
            $readme
        );
    }

    private function generateConfigFiles(): void
    {
        echo "→ Generating configuration files...\n";

        // Create .gitignore
        $gitignore = <<<GITIGNORE
/vendor/
/.env
/.idea/
/.vscode/
/node_modules/
*.log
.DS_Store
/public/uploads/
composer.lock

GITIGNORE;
        file_put_contents(
            $this->projectDir . '/.gitignore',
            $gitignore
        );
        echo $this->success("  ✓ .gitignore") . "\n";

        // Create .env.example
        $envExample = <<<ENV
# Database Configuration
DATABASE_HOST=localhost
DATABASE_PORT=5432
DATABASE_NAME=your_database_name
DATABASE_USER=your_database_user
DATABASE_PASSWORD=your_database_password

# JWT Configuration
JWT_SECRET=your-jwt-secret-key-here
JWT_PUBLIC_KEY=path/to/public.key
JWT_PRIVATE_KEY=path/to/private.key

# Environment
ENVIRONMENT=development

ENV;
        file_put_contents(
            $this->projectDir . '/.env.example',
            $envExample
        );
        echo $this->success("  ✓ .env.example") . "\n";

        // Create phpunit.xml
        $phpunitXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="tests/bootstrap.php"
         colors="true"
         verbose="true"
         stopOnFailure="false">
    <testsuites>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
</phpunit>

XML;
        file_put_contents(
            $this->projectDir . '/phpunit.xml',
            $phpunitXml
        );
        echo $this->success("  ✓ phpunit.xml") . "\n";

        // Create tests/bootstrap.php
        $testBootstrap = <<<'PHP'
<?php

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment variables for testing
// Add any test-specific setup here

PHP;
        file_put_contents(
            $this->projectDir . '/tests/bootstrap.php',
            $testBootstrap
        );
        echo $this->success("  ✓ tests/bootstrap.php") . "\n\n";
    }

    private function updateComposerJson(): void
    {
        echo "→ Generating composer.json...\n";

        $composerJson = [
            'name' => 'stonescriptphp/' . $this->projectName,
            'description' => 'A StoneScriptPHP application',
            'type' => 'project',
            'license' => 'MIT',
            'require' => [
                'php' => '^8.1',
                'vlucas/phpdotenv' => '^5.6',
                'firebase/php-jwt' => '^6.10'
            ],
            'require-dev' => [
                'phpunit/phpunit' => '^10.0'
            ],
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/App/'
                ]
            ],
            'scripts' => [
                'serve' => [
                    'Composer\\Config::disableProcessTimeout',
                    'php stone serve'
                ],
                'test' => 'phpunit',
                'migrate' => 'php stone migrate',
                'setup' => 'php stone setup'
            ],
            'config' => [
                'optimize-autoloader' => true,
                'preferred-install' => 'dist',
                'sort-packages' => true
            ],
            'minimum-stability' => 'stable',
            'prefer-stable' => true
        ];

        file_put_contents(
            $this->projectDir . '/composer.json',
            json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );

        echo $this->success("  ✓ composer.json created") . "\n\n";
    }

    private function installDependencies(): void
    {
        echo "→ Installing dependencies...\n";
        echo $this->info("  This may take a few moments...") . "\n\n";

        chdir($this->projectDir);

        // Run composer install
        passthru('composer install --no-interaction 2>&1', $returnCode);

        if ($returnCode !== 0) {
            echo "\n" . $this->warning("  ⚠ Composer install failed. You may need to run 'composer install' manually.") . "\n\n";
        } else {
            echo "\n" . $this->success("  ✓ Dependencies installed") . "\n\n";
        }
    }

    private function initializeGit(): void
    {
        echo "→ Initializing git repository...\n";

        chdir($this->projectDir);

        passthru('git init > /dev/null 2>&1');
        echo $this->success("  ✓ Git repository initialized") . "\n";

        passthru('git add . > /dev/null 2>&1');
        passthru('git commit -m "Initial commit: StoneScriptPHP project scaffold" > /dev/null 2>&1', $returnCode);

        if ($returnCode === 0) {
            echo $this->success("  ✓ Initial commit created") . "\n";
        }

        echo "\n";
    }

    private function runSetup(): void
    {
        echo "→ Running interactive setup...\n\n";

        chdir($this->projectDir);
        passthru('php stone setup');
    }

    private function printSuccess(): void
    {
        echo "\n";
        echo "╔═══════════════════════════════════════════════════════════╗\n";
        echo "║                                                           ║\n";
        echo "║           ✨ Project Created Successfully! ✨             ║\n";
        echo "║                                                           ║\n";
        echo "╚═══════════════════════════════════════════════════════════╝\n";
        echo "\n";
        echo $this->success("Your StoneScriptPHP project is ready!") . "\n\n";
        echo "Next steps:\n\n";
        echo "  cd {$this->projectName}\n";

        if ($this->skipSetup) {
            echo "  php stone setup              # Run interactive setup\n";
        }

        if ($this->skipInstall) {
            echo "  composer install             # Install dependencies\n";
        }

        echo "  composer serve               # Start development server\n";
        echo "  php stone generate route /api/endpoint  # Generate new route\n";
        echo "\n";
        echo "Documentation:\n";
        echo "  README.md                    # Project README\n";
        echo "  CLI-USAGE.md                 # CLI command reference\n";
        echo "\n";
        echo $this->info("Happy coding! 🚀") . "\n\n";
    }

    // Utility methods
    private function recursiveCopy(string $src, string $dst): void
    {
        if (!is_dir($src)) {
            return;
        }

        if (!is_dir($dst)) {
            mkdir($dst, 0755, true);
        }

        $dir = opendir($src);
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $srcPath = $src . DIRECTORY_SEPARATOR . $file;
            $dstPath = $dst . DIRECTORY_SEPARATOR . $file;

            if (is_dir($srcPath)) {
                $this->recursiveCopy($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }
        closedir($dir);
    }

    private function success(string $message): string
    {
        return "\033[0;32m{$message}\033[0m";
    }

    private function error(string $message): string
    {
        return "\033[0;31m{$message}\033[0m";
    }

    private function warning(string $message): string
    {
        return "\033[1;33m{$message}\033[0m";
    }

    private function info(string $message): string
    {
        return "\033[0;34m{$message}\033[0m";
    }
}

// Run the generator
try {
    $generator = new ProjectGenerator($argv);
    $generator->run();
} catch (Exception $e) {
    echo "\033[0;31mError: {$e->getMessage()}\033[0m\n";
    exit(1);
}
