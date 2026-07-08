<?php

/**
 * Route Generator
 *
 * Generates a route handler implementing IRouteHandler directly — the only
 * pattern used by any real platform in the fleet — and registers it in
 * src/config/routes.php using the flat array format with v4.0 metadata
 * (service/group/action/is_public). See ROUTING-CONSOLIDATION-PLAN.md and
 * SPEC.md §3 Routing Conventions.
 *
 * Previously this generated a 5-file BaseRoute/Service/Contract/DTO split
 * and wrote routes.php entries keyed by a 'class' field that no real
 * platform's routes.php ever used (they use 'handler') — running this
 * command against any real platform's routes.php would corrupt it. Fixed
 * as part of the v6.0.0 routing consolidation.
 *
 * Usage:
 *   php generate route <method> <path> [--service=NAME] [--group=NAME] [--action=NAME] [--public]
 *
 * Example:
 *   php generate route post /login --service=infra --group=auth --public
 *   php generate route get /items/{itemId} --service=portal --group=inventory --action=get
 *
 * NOTE: routes.php is rewritten from its parsed array representation, same
 * as before this fix — this does not preserve hand-written comments in the
 * existing file. Review the diff after running this command.
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
    echo "Usage: php generate route <method> <path> [options]\n\n";
    echo "Arguments:\n";
    echo "  method    HTTP method (get, post, put, delete, patch)\n";
    echo "  path      Route path (e.g., /login, /items/{id}/view)\n\n";
    echo "Options:\n";
    echo "  --service=NAME   Service partition key for client generation (default: shared).\n";
    echo "                   'infra'/'webhook' are excluded from all generated clients.\n";
    echo "  --group=NAME     Domain-concept group for client generation. Required on any\n";
    echo "                   route that should appear in a generated client (not infra/webhook).\n";
    echo "  --action=NAME    Explicit action name override (default: derived from path).\n";
    echo "  --public         Mark route public (no JWT required).\n\n";
    echo "Examples:\n";
    echo "  php generate route post /login --service=infra --group=auth --public\n";
    echo "  php generate route get /items/{itemId} --service=portal --group=inventory --action=get\n\n";
    echo "This will create:\n";
    echo "  - Route handler class in src/App/Routes/ (implements IRouteHandler directly)\n";
    echo "  - Registers it in src/config/routes.php (flat array format, v4.0 metadata)\n";
    exit(0);
}

// Parse positional args (method, path) and --flag options, in any order.
$positional = [];
$flags = ['service' => 'shared', 'group' => null, 'action' => null, 'public' => false];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--service=')) {
        $flags['service'] = substr($arg, strlen('--service='));
    } elseif (str_starts_with($arg, '--group=')) {
        $flags['group'] = substr($arg, strlen('--group='));
    } elseif (str_starts_with($arg, '--action=')) {
        $flags['action'] = substr($arg, strlen('--action='));
    } elseif ($arg === '--public') {
        $flags['public'] = true;
    } elseif (!str_starts_with($arg, '--')) {
        $positional[] = $arg;
    }
}

if (count($positional) !== 2) {
    echo 'Error: Invalid arguments (expected <method> <path>, got ' . count($positional) . ")\n";
    echo "Usage: php generate route <method> <path> [options]\n";
    echo "Run 'php generate route --help' for more information.\n";
    exit(1);
}

$method = strtoupper($positional[0]);
$path = $positional[1];

// Validate HTTP method
$valid_methods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'];
if (!in_array($method, $valid_methods)) {
    echo "Error: Invalid HTTP method '{$positional[0]}'\n";
    echo "Valid methods: " . implode(', ', array_map('strtolower', $valid_methods)) . "\n";
    exit(1);
}

// Validate path
if (!str_starts_with($path, '/')) {
    echo "Error: Path must start with '/'\n";
    echo "Example: /login\n";
    exit(1);
}

if ($flags['group'] === null && !in_array($flags['service'], ['infra', 'webhook'], true)) {
    echo "Warning: no --group=NAME given and service '{$flags['service']}' is not infra/webhook.\n";
    echo "This route will be excluded from client generation until you add a 'group' key\n";
    echo "to its routes.php entry (CLIENT-SDK-SPEC §0 A2 requires it on includable routes).\n\n";
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

    // Process parts: convert params to "ByParamName" and regular parts to PascalCase
    $processedParts = [];
    foreach ($parts as $part) {
        if (preg_match('/^\{(.+)\}$/', $part, $matches)) {
            // This is a parameter like {id} or {itemId}
            $paramName = $matches[1];
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

/**
 * Render a routes.php route value (string handler or v4.0 metadata array)
 * back to PHP source. Existing entries loaded via require() have already
 * had any ::class constant resolved to its FQCN string — re-append ::class
 * on the way back out. Used both for the newly-added route and for every
 * pre-existing route already in the file, so a regenerate never drops
 * anyone else's service/group/action/is_public metadata.
 */
function renderRouteValue($routeData): string {
    if (is_string($routeData)) {
        $class = str_ends_with($routeData, '::class') ? substr($routeData, 0, -7) : $routeData;
        return $class . '::class';
    }

    if (is_array($routeData)) {
        $parts = [];
        foreach ($routeData as $key => $value) {
            if ($key === 'handler' && is_string($value)) {
                $class = str_ends_with($value, '::class') ? substr($value, 0, -7) : $value;
                $parts[] = "'handler' => {$class}::class";
            } else {
                $parts[] = var_export($key, true) . ' => ' . var_export($value, true);
            }
        }
        return '[' . implode(', ', $parts) . ']';
    }

    // Shouldn't happen — Router::normalizeRouteConfig() only ever produces
    // string|object|array route values — but never silently drop data.
    return var_export($routeData, true);
}

