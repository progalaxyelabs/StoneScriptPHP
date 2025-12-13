<?php

namespace StoneScriptPHP\Routing;

use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Routing\MiddlewarePipeline;
use StoneScriptPHP\Routing\MiddlewareInterface;

class Router
{
    private MiddlewarePipeline $globalMiddleware;
    private array $routeMiddleware = [];
    private array $routes = [];
    private array $routeParams = [];

    public function __construct()
    {
        $this->globalMiddleware = new MiddlewarePipeline();
    }

    /**
     * Add global middleware that runs on all routes
     *
     * @param MiddlewareInterface $middleware
     * @return self
     */
    public function use(MiddlewareInterface $middleware): self
    {
        $this->globalMiddleware->pipe($middleware);
        return $this;
    }

    /**
     * Add multiple global middleware
     *
     * @param array $middlewares
     * @return self
     */
    public function useMany(array $middlewares): self
    {
        $this->globalMiddleware->pipes($middlewares);
        return $this;
    }

    /**
     * Register a GET route
     *
     * @param string $path
     * @param string $handler Handler class name
     * @param array $middleware Route-specific middleware
     * @return self
     */
    public function get(string $path, string $handler, array $middleware = []): self
    {
        return $this->addRoute('GET', $path, $handler, $middleware);
    }

    /**
     * Register a POST route
     *
     * @param string $path
     * @param string $handler Handler class name
     * @param array $middleware Route-specific middleware
     * @return self
     */
    public function post(string $path, string $handler, array $middleware = []): self
    {
        return $this->addRoute('POST', $path, $handler, $middleware);
    }

    /**
     * Register a route
     *
     * @param string $method HTTP method
     * @param string $path Route path
     * @param string $handler Handler class name
     * @param array $middleware Route-specific middleware
     * @return self
     */
    public function addRoute(string $method, string $path, string $handler, array $middleware = []): self
    {
        $method = strtoupper($method);

        if (!isset($this->routes[$method])) {
            $this->routes[$method] = [];
        }

        $this->routes[$method][$path] = $handler;

        // Store route-specific middleware
        $routeKey = "$method:$path";
        if (!empty($middleware)) {
            $this->routeMiddleware[$routeKey] = $middleware;
        }

        return $this;
    }

    /**
     * Load routes from configuration array
     *
     * @param array $routesConfig Array of routes by method
     * @return self
     */
    public function loadRoutes(array $routesConfig): self
    {
        foreach ($routesConfig as $method => $routes) {
            $method = strtoupper($method);
            if (is_array($routes)) {
                foreach ($routes as $path => $handler) {
                    $this->addRoute($method, $path, $handler);
                }
            }
        }
        return $this;
    }

    /**
     * Process the incoming request
     *
     * @return ApiResponse
     */
    public function dispatch(): ApiResponse
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        // Build request context
        $request = [
            'method' => $method,
            'path' => $path,
            'input' => $this->getInput(),
            'params' => [],
            'headers' => $this->getHeaders(),
        ];

