<?php

/**
 * API Client Generator
 *
 * Generates a TypeScript or Kotlin client from PHP routes with full type safety.
 *
 * Usage:
 *   php generate client [--output=<path>] [--language=typescript|kotlin]
 *
 * Example:
 *   php generate client
 *   php generate client --output=frontend/src/api/client.ts
 *   php generate client --language=kotlin --output=/tmp/kotlin-gen
 */

// Determine the root path (go up two levels from Framework/cli)
if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('SRC_PATH', ROOT_PATH . 'src' . DIRECTORY_SEPARATOR);

// Auto-load Framework and App classes
spl_autoload_register(function ($class) {
    // Try loading from root (for Framework\... and App\...)
    $file = ROOT_PATH . str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
        return;
    }

    // Try loading from src (for App\...)
    $file = SRC_PATH . str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
        return;
    }
});

// Parse command line arguments
$outputDir = 'client';
$packageName = '@stonescript/api-client';
$apiVersion = '1.0.0';
$language = 'typescript';

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--output=')) {
        $outputDir = substr($arg, 9);
    }
    if (str_starts_with($arg, '--name=')) {
        $packageName = substr($arg, 7);
    }
    if (str_starts_with($arg, '--language=')) {
        $language = strtolower(substr($arg, 11));
    }
    if (in_array($arg, ['--help', '-h', 'help'])) {
        echo "API Client Generator\n";
        echo "=====================\n\n";
        echo "Usage: php generate client [options]\n\n";
        echo "Options:\n";
        echo "  --output=<dir>       Output directory (default: client)\n";
        echo "  --name=<name>        Package name (default: @stonescript/api-client)\n";
        echo "  --language=<lang>    Language: typescript (default) or kotlin\n\n";
        echo "Example:\n";
        echo "  php generate client\n";
        echo "  php generate client --output=packages/api-client\n";
        echo "  php generate client --language=kotlin --output=/tmp/kotlin-gen\n\n";
        exit(0);
    }
}

if (!in_array($language, ['typescript', 'kotlin'])) {
    echo "Error: Unsupported language '$language'. Use 'typescript' or 'kotlin'.\n";
    exit(1);
}

// Convert to absolute path if relative
if (!str_starts_with($outputDir, '/')) {
    $outputDir = ROOT_PATH . $outputDir;
}

/**
 * Load and parse routes from routes.php
 */
function loadRoutes(): array {
    $routesFile = SRC_PATH . 'config' . DIRECTORY_SEPARATOR . 'routes.php';

    if (!file_exists($routesFile)) {
        echo "Error: routes.php not found at $routesFile\n";
        exit(1);
    }

    $routes = require $routesFile;

    // Flatten routes by method
    $flatRoutes = [];
    foreach ($routes as $method => $methodRoutes) {
        foreach ($methodRoutes as $path => $handler) {
            $flatRoutes[] = [
                'method' => $method,
                'path' => $path,
                'handler' => $handler
            ];
        }
    }

    return $flatRoutes;
}

/**
 * Get the contract interface for a route handler
 */
function getRouteContract(string $handlerClass): ?ReflectionClass {
    if (!class_exists($handlerClass)) {
        return null;
    }

    $reflection = new ReflectionClass($handlerClass);
    $interfaces = $reflection->getInterfaces();

    // Find the contract interface (skip IRouteHandler regardless of namespace)
    foreach ($interfaces as $interface) {
        $name = $interface->getName();
        if ($name !== 'Framework\\IRouteHandler' && $name !== 'StoneScriptPHP\\IRouteHandler') {
            return $interface;
        }
    }

    return null;
}

/**
 * Extract request and response types from interface
 */
function extractContractTypes(ReflectionClass $interface): ?array {
    if (!$interface->hasMethod('execute')) {
        return null;
    }

    $method = $interface->getMethod('execute');
    $params = $method->getParameters();

    if (empty($params)) {
        return null;
    }

    $requestType = $params[0]->getType();
    $responseType = $method->getReturnType();

    if (!$requestType || !$responseType) {
        return null;
    }

    return [
        'request' => getReflectionTypeName($requestType),
        'response' => getReflectionTypeName($responseType)
    ];
}

/**
 * Safely extract type name from any ReflectionType (named, union, intersection)
 */
function getReflectionTypeName(?ReflectionType $type): string {
    if ($type === null) {
        return 'mixed';
    }

    if ($type instanceof ReflectionNamedType) {
        return $type->getName();
    }

    if ($type instanceof ReflectionUnionType) {
        // Pick the first non-null type
        foreach ($type->getTypes() as $subType) {
            if ($subType instanceof ReflectionNamedType && $subType->getName() !== 'null') {
                return $subType->getName();
            }
        }
        // All types are null? Shouldn't happen, but fallback
        return 'mixed';
    }

    if ($type instanceof ReflectionIntersectionType) {
        // Use the first type
        $types = $type->getTypes();
        if (!empty($types) && $types[0] instanceof ReflectionNamedType) {
            return $types[0]->getName();
        }
        return 'mixed';
    }

    return 'mixed';
}

/**
 * Check if a ReflectionType is a builtin type (safe for union types)
 */
function isReflectionTypeBuiltin(?ReflectionType $type): bool {
    if ($type === null) return true;

    if ($type instanceof ReflectionNamedType) {
        return $type->isBuiltin();
    }

    // Union/intersection types with class components are not considered builtin
    if ($type instanceof ReflectionUnionType) {
        foreach ($type->getTypes() as $subType) {
            if ($subType instanceof ReflectionNamedType && !$subType->isBuiltin() && $subType->getName() !== 'null') {
                return false;
            }
        }
        return true;
    }

    return true;
}

/**
 * Track types that are referenced but can't be reflected (class doesn't exist)
 */
$GLOBALS['unresolvedTypes'] = [];

/**
 * PHP type to TypeScript type mapping
 */
