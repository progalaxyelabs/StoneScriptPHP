<?php

// php generate route method=post url=/register class=RegisterForClass

if ($argc !== 2) {
    echo 'usage example: php generate-route.php route-name' . PHP_EOL;
    die(0);
}

// $method = $argv[1];
$classname = implode(array_map(fn($str) => ucfirst($str), explode('-', $argv[1]))) . 'Route';
$route_filename = $classname . '.php';
$route_filepath = __DIR__ . DIRECTORY_SEPARATOR . 'Routes' . DIRECTORY_SEPARATOR. $route_filename;
$route_file_content = <<<EOD
<?php

namespace Routes;

use Framework\IRouteHandler;
use Models\ApiResponse;

class $classname implements IRouteHandler
{
    public function validation_rules(): array
    {
        return [];
    }

    public function process(): ApiResponse
    {
        // return new ApiResponse('ok', '', []);
        throw new Exception('Not Implemented');
    }

}

EOD;

file_put_contents($route_filepath, $route_file_content);

echo 'Created file ' . $route_filename . PHP_EOL;