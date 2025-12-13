<?php
// Framework/cli/cli-server-router.php
// Router for PHP built-in server

// Define ROOT_PATH (go up two levels from Framework/cli)
if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__));

// Set JSON response headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle OPTIONS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Parse request
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode(file_get_contents('php://input'), true);

// Route handlers
try {
    $response = match($uri) {
        '/generate/route' => handleGenerateRoute($body),
        '/generate/model' => handleGenerateModel($body),
        '/generate/migration' => handleGenerateMigration($body),
        '/generate/env' => handleGenerateEnv($body),
        '/health' => ['status' => 'ok', 'version' => '1.0.0'],
        default => throw new Exception("Endpoint not found: $uri", 404)
    };

    echo json_encode([
        'success' => true,
        'data' => $response
    ]);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

// Handler functions
function handleGenerateRoute(array $body): array {
    $path = $body['path'] ?? null;
    $handler = $body['handler'] ?? null;
    $requestDTO = $body['RequestDTO'] ?? [];
    $responseDTO = $body['ResponseDTOData'] ?? [];

    if (!$path) {
        throw new Exception("Missing required field: path", 400);
    }

    // Convert path to route name: /products -> Products
    $routeName = convertPathToRouteName($path);

    // Generate route file
    $routeFile = generateRouteFile($routeName, $path, $handler, $requestDTO, $responseDTO);

    // Generate DTO files if provided
    $files = ['route' => $routeFile];

    if (!empty($requestDTO)) {
        $files['requestDTO'] = generateDTOFile($routeName . 'Request', $requestDTO);
    }

    if (!empty($responseDTO)) {
        $files['responseDTO'] = generateDTOFile($routeName . 'Response', $responseDTO);
    }

    return [
        'message' => 'Route generated successfully',
        'files' => $files,
        'routeName' => $routeName,
        'path' => $path
    ];
}

function handleGenerateModel(array $body): array {
    $functionFile = $body['functionFile'] ?? null;

    if (!$functionFile) {
        throw new Exception("Missing required field: functionFile", 400);
    }

    // Execute existing generate model command
    $output = [];
    $return = 0;
    exec("php " . ROOT_PATH . "/stone generate model " . escapeshellarg($functionFile), $output, $return);

    if ($return !== 0) {
        throw new Exception("Model generation failed: " . implode("\n", $output), 500);
    }

    return [
        'message' => 'Model generated successfully',
        'output' => implode("\n", $output)
    ];
}

function handleGenerateMigration(array $body): array {
    // Similar to model generation
    return ['message' => 'Migration generated'];
}

function handleGenerateEnv(array $body): array {
    $force = $body['force'] ?? false;
    $example = $body['example'] ?? false;

    $cmd = "php " . ROOT_PATH . "/stone generate env";
    if ($force) $cmd .= " --force";
    if ($example) $cmd .= " --example";

    exec($cmd, $output, $return);

    return [
        'message' => '.env file generated',
        'output' => implode("\n", $output)
    ];
}

function convertPathToRouteName(string $path): string {
    // /products -> Products
    // /update-trophies -> UpdateTrophies
    $name = trim($path, '/');
    $name = str_replace(['-', '_'], ' ', $name);
    $name = ucwords($name);
    $name = str_replace(' ', '', $name);
    return $name;
}

function generateRouteFile(string $name, string $path, ?string $handler, array $requestDTO, array $responseDTO): string {
    $requestDTOClass = !empty($requestDTO) ? $name . 'Request' : null;
    $responseDTOClass = !empty($responseDTO) ? $name . 'Response' : null;

    $routeFile = ROOT_PATH . "/src/App/Routes/{$name}Route.php";

    $content = "<?php\n\nnamespace App\\Routes;\n\n";
    $content .= "use StoneScriptPHP\\IRouteHandler;\n";
    $content .= "use StoneScriptPHP\\ApiResponse;\n";

    if ($requestDTOClass) {
        $content .= "use App\\DTO\\{$requestDTOClass};\n";
    }
    if ($responseDTOClass) {
        $content .= "use App\\DTO\\{$responseDTOClass};\n";
    }

    $content .= "\nclass {$name}Route implements IRouteHandler {\n";
    $content .= "    public function process(\$request): ApiResponse {\n";

    if ($requestDTOClass) {
        $content .= "        \$data = {$requestDTOClass}::fromRequest(\$request);\n\n";
    }

    $content .= "        // TODO: Implement your logic here\n\n";

    if ($responseDTOClass) {
        $content .= "        \$response = new {$responseDTOClass}();\n";
        $content .= "        return new ApiResponse('ok', 'Success', \$response);\n";
    } else {
        $content .= "        return new ApiResponse('ok', 'Success');\n";
    }

    $content .= "    }\n";
    $content .= "}\n";

    file_put_contents($routeFile, $content);

    return $routeFile;
}

function generateDTOFile(string $className, array $fields): string {
    $dtoFile = ROOT_PATH . "/src/App/DTO/{$className}.php";

    if (!is_dir(ROOT_PATH . "/src/App/DTO")) {
        mkdir(ROOT_PATH . "/src/App/DTO", 0755, true);
    }

    $content = "<?php\n\nnamespace App\\DTO;\n\n";
    $content .= "class {$className} {\n";

    foreach ($fields as $name => $type) {
        $phpType = mapJsonTypeToPhp($type);
        $content .= "    public {$phpType} \${$name};\n";
    }

    $content .= "\n    public static function fromRequest(\$request): self {\n";
    $content .= "        \$dto = new self();\n";
    foreach ($fields as $name => $type) {
        $content .= "        \$dto->{$name} = \$request['{$name}'] ?? null;\n";
    }
    $content .= "        return \$dto;\n";
    $content .= "    }\n";
    $content .= "}\n";

    file_put_contents($dtoFile, $content);

    return $dtoFile;
}

function mapJsonTypeToPhp(string $type): string {
    return match(strtolower($type)) {
        'string' => 'string',
        'int', 'integer' => 'int',
        'float', 'double' => 'float',
        'bool', 'boolean' => 'bool',
        'array' => 'array',
        default => 'mixed'
    };
}