function phpTypeToTs(string $phpType): string {
    // Strip leading backslash (e.g., \object → object, \App\Dto → App\Dto)
    $phpType = ltrim($phpType, '\\');

    return match ($phpType) {
        'int', 'integer', 'float', 'double' => 'number',
        'bool', 'boolean' => 'boolean',
        'string' => 'string',
        'array' => 'any[]',
        'object' => 'Record<string, any>',
        'mixed', 'any' => 'any',
        'void' => 'void',
        'null' => 'null',
        default => str_contains($phpType, '\\')
            ? substr($phpType, strrpos($phpType, '\\') + 1)  // Extract short class name
            : $phpType  // Already a short name, return as-is
    };
}

/**
 * Reflect on a DTO class and extract its properties with types
 */
function reflectDto(string $className): array {
    if (!class_exists($className)) {
        return [];
    }

    $reflection = new ReflectionClass($className);
    $constructor = $reflection->getConstructor();

    if (!$constructor) {
        return [];
    }

    $properties = [];
    foreach ($constructor->getParameters() as $param) {
        $type = $param->getType();
        $typeName = getReflectionTypeName($type);
        if ($typeName === 'mixed') $typeName = 'any';
        $isNullable = $type && $type->allowsNull();

        // Check if this is a class type (nested DTO)
        $isClassType = $type && !isReflectionTypeBuiltin($type) && class_exists($typeName);

        $properties[] = [
            'name' => $param->getName(),
            'type' => $typeName,
            'nullable' => $isNullable,
            'isClass' => $isClassType,
            'optional' => $param->isOptional()
        ];
    }

    return $properties;
}

/**
 * Generate TypeScript interface from DTO
 */
function generateTsInterface(string $className, array &$processedClasses = []): string {
    // Avoid infinite recursion
    if (in_array($className, $processedClasses)) {
        return '';
    }
    $processedClasses[] = $className;

    $properties = reflectDto($className);
    if (empty($properties)) {
        return '';
    }

    $shortName = str_contains($className, '\\')
        ? substr($className, strrpos($className, '\\') + 1)
        : $className;
    $output = "export interface $shortName {\n";

    $nestedInterfaces = '';

    foreach ($properties as $prop) {
        $tsType = phpTypeToTs($prop['type']);

        // If this is a nested class, generate its interface too
        if ($prop['isClass']) {
            $nestedInterfaces .= generateTsInterface($prop['type'], $processedClasses);
            $tsType = phpTypeToTs($prop['type']);
        } elseif (!in_array($tsType, ['number', 'boolean', 'string', 'any[]', 'Record<string, any>', 'any', 'void', 'null'])) {
            // Type is not a built-in TS type and not a reflectable class — track as unresolved
            if (!in_array($tsType, $GLOBALS['unresolvedTypes'])) {
                $GLOBALS['unresolvedTypes'][] = $tsType;
            }
        }

        $optional = $prop['optional'] ? '?' : '';
        $nullable = $prop['nullable'] ? ' | null' : '';

        $output .= "  {$prop['name']}$optional: $tsType$nullable;\n";
    }

    $output .= "}\n\n";

    return $nestedInterfaces . $output;
}

/**
 * Extract resource name from path (first segment)
 * /projects/create -> projects
 * /users/{id} -> users
 * /auth/login -> auth
 */
function extractResourceName(string $path): string {
    $path = trim($path, '/');
    $parts = explode('/', $path);
    $name = $parts[0] ?? 'default';

    // Convert hyphenated/underscored names to camelCase (e.g. customer-bills → customerBills)
    if (str_contains($name, '-') || str_contains($name, '_')) {
        $name = str_replace(['-', '_'], ' ', $name);
        $name = lcfirst(str_replace(' ', '', ucwords($name)));
    }

    return $name;
}

/**
 * Convert route path to method name within a resource
 * /projects/create + POST -> create
 * /projects/:id + GET -> getById
 * /projects/:id + PUT -> update
 * /projects/:id + DELETE -> delete
 * /projects -> GET -> list
 * /payments/reference/:reference_type/:reference_id + GET -> getByReference
 */
function pathToMethodName(string $path, string $method): string {
    $path = trim($path, '/');
    $parts = explode('/', $path);

    // Remove the first segment (resource name)
    array_shift($parts);

    // Check if path has parameters
    $hasParams = false;
    $paramNames = [];
    foreach ($parts as $part) {
        if (preg_match('/^\{.+\}$/', $part) || preg_match('/^\:(.+)$/', $part, $matches)) {
            $hasParams = true;
            if (isset($matches[1])) {
                $paramNames[] = $matches[1];
            }
        }
    }

    // Remove parameter parts (both {param} and :param notation)
    $nonParamParts = array_filter($parts, fn($part) => !preg_match('/^\{.+\}$/', $part) && !preg_match('/^\:.+$/', $part));

    // If no non-param parts left but has params, generate method name based on HTTP method + param context
    if (empty($nonParamParts) && $hasParams) {
        $httpMethod = strtoupper($method);

        // For single :id parameter, use conventional CRUD names
        if (count($paramNames) === 1 && $paramNames[0] === 'id') {
            return match($httpMethod) {
                'GET' => 'getById',
                'PUT' => 'update',
                'DELETE' => 'delete',
                'POST' => 'create',
                default => strtolower($method)
            };
        }

        // For other parameters, create descriptive names
        // e.g., :reference_type/:reference_id -> getByReference
        if (!empty($paramNames)) {
            $paramContext = $paramNames[0];
            // Remove common suffixes like _id, _type
            $paramContext = preg_replace('/_(id|type|key|code)$/', '', $paramContext);
            $paramContext = str_replace('_', ' ', $paramContext);
            $paramContext = ucwords($paramContext);
            $paramContext = str_replace(' ', '', $paramContext);

            return match($httpMethod) {
                'GET' => 'getBy' . $paramContext,
                'PUT' => 'updateBy' . $paramContext,
                'DELETE' => 'deleteBy' . $paramContext,
                'POST' => 'createBy' . $paramContext,
                default => strtolower($method) . 'By' . $paramContext
            };
        }
    }

    // If no parts left and no params, use method-based name
    if (empty($nonParamParts)) {
        return match(strtoupper($method)) {
            'GET' => 'list',
            'POST' => 'create',
            'PUT' => 'update',
            'DELETE' => 'delete',
            default => strtolower($method)
        };
    }

    // Convert remaining non-param parts to camelCase
    $methodName = '';
    foreach ($nonParamParts as $i => $part) {
        $part = str_replace(['-', '_'], ' ', $part);
        $part = ucwords($part);
        $part = str_replace(' ', '', $part);

        if ($i === 0) {
            $part = lcfirst($part);
        }

        $methodName .= $part;
    }

    return $methodName;
}

