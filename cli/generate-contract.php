<?php

declare(strict_types=1);

/**
 * Contract Generator
 *
 * Auto-generates contract interfaces and Request/Response DTOs from route handler classes
 * using PHP Reflection - no AI needed.
 *
 * Usage:
 *   php stone generate contract [ClassName] [--dry-run] [--force]
 *
 * Examples:
 *   php stone generate contract                    # Generate for all routes
 *   php stone generate contract GetApiTodosRoute   # Generate for specific route
 *   php stone generate contract --dry-run          # Preview what would be generated
 *   php stone generate contract --force            # Overwrite existing files
 */

require_once __DIR__ . '/generate-common.php';

// Use $_SERVER values if set by stone script, otherwise use global $argc/$argv
$argc = $_SERVER['argc'] ?? $argc;
$argv = $_SERVER['argv'] ?? $argv;

// Parse flags
$dryRun = in_array('--dry-run', $argv);
$force = in_array('--force', $argv);
$targetClassName = null;

// Find target class name (first non-flag argument)
foreach ($argv as $idx => $arg) {
    if ($idx === 0) continue; // skip script name
    if (str_starts_with($arg, '--')) continue; // skip flags
    $targetClassName = $arg;
    break;
}

// Check for help flag
if (in_array('--help', $argv) || in_array('-h', $argv) || in_array('help', $argv)) {
    echo "Contract Generator\n";
    echo "==================\n\n";
    echo "Auto-generates contract interfaces and Request/Response DTOs from route handler classes.\n\n";
    echo "Usage: php stone generate contract [ClassName] [--dry-run] [--force]\n\n";
    echo "Arguments:\n";
    echo "  ClassName   (Optional) Route handler class name to generate contract for\n";
    echo "              If omitted, generates contracts for all routes\n\n";
    echo "Options:\n";
    echo "  --dry-run   Preview what would be generated without creating files\n";
    echo "  --force     Overwrite existing contract files\n\n";
    echo "Examples:\n";
    echo "  php stone generate contract                    # Generate for all routes\n";
    echo "  php stone generate contract GetApiTodosRoute   # Generate for specific route\n";
    echo "  php stone generate contract --dry-run          # Preview mode\n";
    echo "  php stone generate contract --force            # Overwrite existing\n\n";
    echo "What this generates:\n";
    echo "  - Contract interface: src/App/Contracts/I{ClassName}.php\n";
    echo "  - Request DTO: src/App/DTO/{ClassName}Request.php\n";
    echo "  - Response DTO: src/App/DTO/{ClassName}Response.php (placeholder)\n\n";
    echo "How it works:\n";
    echo "  1. Scans src/config/routes.php for route → handler mappings\n";
    echo "  2. Uses PHP Reflection to extract public properties from route classes\n";
    echo "  3. Infers required/optional from validation_rules() method\n";
    echo "  4. Generates typed DTOs with readonly constructor params\n";
    echo "  5. Skips routes that already have contracts implemented\n\n";
    exit(0);
}

// Load routes configuration
$routesConfigPath = SRC_PATH . 'config' . DIRECTORY_SEPARATOR . 'routes.php';

if (!file_exists($routesConfigPath)) {
    echo "Error: routes.php not found at $routesConfigPath\n";
    echo "Make sure you're in a StoneScriptPHP project directory.\n";
    exit(1);
}

$routes = require $routesConfigPath;

if (!is_array($routes)) {
    echo "Error: routes.php must return an array\n";
    exit(1);
}

// Collect all route handler classes
$routeHandlers = [];
foreach ($routes as $method => $methodRoutes) {
    foreach ($methodRoutes as $path => $handler) {
        $className = is_string($handler) ? ltrim($handler, '\\') : null;
        if ($className && class_exists($className)) {
            $routeHandlers[] = [
                'method' => $method,
                'path' => $path,
                'class' => $className,
            ];
        }
    }
}

if (empty($routeHandlers)) {
    echo "No route handlers found in routes.php\n";
    exit(0);
}

// Filter to target class if specified
if ($targetClassName !== null) {
    $routeHandlers = array_filter($routeHandlers, function($route) use ($targetClassName) {
        $shortName = basename(str_replace('\\', '/', $route['class']));
        return $shortName === $targetClassName || $route['class'] === $targetClassName;
    });

    if (empty($routeHandlers)) {
        echo "Error: Route handler '$targetClassName' not found\n";
        echo "Available route handlers:\n";
        $all = require $routesConfigPath;
        foreach ($all as $method => $methodRoutes) {
            foreach ($methodRoutes as $path => $handler) {
                if (is_string($handler) && class_exists($handler)) {
                    $shortName = basename(str_replace('\\', '/', $handler));
                    echo "  - $shortName ($method $path)\n";
                }
            }
        }
        exit(1);
    }
}

