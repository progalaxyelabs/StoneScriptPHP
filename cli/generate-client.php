<?php

/**
 * TypeScript Client Generator
 *
 * Generates a TypeScript client from PHP routes with full type safety.
 *
 * Usage:
 *   php generate client [--output=<path>]
 *
 * Example:
 *   php generate client
 *   php generate client --output=frontend/src/api/client.ts
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

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--output=')) {
        $outputDir = substr($arg, 9);
    }
    if (str_starts_with($arg, '--name=')) {
        $packageName = substr($arg, 7);
    }
    if (in_array($arg, ['--help', '-h', 'help'])) {
        echo "TypeScript Client Generator\n";
        echo "============================\n\n";
        echo "Usage: php generate client [options]\n\n";
        echo "Options:\n";
        echo "  --output=<dir>   Output directory (default: client)\n";
        echo "  --name=<name>    Package name (default: @stonescript/api-client)\n\n";
        echo "Example:\n";
        echo "  php generate client\n";
        echo "  php generate client --output=packages/api-client\n";
        echo "  php generate client --name=@myapp/api\n\n";
        echo "Output structure:\n";
        echo "  client/\n";
        echo "  ├── package.json\n";
        echo "  ├── tsconfig.json\n";
        echo "  ├── README.md\n";
        echo "  └── src/\n";
        echo "      └── index.ts\n\n";
        echo "Install in Angular project:\n";
        echo "  npm install file:../client\n";
        exit(0);
    }
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

    // Find the contract interface (not IRouteHandler)
    foreach ($interfaces as $interface) {
        if ($interface->getName() !== 'Framework\\IRouteHandler') {
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
        'request' => $requestType->getName(),
        'response' => $responseType->getName()
    ];
}

/**
 * PHP type to TypeScript type mapping
 */
function phpTypeToTs(string $phpType): string {
    return match ($phpType) {
        'int', 'integer', 'float', 'double' => 'number',
        'bool', 'boolean' => 'boolean',
        'string' => 'string',
        'array' => 'any[]',
        'mixed' => 'any',
        default => $phpType // Keep class names as-is for now
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
        $typeName = $type ? $type->getName() : 'any';
        $isNullable = $type && $type->allowsNull();

        // Check if this is a class type (nested DTO)
        $isClassType = $type && !$type->isBuiltin() && class_exists($typeName);

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

    $shortName = substr($className, strrpos($className, '\\') + 1);
    $output = "export interface $shortName {\n";

    $nestedInterfaces = '';

    foreach ($properties as $prop) {
        $tsType = phpTypeToTs($prop['type']);

        // If this is a nested class, generate its interface too
        if ($prop['isClass']) {
            $nestedInterfaces .= generateTsInterface($prop['type'], $processedClasses);
            $tsType = substr($prop['type'], strrpos($prop['type'], '\\') + 1);
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
    return $parts[0] ?? 'default';
}

/**
 * Convert route path to method name within a resource
 * /projects/create + POST -> create
 * /projects/{id} + GET -> get
 * /projects/{id} + PUT -> update
 * /projects/{id} + DELETE -> delete
 * /projects -> GET -> list
 */
function pathToMethodName(string $path, string $method): string {
    $path = trim($path, '/');
    $parts = explode('/', $path);

    // Remove the first segment (resource name)
    array_shift($parts);

    // Remove parameter parts
    $parts = array_filter($parts, fn($part) => !preg_match('/^\{.+\}$/', $part));

    // If no parts left, use method-based name
    if (empty($parts)) {
        return match(strtoupper($method)) {
            'GET' => 'list',
            'POST' => 'create',
            'PUT' => 'update',
            'DELETE' => 'delete',
            default => strtolower($method)
        };
    }

    // Convert remaining parts to camelCase
    $methodName = '';
    foreach ($parts as $i => $part) {
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
    preg_match_all('/\{([^}]+)\}/', $path, $matches);
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
        $requestTypeName = substr($types['request'], strrpos($types['request'], '\\') + 1);
        $responseTypeName = substr($types['response'], strrpos($types['response'], '\\') + 1);

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

        // Group by resource
        if (!isset($resourceMethods[$resourceName])) {
            $resourceMethods[$resourceName] = [];
        }
        $resourceMethods[$resourceName][] = $methodCode;
    }

    // Generate resource objects
    $resourcesCode = '';
    foreach ($resourceMethods as $resourceName => $methods) {
        $methodsStr = implode("\n\n", $methods);
        $resourcesCode .= <<<TS

  /**
   * $resourceName endpoints
   */
  $resourceName = {
$methodsStr
  };

TS;
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

$interfaces
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
            $pathTemplate = str_replace("{{$param}}", "\${$param}", $pathTemplate);
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

// Main execution
echo "Scanning routes...\n";
$routes = loadRoutes();
echo "Found " . count($routes) . " route(s)\n";

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
        '@progalaxyelabs/ngx-stonescriptphp-client' => '^2.0.0'
    ],
    'devDependencies' => [
        'typescript' => '^5.8.0',
        '@progalaxyelabs/ngx-stonescriptphp-client' => '^2.0.0'
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
