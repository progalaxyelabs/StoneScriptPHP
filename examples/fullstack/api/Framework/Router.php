<?php

namespace Framework;

use Error;
use Exception;
use ReflectionClass;
use ReflectionProperty;
use RequestMethod;


abstract class RequestParser
{
    public array $routes = [];
    public array $allowed_origins = [];
    public string $error = '';
    protected array $route_params = [];  // Parameters extracted from URL pattern


    protected string $request_path;

    public function __construct()
    {
        $this->routes = require CONFIG_PATH . 'routes.php';
        $this->allowed_origins =  require CONFIG_PATH . 'allowed_origins.php';

        // Compile route groups for each HTTP method
        foreach ($this->routes as $method => &$methodRoutes) {
            if (is_array($methodRoutes)) {
                $methodRoutes = RouteCompiler::compile($methodRoutes);
            }
        }
        unset($methodRoutes); // Break reference

        $this->request_path = parse_url($_SERVER['REQUEST_URI'])['path'] ?? '';
        log_debug("request path is [$this->request_path]");
    }

    protected function add_cors_headers(): void
    {
        $http_origin = strtolower($_SERVER['HTTP_ORIGIN'] ?? '');

        log_debug("origin header is [$http_origin]");

        if (in_array($http_origin, $this->allowed_origins)) {
            header("Access-Control-Allow-Origin: $http_origin");
        }

        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Headers: Alt-Used, Content-Type, Authorization');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 900');
        header('Vary: Origin');
    }

    protected function _process(): ApiResponse
    {
        global $timings;

        $class_name = $this->identify_route();
        if (!$class_name) {
            return e404();
        }

        $instance = new $class_name();
        if (!($instance instanceof IRouteHandler)) {
            return e404('Not Implemented');
        }

        $input = $this->extract_input();
        if ($this->error) {
            // Return appropriate error based on error type
            if ($this->error === 'unsupported_media_type') {
                return e415('Content-Type must be application/json');
            }
            return e400($this->error);
        }

        // Merge route parameters (from URL) with input data (from body/query)
        // Route params take precedence to prevent injection
        $all_input = array_merge($input, $this->route_params);

        $reflect = new ReflectionClass($instance);
        $properties = $reflect->getProperties(ReflectionProperty::IS_PUBLIC);


        $missing_properties = [];
        foreach ($properties as $property) {
            $property_name = $property->getName();
            if (array_key_exists($property_name, $all_input)) {
                $instance->$property_name = $all_input[$property_name];
            } else {
                log_debug(" expected [$property_name] not found in request");
                $missing_properties[] = $property_name;
            }
        }

        $missing_properties_count = count($missing_properties);
        if ($missing_properties_count > 0) {
            $message = "Error: $missing_properties_count missing properties";
            log_debug($message);
            return e400($message);
        }

        $timings['route_parsing_complete'] = microtime(true);

        $response = [];
        try {
            $db_init_start = microtime(true);
            // $db = Database::get_instance();
            $db_init_time = microtime(true) - $db_init_start;
            $timings['db_initialization_complete'] = microtime(true);
            log_debug('db initialization took ' . ($db_init_time * 1000) . 'ms');
            $response = $instance->process();
            if (is_null($response)) {
                $response = new ApiResponse('not ok', 'null');
                log_debug('Null response from route handler');
            }
        } catch (Exception $exception) {
            log_debug('Exception: ' . $exception->getMessage());
            $response = e500(DEBUG_MODE ? $exception->getMessage() : 'Internal server error');
        } catch (Error $error) {
            log_debug('Error: ' . $error->getMessage());
            $response = e500(DEBUG_MODE ? $error->getMessage() : 'Internal server error');
        }

        $this->add_cors_headers();

        if (!($response instanceof ApiResponse)) {
            if(DEBUG_MODE) {
                return res_not_ok(var_export($response, true));
            } else {
                return res_not_ok('Expected ApiResponse, got array??');
            }
            
        }

        return $response;
    }

    public abstract function extract_input(): array;
    public abstract function identify_route(): string;
    public abstract function respond(): ApiResponse;
}