/**
 * Extract path parameter names
 */
function extractPathParams(string $path): array {
    preg_match_all('/\:([a-zA-Z_]+)/', $path, $matches);
    return $matches[1] ?? [];
}

/**
 * Generate TypeScript API client (v2.0 - with ApiConnectionService)
 */
function generateClient(array $routes): string {
    $interfaces = '';
    $processedClasses = [];
    $resourceMethods = []; // Group methods by resource

    foreach ($routes as $route) {
        $handlerClass = $route['handler'];
        $contract = getRouteContract($handlerClass);

        if (!$contract) {
            echo "Warning: No contract interface found for {$route['path']}\n";
            continue;
        }

        $types = extractContractTypes($contract);

        if (!$types) {
            echo "Warning: Could not extract types from contract for {$route['path']}\n";
            continue;
        }

        // Generate interfaces for request and response
        $interfaces .= generateTsInterface($types['request'], $processedClasses);
        $interfaces .= generateTsInterface($types['response'], $processedClasses);

        // Get resource and method names
        $resourceName = extractResourceName($route['path']);
        $methodName = pathToMethodName($route['path'], $route['method']);
        $requestTypeName = phpTypeToTs($types['request']);
        $responseTypeName = phpTypeToTs($types['response']);

        $pathParams = extractPathParams($route['path']);
        $method = strtoupper($route['method']);

        // Build method code
        $methodCode = generateResourceMethod(
            $methodName,
            $route['path'],
            $method,
            $requestTypeName,
            $responseTypeName,
            $pathParams
        );

        // Group by resource — track method names for deduplication
        if (!isset($resourceMethods[$resourceName])) {
            $resourceMethods[$resourceName] = [];
            $resourceMethodNames[$resourceName] = [];
        }
        $resourceMethods[$resourceName][] = [
            'code' => $methodCode,
            'name' => $methodName,
            'httpMethod' => strtoupper($route['method']),
        ];
        $resourceMethodNames[$resourceName][] = $methodName;
    }

    // Deduplicate method names within each resource
    foreach ($resourceMethods as $resourceName => &$methods) {
        // First pass: prefix duplicates with HTTP method
        $nameCounts = array_count_values(array_column($methods, 'name'));
        foreach ($methods as &$methodEntry) {
            $name = $methodEntry['name'];
            if ($nameCounts[$name] > 1) {
                $httpPrefix = strtolower($methodEntry['httpMethod']);
                $newName = $httpPrefix . ucfirst($name);
                $methodEntry['code'] = str_replace(
                    "{$name}: async (",
                    "{$newName}: async (",
                    $methodEntry['code']
                );
                $methodEntry['name'] = $newName;
            }
        }
        unset($methodEntry);

        // Second pass: if still duplicates (same HTTP method), add numeric suffix
        $nameCounts = array_count_values(array_column($methods, 'name'));
        $nameOccurrence = [];
        foreach ($methods as &$methodEntry) {
            $name = $methodEntry['name'];
            if ($nameCounts[$name] > 1) {
                if (!isset($nameOccurrence[$name])) {
                    $nameOccurrence[$name] = 0;
                }
                $nameOccurrence[$name]++;
                // First occurrence keeps the name, subsequent get ById suffix or numeric
                if ($nameOccurrence[$name] > 1) {
                    $newName = $name . 'ById';
                    // If more than 2 duplicates, add number
                    if ($nameOccurrence[$name] > 2) {
                        $newName = $name . $nameOccurrence[$name];
                    }
                    $methodEntry['code'] = str_replace(
                        "{$name}: async (",
                        "{$newName}: async (",
                        $methodEntry['code']
                    );
                    $methodEntry['name'] = $newName;
                }
            }
        }
        unset($methodEntry);
    }
    unset($methods);

    // Generate resource objects
    $resourcesCode = '';
    foreach ($resourceMethods as $resourceName => $methods) {
        $methodsStr = implode("\n\n", array_column($methods, 'code'));
        $resourcesCode .= <<<TS

  /**
   * $resourceName endpoints
   */
  $resourceName = {
$methodsStr
  };

TS;
    }

    // Generate type aliases for unresolved types (referenced but not reflectable)
    $unresolvedAliases = '';
    if (!empty($GLOBALS['unresolvedTypes'])) {
        $unresolvedAliases .= "// Unresolved types (class not found during generation)\n";
        foreach ($GLOBALS['unresolvedTypes'] as $typeName) {
            $unresolvedAliases .= "export type $typeName = Record<string, any>;\n";
        }
        $unresolvedAliases .= "\n";
    }

    $output = <<<TS
/**
 * Auto-generated TypeScript API Client
 * Generated from PHP routes
 *
 * DO NOT EDIT MANUALLY - Regenerate using: php stone generate client
 */

import { ApiConnectionService, ApiResponse } from '@progalaxyelabs/ngx-stonescriptphp-client';

// ============================================================================
// Type Definitions
// ============================================================================

$unresolvedAliases$interfaces
// ============================================================================
// API Client
// ============================================================================

export class ApiClient {
  constructor(private connection: ApiConnectionService) {}
$resourcesCode}

TS;

    return $output;
}

