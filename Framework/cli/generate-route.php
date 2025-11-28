<?php

/**
 * Route Generator
 *
 * Generates a new route with handler class, interface, and DTOs.
 *
 * Usage:
 *   php generate route <method> <path>
 *
 * Example:
 *   php generate route post /login
 *   php generate route get /items/{itemId}/update-stock
 */

// Determine the root path (go up two levels from Framework/cli)
define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('SRC_PATH', ROOT_PATH . 'src' . DIRECTORY_SEPARATOR);

// Check for help flag
if ($argc === 1 || ($argc === 2 && in_array($argv[1], ['--help', '-h', 'help']))) {
    echo "Route Generator\n";
    echo "===============\n\n";
    echo "Usage: php generate route <method> <path>\n\n";
    echo "Arguments:\n";
    echo "  method    HTTP method (get, post, put, delete, patch)\n";
    echo "  path      Route path (e.g., /login, /items/{id}/view)\n\n";
    echo "Examples:\n";
    echo "  php generate route post /login\n";
    echo "  php generate route get /items/{itemId}/view\n";
    echo "  php generate route put /users/{userId}/update\n\n";
    echo "This will create:\n";
    echo "  - Route handler class in src/App/Routes/\n";
    echo "  - Interface contract in src/App/Contracts/\n";
    echo "  - Request DTO in src/App/DTO/\n";
    echo "  - Response DTO in src/App/DTO/\n";
    echo "  - Updates src/config/routes.php\n";
    exit(0);
}

if ($argc !== 3) {
    echo "Error: Invalid number of arguments\n";
    echo "Usage: php generate route <method> <path>\n";
    echo "Run 'php generate route --help' for more information.\n";
    exit(1);
}

$method = strtoupper($argv[1]);
$path = $argv[2];

// Validate HTTP method
$valid_methods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'];
if (!in_array($method, $valid_methods)) {
    echo "Error: Invalid HTTP method '$argv[1]'\n";
    echo "Valid methods: " . implode(', ', array_map('strtolower', $valid_methods)) . "\n";
    exit(1);
}

// Validate path
if (!str_starts_with($path, '/')) {
    echo "Error: Path must start with '/'\n";
    echo "Example: /login\n";
    exit(1);
}

/**
 * Convert path to class name base
 * /login -> Login
 * /items/{itemId}/view -> ItemsView
 * /users/{userId}/posts/{postId} -> UsersPostsView
 */
function pathToClassName(string $path): string {
    // Remove leading/trailing slashes
    $path = trim($path, '/');

    // Split by /
    $parts = explode('/', $path);

    // Remove parameter parts (e.g., {itemId})
    $parts = array_filter($parts, fn($part) => !preg_match('/^\{.+\}$/', $part));

    // Convert each part to PascalCase
    $parts = array_map(function($part) {
        // Convert kebab-case or snake_case to PascalCase
        return implode('', array_map('ucfirst', preg_split('/[-_]/', $part)));
    }, $parts);

    // Join parts
    $className = implode('', $parts);

    // If empty (e.g., path was just "/{id}"), use a default
    if (empty($className)) {
        $className = 'Index';
    }

    return $className;
}

/**
 * Extract parameter names from path
 * /items/{itemId}/view -> ['itemId']
 */
function extractPathParams(string $path): array {
    preg_match_all('/\{([^}]+)\}/', $path, $matches);
    return $matches[1] ?? [];
}

/**
 * Convert path parameters from :param to {param} format
 * /items/:itemId/view -> /items/{itemId}/view
 */
function normalizePathParams(string $path): string {
    return preg_replace('/:([a-zA-Z0-9_-]+)/', '{$1}', $path);
}

// Normalize path (convert :param to {param})
$path = normalizePathParams($path);

// Generate class names
$baseClassName = pathToClassName($path);
$routeClassName = $baseClassName . 'Route';
$interfaceName = 'I' . $baseClassName . 'Route';
$requestClassName = $baseClassName . 'Request';
$responseClassName = $baseClassName . 'Response';

// Extract path parameters
$pathParams = extractPathParams($path);

// Create directories
$dirs = [
    'routes' => SRC_PATH . 'App' . DIRECTORY_SEPARATOR . 'Routes',
    'contracts' => SRC_PATH . 'App' . DIRECTORY_SEPARATOR . 'Contracts',
    'dto' => SRC_PATH . 'App' . DIRECTORY_SEPARATOR . 'DTO',
];

foreach ($dirs as $name => $dir) {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            echo "Error: Failed to create $dir directory\n";
            exit(1);
        }
        echo "Created $dir directory\n";
    }
}

// File paths
$routeFilePath = $dirs['routes'] . DIRECTORY_SEPARATOR . $routeClassName . '.php';
$interfaceFilePath = $dirs['contracts'] . DIRECTORY_SEPARATOR . $interfaceName . '.php';
$requestFilePath = $dirs['dto'] . DIRECTORY_SEPARATOR . $requestClassName . '.php';
$responseFilePath = $dirs['dto'] . DIRECTORY_SEPARATOR . $responseClassName . '.php';