class GetRequestParser extends RequestParser
{
    public function extract_input(): array
    {
        return $_GET ?? [];
    }

    public function identify_route(): string
    {
        $match = RouteMatcher::match($this->routes['GET'] ?? [], $this->request_path);

        // Store extracted route parameters for use in _process()
        $this->route_params = $match['params'];

        return $match['handler'] ?? '';
    }

    public function respond(): ApiResponse
    {
        return parent::_process();
    }
}

class PostRequestParser extends RequestParser
{
    public function extract_input(): array
    {
        $content_type = $_SERVER['CONTENT_TYPE'] ?? '';

        // Extract media type (before semicolon) to handle charset parameters
        // e.g., "application/json; charset=utf-8" -> "application/json"
        $media_type = explode(';', $content_type)[0];
        $media_type = trim($media_type);

        if ($media_type !== 'application/json') {
            $this->error = 'unsupported_media_type';
            log_debug("Unsupported Content-Type: {$content_type}. Expected application/json");
            return [];
        }

        $input = json_decode(file_get_contents('php://input'), true);

        if ($input === null) {
            $this->error = 'input not valid json';
            log_debug($this->error);
            return [];
        }

        return $input;
    }

    public function identify_route(): string
    {
        $match = RouteMatcher::match($this->routes['POST'] ?? [], $this->request_path);

        // Store extracted route parameters for use in _process()
        $this->route_params = $match['params'];

        return $match['handler'] ?? '';
    }

    public function respond(): ApiResponse
    {
        return parent::_process();
    }
}

class OptionsRequestParser extends RequestParser
{
    public function extract_input(): array
    {
        return [];
    }

    public function identify_route(): string
    {
        return '';
    }

    public function respond(): ApiResponse
    {
        $this->add_cors_headers();
        return res_ok([]);
    }
}

class NullRequestParser extends RequestParser
{
    public function extract_input(): array
    {
        return [];
    }

    public function identify_route(): string
    {
        return '';
    }

    public function respond(): ApiResponse
    {
        return e404();
    }
}

class Router
{



    // private function respond_cors(array $allowed_origins): ApiResponse
    // {
    //     $this->add_cors_headers();
    //     return res_ok([]);
    // }

    // private function respond_request(): ApiResponse
    // {
    // }



    // private function extract_input(RequestMethod $request_method): array
    // {
    //     return match ($request_method) {
    //         RequestMethod::get => $_GET,
    //         RequestMethod::post => match ($_SERVER['CONTENT_TYPE'] ?? '') {
    //             'application/json' => $this->extract_json_input(),
    //             default => []
    //         },
    //         default => []
    //     };
    // }

    // public function process_route_old(): ApiResponse
    // {
    //     global $timings;

    //     $routes = require ROOT_PATH . 'Config/routes.php';
    //     $allowed_origins =  require ROOT_PATH . 'Config/allowed_origins.php';

    //     if (strtolower($_SERVER['REQUEST_URI']) === '.env') {
    //         return e404();
    //     }

    //     /**
    //      * @var RequestMethod
    //      */
    //     $request_method = RequestMethod::tryFrom($_SERVER['REQUEST_METHOD'] ?? '') ?? RequestMethod::other;

    //     log_debug("request method is [$request_method->toString()]");

    //     // switch ($request_method) {
    //     //     case 'GET':
    //     //         $request_method = 'get';
    //     //         break;
    //     //     case 'POST':
    //     //         $request_method = 'post';
    //     //         break;
    //     //     case 'OPTIONS':
    //     //         $request_method = 'options';
    //     //         break;
    //     // }

    //     $request_path = parse_url($_SERVER['REQUEST_URI'])['path'] ?? '';
    //     log_debug("request path is [$request_path]");

    //     $request_parser = match ($request_method) {
    //         RequestMethod::get => new GetRequestParser(),
    //         RequestMethod::post => new PostRequestParser(),
    //         RequestMethod::options => new OptionsRequestParser(),
    //         default => new NullRequestParser()
    //     };

    //     // $class = $routes[$request_method][$request_path] ?? '';
    //     // $class = match ($request_method) {
    //     //     RequestMethod::get => $routes['GET'][$request_path] ?? '',
    //     //     RequestMethod::post => $routes['POST'][$request_method] ?? '',
    //     //     default => ''
    //     // };