/**
 * Generate a single method for a resource
 */
function generateResourceMethod(
    string $methodName,
    string $path,
    string $httpMethod,
    string $requestTypeName,
    string $responseTypeName,
    array $pathParams
): string {
    // Build path template
    $pathTemplate = $path;
    if (!empty($pathParams)) {
        foreach ($pathParams as $param) {
            // Replace :param with ${param} for template literal interpolation
            $pathTemplate = str_replace(":{$param}", '${' . $param . '}', $pathTemplate);
        }
        $pathTemplate = "`$pathTemplate`";
    } else {
        $pathTemplate = "'$path'";
    }

    // Determine connection method
    $connectionMethod = strtolower($httpMethod);

    // Build parameters
    if ($httpMethod === 'GET' || $httpMethod === 'DELETE') {
        // GET/DELETE: only path params
        if (!empty($pathParams)) {
            $paramsStr = implode(': number, ', $pathParams) . ': number';
        } else {
            $paramsStr = '';
        }

        // Generate method
        return <<<TS
    $methodName: async ($paramsStr): Promise<$responseTypeName> => {
      const response = await this.connection.$connectionMethod<$responseTypeName>($pathTemplate);

      return new Promise((resolve, reject) => {
        response
          .onOk((result) => resolve(result))
          .onNotOk((message) => reject(new Error(message)))
          .onError(() => reject(new Error('Server error')));
      });
    },
TS;
    } else {
        // POST/PUT/PATCH: data param + path params
        if (!empty($pathParams)) {
            $pathParamsStr = ', ' . implode(': number, ', $pathParams) . ': number';
        } else {
            $pathParamsStr = '';
        }

        return <<<TS
    $methodName: async (data: $requestTypeName$pathParamsStr): Promise<$responseTypeName> => {
      const response = await this.connection.$connectionMethod<$responseTypeName>($pathTemplate, data);

      return new Promise((resolve, reject) => {
        response
          .onOk((result) => resolve(result))
          .onNotOk((message) => reject(new Error(message)))
          .onError(() => reject(new Error('Server error')));
      });
    },
TS;
    }
}

// ============================================================================
// Kotlin Generation Functions
// ============================================================================

/**
 * Extract Database::fn() calls from a PHP route handler class.
 *
 * Returns array of ['function' => 'func_name', 'params' => ['p_param1', ...]]
 * or null if no Database::fn() call is found.
 */
function extractFunctionCalls(string $handlerClass): ?array {
    if (!class_exists($handlerClass)) {
        return null;
    }

    try {
        $reflection = new ReflectionClass($handlerClass);
        $filePath = $reflection->getFileName();
    } catch (\Throwable $e) {
        return null;
    }

    if (!$filePath || !file_exists($filePath)) {
        return null;
    }

    $source = file_get_contents($filePath);

    // Check for Database::raw() — flag these
    if (preg_match('/Database::raw\s*\(/', $source)) {
        return ['function' => '__RAW_SQL__', 'params' => [], 'raw' => true];
    }

    // Find all Database::fn() calls
    $calls = [];
    if (preg_match_all("/Database::fn\s*\(\s*'([^']+)'/", $source, $fnMatches, PREG_OFFSET_CAPTURE)) {
        foreach ($fnMatches[1] as $match) {
            $funcName = $match[0];
            $matchPos = $match[1];

            // Find the opening bracket '[' after the function name
            $afterName = substr($source, $matchPos + strlen($funcName));
            $bracketStart = strpos($afterName, '[');

            $params = [];
            $isJsonCall = false;

            if ($bracketStart !== false) {
                // Extract only the content between matching [ ... ]
                $bracketContent = '';
                $depth = 0;
                $started = false;
                for ($c = $bracketStart; $c < strlen($afterName); $c++) {
                    $ch = $afterName[$c];
                    if ($ch === '[') {
                        $depth++;
                        $started = true;
                    }
                    if ($started) $bracketContent .= $ch;
                    if ($ch === ']') {
                        $depth--;
                        if ($depth === 0) break;
                    }
                }

                // Extract parameter keys only from the matched bracket block
                if (preg_match_all("/'(p?_?[a-zA-Z_]+)'\s*=>/", $bracketContent, $paramMatches)) {
                    $params = $paramMatches[1];
                }

                // Check for json_encode pass-through inside the bracket block
                if (empty($params) && preg_match('/json_encode\s*\(/', $bracketContent)) {
                    $isJsonCall = true;
                }
            } else {
                // No bracket found — check for json_encode pass-through
                $callBlock = substr($source, $matchPos, 300);
                $isJsonCall = (bool)preg_match('/json_encode\s*\(/', $callBlock);
            }

            $calls[] = [
                'function' => $funcName,
                'params' => $params,
                'json_passthrough' => $isJsonCall,
            ];
        }
    }

    if (empty($calls)) {
        return null;
    }

    // Return the primary call (first one)
    $primary = $calls[0];
    $primary['all_calls'] = $calls;
    $primary['raw'] = false;
    return $primary;
}

/**
 * PHP type to Kotlin type mapping
 */
function phpTypeToKotlin(string $phpType): string {
    $nullable = str_starts_with($phpType, '?');
    $baseType = ltrim($phpType, '?\\');
    $suffix = $nullable ? '?' : '';

    return match ($baseType) {
        'string' => "String$suffix",
        'int', 'integer' => "Int$suffix",
        'float', 'double' => "Double$suffix",
        'bool', 'boolean' => "Boolean$suffix",
        'array' => 'List<Any?>',
        'object' => 'Map<String, Any?>',
        'mixed', 'any' => 'Any?',
        'void' => 'Unit',
        'null' => 'Nothing?',
        default => str_contains($baseType, '\\')
            ? substr($baseType, strrpos($baseType, '\\') + 1) . $suffix
            : $baseType . $suffix
    };
}