// Check if route already exists
if (file_exists($routeFilePath)) {
    echo "Error: Route file already exists: $routeFilePath\n";
    echo "Delete it first if you want to regenerate it.\n";
    exit(1);
}

// Generate path parameter properties
$pathParamProperties = '';
if (!empty($pathParams)) {
    $pathParamProperties = "\n    // Path parameters\n";
    foreach ($pathParams as $param) {
        $pathParamProperties .= "    public string \$$param;\n";
    }
}

// Generate interface content
$interfaceContent = "<?php

namespace App\\Contracts;

use App\\DTO\\$requestClassName;
use App\\DTO\\$responseClassName;

interface $interfaceName
{
    public function execute($requestClassName \$request): $responseClassName;
}
";

// Generate Request DTO content
$requestContent = <<<EOD
<?php

namespace App\DTO;

class $requestClassName
{
    public function __construct(
        // TODO: Add request properties
        // Example: public readonly string \$email,
    ) {}
}

EOD;

// Generate Response DTO content
$responseContent = <<<EOD
<?php

namespace App\DTO;

class $responseClassName
{
    public function __construct(
        // TODO: Add response properties
        // Example: public readonly string \$token,
    ) {}
}

EOD;

// Generate Route Handler content
$routeContent = <<<'EOD'
<?php

namespace App\Routes;

use Framework\IRouteHandler;
use Framework\ApiResponse;
use App\Contracts\{INTERFACE_NAME};
use App\DTO\{REQUEST_CLASS};
use App\DTO\{RESPONSE_CLASS};

class {ROUTE_CLASS} implements IRouteHandler, {INTERFACE_NAME}
{{PATH_PARAMS}
    public function validation_rules(): array
    {
        return [
            // TODO: Add validation rules
            // Example: 'email' => 'required|email',
        ];
    }

    public function process(): ApiResponse
    {
        // TODO: Build request DTO from input
        $request = new {REQUEST_CLASS}(
            // Map properties here
        );

        $response = $this->execute($request);

        return res_ok($response);
    }

    public function execute({REQUEST_CLASS} $request): {RESPONSE_CLASS}
    {
        // TODO: Implement route logic
        throw new \Exception('Not Implemented');
    }
}

EOD;

// Replace placeholders
$routeContent = str_replace('{ROUTE_CLASS}', $routeClassName, $routeContent);
$routeContent = str_replace('{INTERFACE_NAME}', $interfaceName, $routeContent);
$routeContent = str_replace('{REQUEST_CLASS}', $requestClassName, $routeContent);
$routeContent = str_replace('{RESPONSE_CLASS}', $responseClassName, $routeContent);
$routeContent = str_replace('{PATH_PARAMS}', $pathParamProperties, $routeContent);

// Write files
file_put_contents($interfaceFilePath, $interfaceContent);
file_put_contents($requestFilePath, $requestContent);
file_put_contents($responseFilePath, $responseContent);
file_put_contents($routeFilePath, $routeContent);

echo "✓ Created interface: src/App/Contracts/$interfaceName.php\n";
echo "✓ Created request DTO: src/App/DTO/$requestClassName.php\n";
echo "✓ Created response DTO: src/App/DTO/$responseClassName.php\n";
echo "✓ Created route handler: src/App/Routes/$routeClassName.php\n";

// Update routes.php
$routesConfigPath = SRC_PATH . 'config' . DIRECTORY_SEPARATOR . 'routes.php';

if (!file_exists($routesConfigPath)) {
    echo "Error: routes.php not found at $routesConfigPath\n";
    exit(1);
}

// Read current routes
$routesContent = file_get_contents($routesConfigPath);

// Parse the PHP array (simple approach - assumes standard format)
// We'll add the new route to the appropriate method array

// Build the new route entry
$routeEntry = "        '$path' => \\App\\Routes\\$routeClassName::class,\n";

// Find the method array and add the route
$pattern = "/('$method'\s*=>\s*\[)([^\]]*?)(\s*\])/s";

if (preg_match($pattern, $routesContent)) {
    // Method array exists, add to it
    $routesContent = preg_replace(
        $pattern,
        "$1$2$routeEntry$3",
        $routesContent
    );
} else {
    // Method array doesn't exist, create it
    // Find the closing of the return array
    $routesContent = preg_replace(
        "/(return\s*\[)/",
        "$1\n    '$method' => [\n$routeEntry    ],",
        $routesContent,
        1
    );
}

file_put_contents($routesConfigPath, $routesContent);

echo "✓ Updated src/config/routes.php with $method $path\n";
echo "\nNext steps:\n";
echo "1. Edit src/App/DTO/$requestClassName.php to define request properties\n";
echo "2. Edit src/App/DTO/$responseClassName.php to define response properties\n";
echo "3. Implement logic in src/App/Routes/$routeClassName.php\n";
echo "4. Run: php generate client (to generate TypeScript client)\n";