// Normalize path (convert :param to {param})
$path = normalizePathParams($path);

// Generate class name
$baseClassName = pathToClassName($path, $method);
$routeClassName = $baseClassName . 'Route';

// Validate routes.php BEFORE writing any files — a rejected/unreadable
// routes.php must not leave an orphan route class with nothing registering
// it (matters most for the removed-format check below, which is the one
// most likely to actually fire for someone mid-migration).
$routesConfigPath = SRC_PATH . 'config' . DIRECTORY_SEPARATOR . 'routes.php';

if (!file_exists($routesConfigPath)) {
    echo "Error: routes.php not found at $routesConfigPath\n";
    exit(1);
}

$routes = require $routesConfigPath;

if (!is_array($routes)) {
    echo "Error: routes.php must return an array. See SPEC.md §3 Routing Conventions.\n";
    exit(1);
}

if (array_key_exists('public', $routes) || array_key_exists('protected', $routes)) {
    echo "Error: routes.php uses the removed 'public'/'protected' sectioned format.\n";
    echo "Migrate it to the flat format first — see SPEC.md §3 Routing Conventions.\n";
    exit(1);
}

// Extract path parameters
$pathParams = extractPathParams($path);

// Create routes directory
$routesDir = SRC_PATH . 'App' . DIRECTORY_SEPARATOR . 'Routes';
if (!is_dir($routesDir)) {
    if (!mkdir($routesDir, 0755, true)) {
        echo "Error: Failed to create $routesDir directory\n";
        exit(1);
    }
    echo "Created $routesDir directory\n";
}

$routeFilePath = $routesDir . DIRECTORY_SEPARATOR . $routeClassName . '.php';

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

// Generate Route Handler content — implements IRouteHandler directly,
// matching every real platform's actual routes (no BaseRoute, no separate
// Service/Contract/DTO split — see ROUTING-CONSOLIDATION-PLAN.md).
$routeContent = <<<EOD
<?php

declare(strict_types=1);

namespace App\\Routes;

use StoneScriptPHP\\ApiResponse;
use StoneScriptPHP\\IRouteHandler;
use StoneScriptPHP\\Database;

/**
 * $method $path
 */
class $routeClassName implements IRouteHandler
{{$pathParamProperties}
    public function validation_rules(): array
    {
        return [
            // TODO: Add validation rules
            // Example: 'email' => 'required|email',
        ];
    }

    public function process(): ApiResponse
    {
        try {
            // TODO: Implement business logic, typically:
            //   \$rows = Database::fn('some_sql_function', [...]);
            //   return res_ok(\$rows);
            throw new \\Exception('Not Implemented');
        } catch (\\Exception \$e) {
            log_error('Error in $routeClassName: ' . \$e->getMessage());
            return res_error('$routeClassName failed');
        }
    }
}

EOD;

file_put_contents($routeFilePath, $routeContent);
echo "Created route handler: src/App/Routes/$routeClassName.php\n";

// Add the new route to the appropriate method array (routes.php already
// loaded + validated above, before the route class file was written)
if (!isset($routes[$method])) {
    $routes[$method] = [];
}

// Check if route already exists
if (isset($routes[$method][$path])) {
    echo "Warning: Route $method $path already exists in routes.php\n";
    echo "Existing route will be replaced.\n";
}

$newEntry = ['handler' => "\\App\\Routes\\$routeClassName"];
if ($flags['service'] !== 'shared') {
    $newEntry['service'] = $flags['service'];
}
if ($flags['group'] !== null) {
    $newEntry['group'] = $flags['group'];
}
if ($flags['action'] !== null) {
    $newEntry['action'] = $flags['action'];
}
if ($flags['public']) {
    $newEntry['is_public'] = true;
}

$routes[$method][$path] = $newEntry;

// Generate the formatted PHP code. Every pre-existing route is re-rendered
// via renderRouteValue() so its service/group/action/is_public metadata
// (if it's the v4.0 array form real platforms use) survives the round trip
// — the old version of this generator only handled a bare 'class' key and
// would have silently dropped all of this on any real platform's routes.php.
$routesCode = "<?php\n\nreturn [\n";

foreach ($routes as $httpMethod => $methodRoutes) {
    if (!is_array($methodRoutes)) {
        continue;
    }
    $routesCode .= "    '$httpMethod' => [\n";
    foreach ($methodRoutes as $routePath => $routeData) {
        $routesCode .= "        " . var_export($routePath, true) . " => " . renderRouteValue($routeData) . ",\n";
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
echo "1. Edit src/App/Routes/$routeClassName.php to implement business logic\n";
echo "2. Add validation rules to validation_rules()\n";
if ($flags['group'] === null && !in_array($flags['service'], ['infra', 'webhook'], true)) {
    echo "3. Add a 'group' key to this route's routes.php entry before generating a client\n";
    echo "4. Run: php stone generate client (to generate TypeScript client)\n";
} else {
    echo "3. Run: php stone generate client (to generate TypeScript client)\n";
}