/**
 * Extract public properties from a class using Reflection
 */
function extractPublicProperties(string $className): array
{
    if (!class_exists($className)) {
        return [];
    }

    $reflection = new ReflectionClass($className);
    $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

    $result = [];
    foreach ($properties as $property) {
        // Skip inherited properties from BaseRoute/interfaces
        if ($property->getDeclaringClass()->getName() !== $className) {
            continue;
        }

        $name = $property->getName();
        $type = $property->getType();
        $phpType = 'mixed';

        if ($type instanceof ReflectionNamedType) {
            $phpType = $type->getName();
        } elseif ($type instanceof ReflectionUnionType) {
            $types = array_map(fn($t) => $t->getName(), $type->getTypes());
            $phpType = implode('|', $types);
        }

        $result[$name] = [
            'name' => $name,
            'type' => $phpType,
            'hasDefault' => $property->hasDefaultValue(),
            'default' => $property->hasDefaultValue() ? $property->getDefaultValue() : null,
        ];
    }

    return $result;
}

/**
 * Get validation rules from route handler class
 */
function getValidationRules(string $className): array
{
    if (!class_exists($className)) {
        return [];
    }

    try {
        $instance = new $className();
        if (method_exists($instance, 'validation_rules')) {
            return $instance->validation_rules();
        }
    } catch (Throwable $e) {
        // Ignore instantiation errors - some routes may have constructor dependencies
        return [];
    }

    return [];
}

/**
 * Determine if a field is required based on validation rules
 */
function isRequired(string $fieldName, array $validationRules): bool
{
    if (!isset($validationRules[$fieldName])) {
        return false;
    }

    $rules = $validationRules[$fieldName];
    if (is_string($rules)) {
        return str_contains($rules, 'required');
    }

    if (is_array($rules)) {
        return in_array('required', $rules);
    }

    return false;
}

/**
 * Infer PHP type from validation rules
 */
function inferTypeFromValidation(string $fieldName, array $validationRules): string
{
    if (!isset($validationRules[$fieldName])) {
        return 'mixed';
    }

    $rules = $validationRules[$fieldName];
    $ruleString = is_array($rules) ? implode('|', $rules) : (string)$rules;

    if (str_contains($ruleString, 'email')) return 'string';
    if (str_contains($ruleString, 'integer')) return 'int';
    if (str_contains($ruleString, 'numeric')) return 'float';
    if (str_contains($ruleString, 'boolean')) return 'bool';
    if (str_contains($ruleString, 'array')) return 'array';
    if (str_contains($ruleString, 'string')) return 'string';

    return 'mixed';
}

/**
 * Generate contract interface content
 */
function generateContractInterface(string $className, string $requestClass, string $responseClass): string
{
    $interfaceName = 'I' . basename(str_replace('\\', '/', $className));
    $requestClassName = basename(str_replace('\\', '/', $requestClass));
    $responseClassName = basename(str_replace('\\', '/', $responseClass));

    return "<?php

declare(strict_types=1);

namespace App\\Contracts;

use App\\DTO\\{$requestClassName};
use App\\DTO\\{$responseClassName};

interface $interfaceName
{
    public function execute({$requestClassName} \$request): {$responseClassName};
}
";
}

/**
 * Generate Request DTO content
 */
function generateRequestDTO(string $className, array $properties, array $validationRules): string
{
    $requestClassName = basename(str_replace('\\', '/', $className)) . 'Request';

    $constructorParams = [];
    foreach ($properties as $prop) {
        $name = $prop['name'];
        $type = $prop['type'];
        $required = isRequired($name, $validationRules);

        // Infer type from validation if property type is mixed
        if ($type === 'mixed') {
            $type = inferTypeFromValidation($name, $validationRules);
        }

        // Make optional if not required
        $nullable = $required ? '' : '?';
        $default = $required ? '' : ' = null';

        $constructorParams[] = "        public readonly {$nullable}{$type} \${$name}{$default},";
    }

    $constructorBody = empty($constructorParams)
        ? "        // No request parameters\n"
        : implode("\n", $constructorParams);

    return "<?php

declare(strict_types=1);

namespace App\\DTO;

use StoneScriptPHP\\IRequest;

class {$requestClassName} implements IRequest
{
    public function __construct(
{$constructorBody}
    ) {}
}
";
}

/**
 * Generate Response DTO content (placeholder)
 */