    //     $class = $request_parser->identify_route($request_path);

    //     if (!$class) {
    //         return e404();
    //     }

    //     return match ($request_method) {
    //         RequestMethod::options => $this->respond_cors($allowed_origins),
    //         RequestMethod::get, RequestMethod::post => ''
    //     };

    //     $input = $this->extract_input($request_method);


    //     if ($request_method === 'options') {
    //         return $this->respond_cors($allowed_origins);
    //     } else if (($request_method === 'get') || ($request_method === 'post')) {
    //         // $request_path = htmlentities($_SERVER['REQUEST_URI'], ENT_QUOTES, "UTF-8");


    //         $instance = new $class();
    //         if ($instance instanceof IRouteHandler) {
    //             $input = [];
    //             if ($request_method === 'post') {
    //                 $content_type = $_SERVER['CONTENT_TYPE'] ?? '';
    //                 if ($content_type === 'application/json') {
    //                     $input = json_decode(file_get_contents('php://input'), true);
    //                     if ($input === null) {
    //                         $message = ' post request body extraction failed: ' . json_last_error_msg();
    //                         log_debug($message);
    //                         return e400($message);
    //                     }
    //                 } else {
    //                     $message = ' invalid content type. expected application/json';
    //                     log_debug($message);
    //                     return e400($message);
    //                 }
    //             } else {
    //                 $input = $_GET;
    //             }

    //             $reflect = new ReflectionClass($instance);
    //             $properties = $reflect->getProperties(ReflectionProperty::IS_PUBLIC);


    //             $missing_properties = [];
    //             foreach ($properties as $property) {
    //                 $property_name = $property->getName();
    //                 if (array_key_exists($property_name, $input)) {
    //                     $instance->$property_name = $input[$property_name];
    //                 } else {
    //                     log_debug(" expected [$property_name] not found in request body");
    //                     $missing_properties[] = $property_name;
    //                 }
    //             }

    //             $missing_properties_count = count($missing_properties);
    //             if ($missing_properties_count > 0) {
    //                 $message = "Error: $missing_properties_count missing properties";
    //                 log_debug($message);
    //                 return e400($message);
    //             }

    //             $timings['route_parsing_complete'] = microtime(true);

    //             $response = [];
    //             try {
    //                 $db_init_start = microtime(true);
    //                 // $db = Database::get_instance();
    //                 $db_init_time = microtime(true) - $db_init_start;
    //                 $timings['db_initialization_complete'] = microtime(true);
    //                 log_debug('db initialization took ' . ($db_init_time * 1000) . 'ms');
    //                 $response = $instance->process();
    //                 if (is_null($response)) {
    //                     $response = new ApiResponse('not ok', 'null');
    //                     log_debug('Null response from route handler');
    //                 }
    //             } catch (Exception $exception) {
    //                 log_debug('Exception: ' . $exception->getMessage());
    //             } catch (Error $error) {
    //                 log_debug('Error:' . $error->getMessage());
    //             }

    //             $this->respond_cors($allowed_origins);
    //             if (!($response instanceof ApiResponse)) {
    //                 $response = new ApiResponse('not ok', var_export($response, true));
    //             }
    //             return $response;
    //         } else {
    //             return e404('Not Implemented');
    //         }
    //     } else {
    //         return e405();
    //     }
    // }

    public function process_route(): ApiResponse
    {
        // global $timings;

        if (strtolower($_SERVER['REQUEST_URI']) === '.env') {
            return e404();
        }


        $http_request_method = $_SERVER['REQUEST_METHOD'] ?? '';
        $request_method = RequestMethod::tryFrom($http_request_method) ?? RequestMethod::other;
        log_debug("request method is [$http_request_method]");

        $request_parser = match ($request_method) {
            RequestMethod::get => new GetRequestParser(),
            RequestMethod::post => new PostRequestParser(),
            RequestMethod::options => new OptionsRequestParser(),
            default => new NullRequestParser()
        };

        return $request_parser->respond();
    }
}
