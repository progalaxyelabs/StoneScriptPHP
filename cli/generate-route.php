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

// Determine the root path (go up one level from cli/)
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
// Ensure ROOT_PATH has trailing separator
$rootPath = rtrim(ROOT_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
if (!defined('SRC_PATH')) {
    define('SRC_PATH', $rootPath . 'src' . DIRECTORY_SEPARATOR);
} else {
    $rootPath = rtrim(dirname(SRC_PATH), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
}

// Use $_SERVER['argv'] which may be modified by stone binary
$argv = $_SERVER['argv'];
$argc = $_SERVER['argc'];

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
    echo "  - Route handler class in src/App/Routes/ (extends BaseRoute)\n";
    echo "  - Service class in src/App/Services/ (business logic)\n";
    echo "  - Interface contract in src/App/Contracts/ (I{Name}Service)\n";
    echo "  - Request DTO in src/App/DTO/ (implements IRequest)\n";
    echo "  - Response DTO in src/App/DTO/ (implements IResponse)\n";
    echo "  - Updates src/config/routes.php\n";
    exit(0);
}

if ($argc !== 3) {
    echo "Error: Invalid number of arguments (got $argc)\n";
    echo "Arguments: " . implode(', ', $argv) . "\n";
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
 * Always prefixes with HTTP method for consistent naming:
 * GET /api/todos -> GetApiTodos
 * POST /api/todos -> PostApiTodos
 * PUT /api/todos/{id} -> PutApiTodosById
 * DELETE /api/todos/{id} -> DeleteApiTodosById
 * GET /items/{itemId}/view -> GetItemsByItemIdView
 */
function pathToClassName(string $path, string $method): string {
    // Remove leading/trailing slashes
    $path = trim($path, '/');

    // Split by /
    $parts = explode('/', $path);

    // Track if we have parameters
    $hasParams = false;

    // Process parts: convert params to "ByParamName" and regular parts to PascalCase
    $processedParts = [];
    foreach ($parts as $part) {
        if (preg_match('/^\{(.+)\}$/', $part, $matches)) {
            // This is a parameter like {id} or {itemId}
            $paramName = $matches[1];
            $hasParams = true;
            // Convert to "ByParamName" format (e.g., {id} -> ById, {itemId} -> ByItemId)
            $processedParts[] = 'By' . implode('', array_map('ucfirst', preg_split('/[-_]/', $paramName)));
        } else {
            // Regular path segment - convert to PascalCase
            $processedParts[] = implode('', array_map('ucfirst', preg_split('/[-_]/', $part)));
        }
    }

    // Join parts
    $className = implode('', $processedParts);

    // If empty (e.g., path was just "/"), use a default
    if (empty($className)) {
        $className = 'Index';
    }

    // Always prefix with HTTP method for consistent naming
    $className = ucfirst(strtolower($method)) . $className;

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
$baseClassName = pathToClassName($path, $method);
$routeClassName = $baseClassName . 'Route';
$serviceClassName = $baseClassName . 'Service';
$interfaceName = 'I' . $baseClassName . 'Service';
$requestClassName = $baseClassName . 'Request';
$responseClassName = $baseClassName . 'Response';

// Extract path parameters
$pathParams = extractPathParams($path);

// Create directories
$dirs = [
    'routes' => SRC_PATH . 'App' . DIRECTORY_SEPARATOR . 'Routes',
    'services' => SRC_PATH . 'App' . DIRECTORY_SEPARATOR . 'Services',
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
$serviceFilePath = $dirs['services'] . DIRECTORY_SEPARATOR . $serviceClassName . '.php';
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

// Generate interface content (now for Service)
$interfaceContent = "<?php

namespace App\\Contracts;

use App\\DTO\\$requestClassName;
use App\\DTO\\$responseClassName;

interface $interfaceName
{
    public function execute($requestClassName \$request): $responseClassName;
}
";

// Generate Service class content
$serviceContent = "<?php

namespace App\\Services;

use App\\Contracts\\$interfaceName;
use App\\DTO\\$requestClassName;
use App\\DTO\\$responseClassName;

class $serviceClassName implements $interfaceName
{
    public function execute($requestClassName \$request): $responseClassName
    {
        // TODO: Implement business logic
        throw new \\Exception('Not Implemented');
    }
}
";

// Generate Request DTO content
$requestContent = <<<EOD
<?php

namespace App\DTO;

use StoneScriptPHP\IRequest;

class $requestClassName implements IRequest
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

use StoneScriptPHP\IResponse;

class $responseClassName implements IResponse
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

use StoneScriptPHP\BaseRoute;
use StoneScriptPHP\IRequest;
use StoneScriptPHP\IResponse;
use App\Contracts\{INTERFACE_NAME};
use App\Services\{SERVICE_CLASS};
use App\DTO\{REQUEST_CLASS};
use App\DTO\{RESPONSE_CLASS};

class {ROUTE_CLASS} extends BaseRoute
{{PATH_PARAMS}
    private {INTERFACE_NAME} $service;

    public function __construct()
    {
        $this->service = new {SERVICE_CLASS}();
    }

    public function validation_rules(): array
    {
        return [
            // TODO: Add validation rules
            // Example: 'email' => 'required|email',
        ];
    }

    protected function buildRequest(): IRequest
    {
        // TODO: Build request DTO from input
        return new {REQUEST_CLASS}(
            // Map properties here
        );
    }

    protected function execute(IRequest $request): IResponse
    {
        return $this->service->execute($request);
    }
}

EOD;

// Replace placeholders
$routeContent = str_replace('{ROUTE_CLASS}', $routeClassName, $routeContent);
$routeContent = str_replace('{SERVICE_CLASS}', $serviceClassName, $routeContent);
$routeContent = str_replace('{INTERFACE_NAME}', $interfaceName, $routeContent);
$routeContent = str_replace('{REQUEST_CLASS}', $requestClassName, $routeContent);
$routeContent = str_replace('{RESPONSE_CLASS}', $responseClassName, $routeContent);
$routeContent = str_replace('{PATH_PARAMS}', $pathParamProperties, $routeContent);

// Write files
file_put_contents($interfaceFilePath, $interfaceContent);
file_put_contents($serviceFilePath, $serviceContent);
file_put_contents($requestFilePath, $requestContent);
file_put_contents($responseFilePath, $responseContent);
file_put_contents($routeFilePath, $routeContent);

echo "Created interface: src/App/Contracts/$interfaceName.php\n";
echo "Created service: src/App/Services/$serviceClassName.php\n";
echo "Created request DTO: src/App/DTO/$requestClassName.php\n";
echo "Created response DTO: src/App/DTO/$responseClassName.php\n";
echo "Created route handler: src/App/Routes/$routeClassName.php\n";

// Update routes.php
$routesConfigPath = SRC_PATH . 'config' . DIRECTORY_SEPARATOR . 'routes.php';

if (!file_exists($routesConfigPath)) {
    echo "Error: routes.php not found at $routesConfigPath\n";
    exit(1);
}

// Load the routes array by including the file
$routes = require $routesConfigPath;

// Add the new route to the appropriate method array
if (!isset($routes[$method])) {
    $routes[$method] = [];
}

// Check if route already exists
if (isset($routes[$method][$path])) {
    echo "Warning: Route $method $path already exists in routes.php\n";
    echo "Existing route will be replaced.\n";
}

// Store the class reference (will be formatted as ::class in output)
$routes[$method][$path] = ['class' => "\\App\\Routes\\$routeClassName", 'is_class_ref' => true];

// Generate the formatted PHP code
$routesCode = "<?php\n\nreturn [\n";

foreach ($routes as $httpMethod => $methodRoutes) {
    $routesCode .= "    '$httpMethod' => [\n";
    foreach ($methodRoutes as $routePath => $routeData) {
        // Handle both old format (string) and new format (array with metadata)
        if (is_array($routeData) && isset($routeData['is_class_ref']) && $routeData['is_class_ref']) {
            // New route we just added - use ::class notation
            $routesCode .= "        '$routePath' => {$routeData['class']}::class,\n";
        } else {
            // Existing route - ensure it uses ::class notation
            $routeClass = is_array($routeData) ? $routeData['class'] : $routeData;

            // If it doesn't already end with ::class, add it
            if (!str_ends_with($routeClass, '::class')) {
                $routesCode .= "        '$routePath' => {$routeClass}::class,\n";
            } else {
                $routesCode .= "        '$routePath' => $routeClass,\n";
            }
        }
    }
    $routesCode .= "    ],\n";
}

$routesCode .= "];\n";

// Write back to file
file_put_contents($routesConfigPath, $routesCode);

echo "✓ Updated src/config/routes.php with $method $path\n";

// Validate PHP syntax of generated files
$filesToValidate = [
    $routeFilePath,
    $serviceFilePath,
    $interfaceFilePath,
    $requestFilePath,
    $responseFilePath,
    $routesConfigPath,
];

$syntaxErrors = [];
foreach ($filesToValidate as $file) {
    $output = [];
    $returnVar = 0;
    exec("php -l " . escapeshellarg($file) . " 2>&1", $output, $returnVar);
    if ($returnVar !== 0) {
        $syntaxErrors[] = [
            'file' => $file,
            'error' => implode("\n", $output)
        ];
    }
}

if (!empty($syntaxErrors)) {
    echo "\n⚠️  WARNING: Syntax errors detected in generated files:\n";
    foreach ($syntaxErrors as $error) {
        echo "\n" . basename($error['file']) . ":\n";
        echo $error['error'] . "\n";
    }
    echo "\nPlease fix these syntax errors before running the application.\n";
} else {
    echo "✓ All generated files have valid PHP syntax\n";
}

echo "\nNext steps:\n";
echo "1. Edit src/App/DTO/$requestClassName.php to define request properties\n";
echo "2. Edit src/App/DTO/$responseClassName.php to define response properties\n";
echo "3. Edit src/App/Routes/$routeClassName.php to build request from input\n";
echo "4. Implement business logic in src/App/Services/$serviceClassName.php\n";
echo "5. Run: php generate client (to generate TypeScript client)\n";