/**
 * Generate Kotlin data class from reflected DTO properties
 */
function generateKotlinDataClass(string $className, array $properties): string {
    if (empty($properties)) return '';

    $shortName = str_contains($className, '\\')
        ? substr($className, strrpos($className, '\\') + 1)
        : $className;

    $params = [];
    foreach ($properties as $prop) {
        $kotlinType = phpTypeToKotlin(($prop['nullable'] ? '?' : '') . $prop['type']);
        $default = $prop['optional'] ? " = null" : '';
        $params[] = "    val {$prop['name']}: $kotlinType$default";
    }

    return "data class $shortName(\n" . implode(",\n", $params) . "\n)\n\n";
}

/**
 * Convert a route path to a Kotlin handler method name.
 * /dashboard -> handleGetDashboard
 * /items/:id -> handleGetItemById
 * /invoices/:id/attachments -> handleGetInvoiceAttachments
 */
function pathToKotlinMethodName(string $path, string $method): string {
    $method = strtolower($method);
    $path = trim($path, '/');
    $parts = explode('/', $path);

    // Build method name from non-param parts
    $nameParts = [];
    $hasIdParam = false;
    foreach ($parts as $part) {
        if (preg_match('/^:(.+)$/', $part, $m) || preg_match('/^\{(.+)\}$/', $part, $m)) {
            if ($m[1] === 'id') {
                $hasIdParam = true;
            } else {
                // Named path param — incorporate into method name
                $paramName = str_replace('_', ' ', $m[1]);
                $paramName = ucwords($paramName);
                $paramName = str_replace(' ', '', $paramName);
                $nameParts[] = 'By' . $paramName;
            }
        } else {
            // Convert kebab-case to CamelCase
            $camel = str_replace(['-', '_'], ' ', $part);
            $camel = ucwords($camel);
            $camel = str_replace(' ', '', $camel);
            $nameParts[] = $camel;
        }
    }

    $baseName = implode('', $nameParts);

    // Prefix with HTTP method context
    $prefix = match ($method) {
        'get' => $hasIdParam ? 'handleGet' : 'handleGet',
        'post' => 'handleCreate',
        'put' => 'handleUpdate',
        'delete' => 'handleDelete',
        'patch' => 'handlePatch',
        default => 'handle' . ucfirst($method),
    };

    $fullName = $prefix . $baseName;

    // Add ById suffix for single-resource GET routes with :id
    if ($hasIdParam && $method === 'get' && !str_contains($baseName, 'ById')) {
        $fullName .= 'ById';
    }
    if ($hasIdParam && in_array($method, ['put', 'delete', 'patch']) && !str_contains($baseName, 'ById')) {
        $fullName .= 'ById';
    }

    return $fullName;
}

/**
 * Build the Kotlin regex pattern and extractor for a route path.
 * Returns ['pattern' => regex string, 'params' => [param names], 'isStatic' => bool]
 */
function buildKotlinPathMatcher(string $path): array {
    $params = [];
    $isStatic = true;

    // Replace :param with regex capture groups
    $regexPath = preg_replace_callback('/\:([a-zA-Z_]+)/', function ($m) use (&$params, &$isStatic) {
        $params[] = $m[1];
        $isStatic = false;
        return '([^/]+)';
    }, $path);

    return [
        'pattern' => $regexPath,
        'params' => $params,
        'isStatic' => $isStatic,
    ];
}

/**
 * Determine if a route handler returns a single result (object) vs list (array).
 * Heuristics: getById, create, update, delete → isSingle; list, search → not single.
 */
function isRouteSingleResponse(string $path, string $method): bool {
    $method = strtoupper($method);

    // POST, PUT, DELETE typically return single
    if (in_array($method, ['POST', 'PUT', 'DELETE'])) return true;

    // GET with :id parameter → single
    if (preg_match('/\:id\b/', $path)) return true;

    // Specific sub-paths that return single
    if (str_contains($path, '/stats') || str_contains($path, '/status') || str_contains($path, '/info')) return true;
    if (str_contains($path, '/daily-summary')) return true;
    if (str_contains($path, '/profile')) return true;

    // List routes
    return false;
}

/**
 * Generate the body of a Kotlin handler method based on the DB function call info.
 */