function generateResponseDTO(string $className): string
{
    $responseClassName = basename(str_replace('\\', '/', $className)) . 'Response';

    return "<?php

declare(strict_types=1);

namespace App\\DTO;

use StoneScriptPHP\\IResponse;

class {$responseClassName} implements IResponse
{
    public function __construct(
        // TODO: Add response properties based on your API response
        // Example: public readonly string \$message,
        // Example: public readonly array \$data,
    ) {}
}
";
}

// Create directories
$dirs = [
    'contracts' => SRC_PATH . 'App' . DIRECTORY_SEPARATOR . 'Contracts',
    'dto' => SRC_PATH . 'App' . DIRECTORY_SEPARATOR . 'DTO',
];

foreach ($dirs as $name => $dir) {
    if (!is_dir($dir) && !$dryRun) {
        if (!mkdir($dir, 0755, true)) {
            echo "Error: Failed to create $dir directory\n";
            exit(1);
        }
        echo "Created $dir directory\n";
    }
}

// Generate contracts
$generatedCount = 0;
$skippedCount = 0;

foreach ($routeHandlers as $route) {
    $className = $route['class'];
    $shortName = basename(str_replace('\\', '/', $className));
    $method = $route['method'];
    $path = $route['path'];

    echo "\nProcessing: $shortName ($method $path)\n";

    // Extract properties and validation rules
    $properties = extractPublicProperties($className);
    $validationRules = getValidationRules($className);

    // Generate class names
    $interfaceName = 'I' . $shortName;
    $requestClassName = $shortName . 'Request';
    $responseClassName = $shortName . 'Response';

    // File paths
    $interfaceFile = $dirs['contracts'] . DIRECTORY_SEPARATOR . $interfaceName . '.php';
    $requestFile = $dirs['dto'] . DIRECTORY_SEPARATOR . $requestClassName . '.php';
    $responseFile = $dirs['dto'] . DIRECTORY_SEPARATOR . $responseClassName . '.php';

    // Check if files already exist
    $filesExist = file_exists($interfaceFile) || file_exists($requestFile) || file_exists($responseFile);

    if ($filesExist && !$force) {
        echo "  ⏭  Skipped (files already exist, use --force to overwrite)\n";
        $skippedCount++;
        continue;
    }

    // Generate content
    $interfaceContent = generateContractInterface($shortName, $requestClassName, $responseClassName);
    $requestContent = generateRequestDTO($shortName, $properties, $validationRules);
    $responseContent = generateResponseDTO($shortName);

    if ($dryRun) {
        echo "  📄 Would generate:\n";
        echo "     - src/App/Contracts/{$interfaceName}.php\n";
        echo "     - src/App/DTO/{$requestClassName}.php\n";
        echo "     - src/App/DTO/{$responseClassName}.php\n";

        if (!empty($properties)) {
            echo "  📝 Request properties:\n";
            foreach ($properties as $prop) {
                $required = isRequired($prop['name'], $validationRules) ? 'required' : 'optional';
                echo "     - {$prop['type']} \${$prop['name']} ($required)\n";
            }
        } else {
            echo "  ℹ️  No public properties found\n";
        }
    } else {
        // Write files
        file_put_contents($interfaceFile, $interfaceContent);
        file_put_contents($requestFile, $requestContent);
        file_put_contents($responseFile, $responseContent);

        echo "  ✓ Generated src/App/Contracts/{$interfaceName}.php\n";
        echo "  ✓ Generated src/App/DTO/{$requestClassName}.php\n";
        echo "  ✓ Generated src/App/DTO/{$responseClassName}.php\n";

        if (!empty($properties)) {
            echo "  📝 Request properties:\n";
            foreach ($properties as $prop) {
                $required = isRequired($prop['name'], $validationRules) ? 'required' : 'optional';
                echo "     - {$prop['type']} \${$prop['name']} ($required)\n";
            }
        }

        $generatedCount++;
    }
}

echo "\n" . str_repeat('=', 60) . "\n";
if ($dryRun) {
    echo "DRY RUN - No files were created\n";
    echo "Total routes that would be processed: " . count($routeHandlers) . "\n";
} else {
    echo "Summary:\n";
    echo "  Generated: $generatedCount\n";
    echo "  Skipped: $skippedCount\n";

    if ($generatedCount > 0) {
        echo "\nNext steps:\n";
        echo "1. Review generated DTOs and add missing properties\n";
        echo "2. Update route handlers to implement the generated interfaces\n";
        echo "3. Run: php stone generate client (to regenerate TypeScript client)\n";
    }
}

echo "\n";
