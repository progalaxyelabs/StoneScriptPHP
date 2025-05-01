<?php

use Framework\Router;

require '../Framework/bootstrap.php';
require '../vendor/autoload.php';

enum RequestMethod : string {
    case get = 'GET';
    case post = 'POST';
    case options = 'OPTIONS';
    case put = 'PUT';
    case patch = 'PATCH';
    case delete = 'DELETE';
    case head = 'HEAD';
    case other = '';
}

log_debug("--------------------------------------------------");

$routing_start_time = microtime(true);

ob_start();

$handle_route_start_time = microtime(true);
$router = new Router();
$data = $router->process_route();
$handle_route_end_time = microtime(true);
$unhandled_echos = ob_get_clean();

$body = [];
$http_response_code = http_response_code();
$body['code'] = http_response_code();
$body['echos'] = $unhandled_echos;

if ($http_response_code === 200) {
    $body['data'] = $data;
} else {
    $body['errors'] = $data;
}

if (DEBUG_MODE) {
    // $body['log'] = Logger::get_instance()->get_all();
    $body['files'] = get_included_files();
    $process_time = (microtime(true) - INDEX_START_TIME);
    $route_parsing_time = isset($timings['route_parsing_complete']) ? ($timings['route_parsing_complete'] - $handle_route_start_time) : 0;
    $db_initialization_time = isset($timings['db_initialization_complete']) ? ($timings['db_initialization_complete'] - $timings['route_parsing_complete']) : 0;
    $body['timings'] = [
        'request_time' => $_SERVER['REQUEST_TIME_FLOAT'],
        'index_ start_time' => INDEX_START_TIME,
        'index_before_json_encode' => $process_time,
        'handover' => number_format(((INDEX_START_TIME - $_SERVER['REQUEST_TIME_FLOAT']) * 1000), 2) . "ms",
        'handle_route' => number_format(($handle_route_end_time - $handle_route_start_time) * 1000, 2) . "ms",
        'route_parsing' => number_format($route_parsing_time * 1000, 2) . "ms",
        'db_initialization' => number_format($db_initialization_time * 1000, 2) . "ms",
        'route_process' => number_format(($handle_route_end_time - ($timings['db_initialization_complete'] ?? $handle_route_start_time)) * 1000, 2) . "ms",
        'full_process' => number_format(($process_time * 1000), 2) . "ms"
    ];
}


$response_body = [];
if ($http_response_code === 200) {
    $response_body = $body['data'];
} else {
    $response_body = $body['errors'];
}

header('Content-Type: application/json');
if (DEBUG_MODE === 1) {
    echo json_encode($body);
} else {
    echo json_encode($response_body);
}