function generateKotlinHandlerBody(
    string $path,
    string $method,
    ?array $fnInfo,
    array $pathParams,
    bool $isSingle
): string {
    $method = strtoupper($method);
    $isBodyMethod = in_array($method, ['POST', 'PUT', 'PATCH']);

    if (!$fnInfo || $fnInfo['raw'] ?? false) {
        // No DB function found — generate a stub
        return "        // TODO: Manual implementation needed (no Database::fn() found)\n" .
               "        return errorResponse(\"Not implemented\")\n";
    }

    if ($fnInfo['json_passthrough'] ?? false) {
        // JSON pass-through call (e.g., create_vendor_invoice, create_customer_bill)
        $funcName = $fnInfo['function'];
        return "        val result = db.callFunction(\"$funcName\", body ?: \"{}\")\n" .
               "        return try {\n" .
               "            val inner = JSONObject(result)\n" .
               "            JSONObject().apply {\n" .
               "                put(\"success\", inner.optBoolean(\"success\", true))\n" .
               "                put(\"data\", inner)\n" .
               "                put(\"message\", inner.optString(\"message\", \"OK\"))\n" .
               "            }.toString()\n" .
               "        } catch (e: Exception) {\n" .
               "            errorResponse(\"$funcName returned unexpected result\")\n" .
               "        }\n";
    }

    $funcName = $fnInfo['function'];
    $params = $fnInfo['params'];
    $paramCount = count($params);

    // Build the positional parameter placeholders: $1, $2, ...
    $placeholders = [];
    for ($i = 1; $i <= $paramCount; $i++) {
        $placeholders[] = "\\\$" . $i;
    }
    $placeholderStr = implode(', ', $placeholders);
    $sqlCall = "SELECT * FROM $funcName($placeholderStr)";

    // Build parameter values
    $paramValues = [];
    foreach ($params as $param) {
        // Strip p_ prefix to get the field name
        $fieldName = preg_replace('/^p_/', '', $param);

        // Check if this param comes from a path parameter
        if (in_array($fieldName, $pathParams) || $fieldName === 'id' && in_array('id', $pathParams)) {
            $paramValues[] = $fieldName;
        } elseif ($isBodyMethod) {
            // From request body JSON
            $paramValues[] = "json.optString(\"$fieldName\", null)";
        } else {
            // From query parameters
            $paramValues[] = "query[\"$fieldName\"]";
        }
    }

    $lines = [];

    if ($isBodyMethod && !empty($params)) {
        // Check if we need body parsing (any non-path params)
        $hasBodyParams = false;
        foreach ($params as $param) {
            $fieldName = preg_replace('/^p_/', '', $param);
            if (!in_array($fieldName, $pathParams) && $fieldName !== 'id') {
                $hasBodyParams = true;
                break;
            }
        }
        if ($hasBodyParams) {
            $lines[] = "        val json = JSONObject(body ?: \"{}\")";
        }
    }

    // Build db.execute call
    $indent = "            ";
    $lines[] = "        val result = db.execute(";
    $lines[] = "$indent\"$sqlCall\",";

    foreach ($paramValues as $i => $val) {
        $comma = ($i < count($paramValues) - 1) ? ',' : '';
        $lines[] = "$indent$val$comma";
    }

    $lines[] = "        )";
    $singleStr = $isSingle ? 'true' : 'false';
    $lines[] = "        return toApiResponse(result, isSingle = $singleStr)";

    return implode("\n", $lines) . "\n";
}

/**
 * Main Kotlin generation function.
 * Generates OfflineApiHandler.kt and ApiTypes.kt from routes.
 */