        // Process through global middleware pipeline
        return $this->globalMiddleware->process($request, function($request) use ($method, $path) {
            // Find matching route
            $match = $this->matchRoute($method, $path);

            if (!$match) {
                return $this->error404();
            }

            $handler = $match['handler'];
            $request['params'] = $match['params'];
            $request['handler_class'] = $handler; // Add handler class to request for attribute checking
            $this->routeParams = $match['params'];

            // Get route-specific middleware
            $routeKey = "$method:" . $match['pattern'];
            $routeMiddleware = $this->routeMiddleware[$routeKey] ?? [];

            // If route has specific middleware, create a pipeline for it
            if (!empty($routeMiddleware)) {
                $routePipeline = new MiddlewarePipeline();
                $routePipeline->pipes($routeMiddleware);

                return $routePipeline->process($request, function($request) use ($handler) {
                    return $this->executeHandler($handler, $request);
                });
            }

            // No route-specific middleware, execute handler directly
            return $this->executeHandler($handler, $request);
        });
    }

    /**
     * Match route and extract parameters
     *
     * @param string $method
     * @param string $path
     * @return array|null
     */
    private function matchRoute(string $method, string $path): ?array
    {
        if (!isset($this->routes[$method])) {
            return null;
        }

        foreach ($this->routes[$method] as $pattern => $handler) {
            // Exact match
            if ($pattern === $path) {
                return [
                    'handler' => $handler,
                    'params' => [],
                    'pattern' => $pattern
                ];
            }

            // Pattern match (with parameters like /users/:id)
            $regex = $this->buildRegex($pattern);
            if (preg_match($regex, $path, $matches)) {
                array_shift($matches); // Remove full match
                $params = $this->extractParams($pattern, $matches);

                return [
                    'handler' => $handler,
                    'params' => $params,
                    'pattern' => $pattern
                ];
            }
        }

        return null;
    }

    /**
     * Build regex from route pattern
     *
     * @param string $pattern
     * @return string
     */
    private function buildRegex(string $pattern): string
    {
        // Convert :param to named capture group
        $regex = preg_replace('/\/:([a-zA-Z0-9_]+)/', '/(?P<$1>[^/]+)', $pattern);
        return '#^' . $regex . '$#';
    }

    /**
     * Extract parameters from matches
     *
     * @param string $pattern
     * @param array $matches
     * @return array
     */
    private function extractParams(string $pattern, array $matches): array
    {
        $params = [];

        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    /**
     * Execute the route handler
     *
     * @param string $handlerClass
     * @param array $request
     * @return ApiResponse
     */
    private function executeHandler(string $handlerClass, array $request): ApiResponse
    {
        try {
            if (!class_exists($handlerClass)) {
                log_debug("Handler class not found: $handlerClass");
                return $this->error404('Handler not found');
            }

            $handler = new $handlerClass();

            // Check if handler implements IRouteHandler interface
            if (!($handler instanceof \Framework\IRouteHandler)) {
                log_debug("Handler does not implement IRouteHandler: $handlerClass");
                return $this->error404('Handler not implemented correctly');
            }

            // Merge input and params
            $allInput = array_merge($request['input'] ?? [], $request['params'] ?? []);

            // Populate handler properties from input
            $reflection = new \ReflectionClass($handler);
            $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);

            foreach ($properties as $property) {
                $propertyName = $property->getName();
                if (array_key_exists($propertyName, $allInput)) {
                    $handler->$propertyName = $allInput[$propertyName];
                }
            }

            // Execute handler
            $response = $handler->process();

            if (!($response instanceof ApiResponse)) {
                log_debug('Handler did not return ApiResponse');
                return new ApiResponse('error', 'Invalid handler response');
            }

            return $response;

        } catch (\Exception $e) {
            log_debug('Exception in handler: ' . $e->getMessage());
            return $this->error500($e->getMessage());
        }
    }

    /**
     * Get input based on request method
     *
     * @return array
     */
    private function getInput(): array
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method === 'GET') {
            return $_GET;
        }

        if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            $mediaType = trim(explode(';', $contentType)[0]);

            if ($mediaType === 'application/json') {
                $json = json_decode(file_get_contents('php://input'), true);
                return is_array($json) ? $json : [];
            }

            return $_POST;
        }

        return [];
    }

    /**
     * Get request headers
     *
     * @return array
     */
    private function getHeaders(): array
    {
        if (function_exists('getallheaders')) {
            return getallheaders();
        }

        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) === 'HTTP_') {
                $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$headerName] = $value;
            }
        }

        return $headers;
    }

    /**
     * Return 404 error
     *
     * @param string $message
     * @return ApiResponse
     */
    private function error404(string $message = 'Not found'): ApiResponse
    {
        http_response_code(404);
        return new ApiResponse('error', $message);
    }

    /**
     * Return 500 error
     *
     * @param string $message
     * @return ApiResponse
     */
    private function error500(string $message = 'Internal server error'): ApiResponse
    {
        http_response_code(500);
        return new ApiResponse('error', DEBUG_MODE ? $message : 'Internal server error');
    }
}