function emitKotlin(array $routes, string $outputDir): void {
    // Create output directory
    if (!is_dir($outputDir)) {
        if (!mkdir($outputDir, 0755, true)) {
            echo "Error: Failed to create output directory: $outputDir\n";
            exit(1);
        }
    }

    $timestamp = date('Y-m-d H:i:s');
    $skippedRoutes = [];
    $processedRoutes = [];
    $stubRoutes = [];

    // Categorize routes
    foreach ($routes as $route) {
        $path = $route['path'];
        $handler = $route['handler'];

        // Skip auth routes
        if (str_starts_with($path, '/auth')) {
            $skippedRoutes[] = $route + ['reason' => 'auth route'];
            continue;
        }
        // Skip health/home routes
        if ($path === '/' || $path === '/health') {
            $skippedRoutes[] = $route + ['reason' => 'health/home route'];
            continue;
        }
        // Skip database schema routes (admin/dev only)
        if (str_starts_with($path, '/database/schema')) {
            $skippedRoutes[] = $route + ['reason' => 'database schema route'];
            continue;
        }
        // Skip admin routes
        if (str_starts_with($path, '/admin')) {
            $skippedRoutes[] = $route + ['reason' => 'admin route'];
            continue;
        }
        // Skip db-health routes
        if (str_starts_with($path, '/db-health')) {
            $skippedRoutes[] = $route + ['reason' => 'db-health route'];
            continue;
        }
        // Skip webhook routes
        if (str_starts_with($path, '/webhook')) {
            $skippedRoutes[] = $route + ['reason' => 'webhook route'];
            continue;
        }
        // Skip user legacy auth endpoints
        if (str_starts_with($path, '/user/') && in_array($path, ['/user/access', '/user/refresh-access', '/user/signout', '/user/change-password', '/user/verify-email-code'])) {
            $skippedRoutes[] = $route + ['reason' => 'legacy auth route'];
            continue;
        }
        // Skip files/authorize
        if ($path === '/files/authorize') {
            $skippedRoutes[] = $route + ['reason' => 'internal files route'];
            continue;
        }
        // Skip auth-test
        if ($path === '/auth-test') {
            $skippedRoutes[] = $route + ['reason' => 'auth test route'];
            continue;
        }

        // Try to extract DB function info
        $fnInfo = extractFunctionCalls($handler);

        $processedRoutes[] = $route + ['fnInfo' => $fnInfo];
    }

    // --- Generate dispatch entries and handler methods ---
    $dispatchEntries = [];
    $handlerMethods = [];
    $methodNames = []; // Track for deduplication

    foreach ($processedRoutes as $route) {
        $path = $route['path'];
        $httpMethod = strtoupper($route['method']);
        $fnInfo = $route['fnInfo'];

        $methodName = pathToKotlinMethodName($path, $route['method']);

        // Deduplicate method names
        if (isset($methodNames[$methodName])) {
            $methodNames[$methodName]++;
            $methodName .= $methodNames[$methodName];
        } else {
            $methodNames[$methodName] = 1;
        }

        $pathMatcher = buildKotlinPathMatcher($path);
        $pathParams = $pathMatcher['params'];
        $isSingle = isRouteSingleResponse($path, $route['method']);

        // Build dispatch entry
        if ($pathMatcher['isStatic']) {
            $dispatchEntries[] = [
                'method' => $httpMethod,
                'path' => $path,
                'isStatic' => true,
                'methodName' => $methodName,
                'pathParams' => [],
                'httpMethod' => $httpMethod,
            ];
        } else {
            $dispatchEntries[] = [
                'method' => $httpMethod,
                'path' => $path,
                'isStatic' => false,
                'pattern' => $pathMatcher['pattern'],
                'params' => $pathMatcher['params'],
                'methodName' => $methodName,
                'pathParams' => $pathMatcher['params'],
                'httpMethod' => $httpMethod,
            ];
        }

        // Build handler method
        $isBodyMethod = in_array($httpMethod, ['POST', 'PUT', 'PATCH']);

        // Build method signature
        $sigParams = ['db: PgDatabase'];
        if (!empty($pathParams)) {
            foreach ($pathParams as $pp) {
                $sigParams[] = "$pp: String";
            }
        }
        if (!$pathMatcher['isStatic'] || !$isBodyMethod) {
            // GET routes always get query params
            if (!$isBodyMethod) {
                $sigParams[] = 'query: Map<String, String>';
            }
        }
        if ($isBodyMethod) {
            $sigParams[] = 'body: String?';
        }

        $sigStr = implode(', ', $sigParams);
        $body = generateKotlinHandlerBody($path, $route['method'], $fnInfo, $pathParams, $isSingle);

        $handlerMethods[] = "    private fun $methodName($sigStr): String {\n" .
                            "        synchronized(dbLock) {\n" .
                            $body .
                            "        }\n" .
                            "    }\n";
    }

    // --- Build dispatch() method body ---
    $dispatchCode = '';
    foreach ($dispatchEntries as $entry) {
        $httpMethod = $entry['httpMethod'];
        $methodName = $entry['methodName'];

        if ($entry['isStatic']) {
            $path = $entry['path'];
            $isBodyMethod = in_array($httpMethod, ['POST', 'PUT', 'PATCH']);
            $callArgs = 'db';
            if (!$isBodyMethod) {
                $callArgs .= ', query';
            }
            if ($isBodyMethod) {
                $callArgs .= ', body';
            }
            $dispatchCode .= "            method == \"$httpMethod\" && path == \"$path\" -> $methodName($callArgs)\n";
        } else {
            $pattern = $entry['pattern'];
            $params = $entry['params'];
            $isBodyMethod = in_array($httpMethod, ['POST', 'PUT', 'PATCH']);

            // Build regex match block
            $dispatchCode .= "            method == \"$httpMethod\" && Regex(\"$pattern\").matchEntire(path) != null -> {\n";
            $dispatchCode .= "                val m = Regex(\"$pattern\").matchEntire(path)!!\n";

            $callArgs = 'db';
            foreach ($params as $i => $param) {
                $groupIdx = $i + 1;
                $dispatchCode .= "                val $param = m.groupValues[$groupIdx]\n";
                $callArgs .= ", $param";
            }
            if (!$isBodyMethod) {
                $callArgs .= ', query';
            }
            if ($isBodyMethod) {
                $callArgs .= ', body';
            }
            $dispatchCode .= "                $methodName($callArgs)\n";
            $dispatchCode .= "            }\n";
        }
    }

    $routeCount = count($processedRoutes);
    $handlersCode = implode("\n", $handlerMethods);

    // --- Build the full OfflineApiHandler.kt ---
    $kotlin = <<<KOTLIN
package com.progalaxyelabs.medstoreapp.generated

import com.pgandroid.PgDatabase
import com.pgandroid.PgResult
import org.json.JSONArray
import org.json.JSONObject

/**
 * AUTO-GENERATED by: php stone generate client --language=kotlin
 * Source: medstoreapp-platform routes.php
 * Generated: $timestamp
 * Routes: $routeCount
 *
 * Do not edit manually — regenerate when routes change.
 */
class OfflineApiHandler(private val db: PgDatabase) {

    private val dbLock = Any()

    fun handleBridge(method: String, path: String, queryParams: Map<String, String>, body: String?): String {
        return try {
            dispatch(method, path, queryParams, body)
        } catch (e: Exception) {
            errorResponse(e.message ?: "Internal error")
        }
    }

    private fun dispatch(method: String, path: String, query: Map<String, String>, body: String?): String {
        return when {
$dispatchCode
            else -> errorResponse("Not found: \$method \$path")
        }
    }

    // =========================================================================
    // Handler Methods
    // =========================================================================

$handlersCode

    // =========================================================================
    // Response Helpers
    // =========================================================================

    private fun toApiResponse(result: PgResult, isSingle: Boolean = false): String {
        val dataArray = JSONArray()
        for (row in result.rows) {
            val obj = JSONObject()
            for ((key, value) in row) {
                obj.put(key, value ?: JSONObject.NULL)
            }
            dataArray.put(obj)
        }
        val data: Any = if (isSingle && dataArray.length() == 1) dataArray.get(0) else dataArray
        return JSONObject().apply {
            put("success", true)
            put("data", data)
            put("message", "OK")
        }.toString()
    }

    private fun errorResponse(message: String): String =
        JSONObject().apply {
            put("success", false)
            put("data", JSONObject.NULL)
            put("message", message)
        }.toString()
}

KOTLIN;

    // Write the file
    $outputFile = $outputDir . DIRECTORY_SEPARATOR . 'OfflineApiHandler.kt';
    file_put_contents($outputFile, $kotlin);

    echo "✓ Generated Kotlin offline handler: $outputFile\n";
    echo "  Routes processed: $routeCount\n";
    echo "  Routes skipped: " . count($skippedRoutes) . "\n";

    // Print skipped routes summary
    if (!empty($skippedRoutes)) {
        echo "\n  Skipped routes:\n";
        $reasons = [];
        foreach ($skippedRoutes as $sr) {
            $reasons[$sr['reason']] = ($reasons[$sr['reason']] ?? 0) + 1;
        }
        foreach ($reasons as $reason => $count) {
            echo "    - $reason: $count\n";
        }
    }

    // Print stub/manual routes
    $manualCount = 0;
    foreach ($processedRoutes as $pr) {
        if (!$pr['fnInfo'] || ($pr['fnInfo']['raw'] ?? false)) {
            $manualCount++;
            echo "  ⚠ Manual implementation needed: {$pr['method']} {$pr['path']}\n";
        }
    }
    if ($manualCount > 0) {
        echo "  $manualCount route(s) need manual implementation\n";
    }
}

// Main execution
echo "Scanning routes...\n";
$routes = loadRoutes();
echo "Found " . count($routes) . " route(s)\n";

// Branch on language
if ($language === 'kotlin') {
    echo "Generating Kotlin offline handler...\n";
    emitKotlin($routes, $outputDir);
    exit(0);
}

echo "Generating TypeScript client...\n";
$clientCode = generateClient($routes);

// Create output directory structure
$srcDir = $outputDir . DIRECTORY_SEPARATOR . 'src';
if (!is_dir($srcDir)) {
    if (!mkdir($srcDir, 0755, true)) {
        echo "Error: Failed to create src directory: $srcDir\n";
        exit(1);
    }
}

// Generate package.json (v2.0 - with peerDependencies)
$packageJson = json_encode([
    'name' => $packageName,
    'version' => $apiVersion,
    'description' => 'Auto-generated TypeScript API client for StoneScriptPHP backend',
    'main' => 'dist/index.js',
    'types' => 'dist/index.d.ts',
    'scripts' => [
        'build' => 'tsc',
        'watch' => 'tsc --watch'
    ],
    'keywords' => ['api', 'client', 'typescript', 'stonescript'],
    'author' => '',
    'license' => 'MIT',
    'peerDependencies' => [
        '@progalaxyelabs/ngx-stonescriptphp-client' => '^1.6.0'
    ],
    'devDependencies' => [
        'typescript' => '^5.8.0',
        '@progalaxyelabs/ngx-stonescriptphp-client' => '^1.6.0'
    ],
    'files' => [
        'dist',
        'src',
        'README.md'
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

// Generate tsconfig.json
$tsConfig = json_encode([
    'compilerOptions' => [
        'target' => 'ES2020',
        'module' => 'ES2020',
        'lib' => ['ES2020', 'DOM'],
        'declaration' => true,
        'outDir' => './dist',
        'rootDir' => './src',
        'strict' => true,
        'esModuleInterop' => true,
        'skipLibCheck' => true,
        'forceConsistentCasingInFileNames' => true,
        'moduleResolution' => 'node'
    ],
    'include' => ['src/**/*'],
    'exclude' => ['node_modules', 'dist']
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

// Generate README.md (v2.0 - with Angular DI setup)
$readme = <<<MD
# API Client

Auto-generated TypeScript API client for StoneScriptPHP backend.

**DO NOT EDIT MANUALLY** - Regenerate using: `php stone generate client`

## Installation

### In Angular Project

```bash
npm install file:./src/api-client
```

### Setup in app.config.ts

```typescript
import { ApiClient } from '$packageName';
import { ApiConnectionService } from '@progalaxyelabs/ngx-stonescriptphp-client';

export const appConfig: ApplicationConfig = {
  providers: [
    // ... other providers
    {
      provide: ApiClient,
      useFactory: (connection: ApiConnectionService) => new ApiClient(connection),
      deps: [ApiConnectionService]
    }
  ]
};
```

## Usage

### In Angular Services

```typescript
import { Injectable } from '@angular/core';
import { ApiClient } from '$packageName';

@Injectable({ providedIn: 'root' })
export class ProjectService {
  constructor(private api: ApiClient) {}

  async createProject(name: string, description: string) {
    try {
      const project = await this.api.projects.create({
        name,
        description
      });

      console.log('Created:', project);
      return project;
    } catch (error) {
      console.error('Failed to create project:', error.message);
      throw error;
    }
  }

  async listProjects() {
    const response = await this.api.projects.list();
    return response.projects;
  }
}
```

### In Components

```typescript
export class ProjectListComponent implements OnInit {
  projects: Project[] = [];

  constructor(private projectService: ProjectService) {}

  async ngOnInit() {
    this.projects = await this.projectService.listProjects();
  }

  async createNew() {
    await this.projectService.createProject('New Project', 'Description');
    this.projects = await this.projectService.listProjects(); // Refresh
  }
}
```

## Regenerating

When backend routes change:

```bash
cd /path/to/backend
php stone generate client --output=../portal/src/api-client
```

Frontend will automatically see new types (no reinstall needed with \`file:\` protocol).

## Type Safety

All request and response types are generated from PHP DTOs:

```typescript
// TypeScript knows the exact shape
const request: CreateProjectRequest = {
  name: 'My Project',        // ✅ string
  description: 'Desc',       // ✅ string
  start_date: '2026-01-14'   // ✅ string | null
  // tenant_id is optional
};

const response: CreateProjectResponse = await api.projects.create(request);
// response.project_id is number
// response.name is string
// response.created_at is string
```

## Error Handling

Errors are automatically wrapped in standard Error objects:

```typescript
try {
  await api.projects.create(data);
} catch (error) {
  console.error(error.message); // User-friendly message from API
}
```

MD;

// Generate .gitignore
$gitignore = <<<IGNORE
node_modules/
dist/
*.log
.DS_Store
IGNORE;

// Write all files
file_put_contents($outputDir . DIRECTORY_SEPARATOR . 'package.json', $packageJson);
file_put_contents($outputDir . DIRECTORY_SEPARATOR . 'tsconfig.json', $tsConfig);
file_put_contents($outputDir . DIRECTORY_SEPARATOR . 'README.md', $readme);
file_put_contents($outputDir . DIRECTORY_SEPARATOR . '.gitignore', $gitignore);
file_put_contents($srcDir . DIRECTORY_SEPARATOR . 'index.ts', $clientCode);

echo "✓ Generated npm package structure in: $outputDir\n";
echo "  ├── package.json\n";
echo "  ├── tsconfig.json\n";
echo "  ├── README.md\n";
echo "  ├── .gitignore\n";
echo "  └── src/index.ts\n";
echo "\nInstall in your Angular project:\n";
echo "  cd /path/to/angular-project\n";
echo "  npm install file:" . str_replace(ROOT_PATH, '../', $outputDir) . "\n";
echo "\nThen import in your Angular code:\n";
echo "  import { ApiClient } from '$packageName';\n";
echo "  const api = new ApiClient(connection); // Pass ApiConnectionService instance\n";
