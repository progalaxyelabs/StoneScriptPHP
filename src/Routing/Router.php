<?php

declare(strict_types=1);

namespace StoneScriptPHP\Routing;

use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Routing\MiddlewarePipeline;
use StoneScriptPHP\Routing\MiddlewareInterface;
use StoneScriptPHP\Routing\RouteEntry;

class Router
{
    private MiddlewarePipeline $globalMiddleware;
    private array $routeMiddleware = [];
    private array $routes = [];
    private array $routeParams = [];
    private array $routeMeta = []; // ['METHOD:path' => ['is_public' => bool, 'scope' => string]]

    /** @var array<string, MiddlewarePipeline> Scope-specific middleware pipelines */
    private array $scopeMiddleware = [];

    /** @var string[] Known scope names (from routes config or scope() calls) */
    private array $knownScopes = [];

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
     * Define scope-specific middleware.
     *
     * When a request matches a route with the given scope, these middleware
     * run AFTER global middleware but BEFORE the route handler.
     *
     * Usage:
     *   $router->scope('portal', function($r) {
     *       $r->use(new GatewayTenantMiddleware());
     *       $r->use(new SubscriptionMiddleware());
     *   });
     *
     * @param string $scopeName The scope name (e.g., 'portal', 'admin')
     * @param callable $callback Receives a ScopeMiddlewareBuilder to add middleware
     * @return self
     */
    public function scope(string $scopeName, callable $callback): self
    {
        if (!isset($this->scopeMiddleware[$scopeName])) {
            $this->scopeMiddleware[$scopeName] = new MiddlewarePipeline();
        }

        if (!in_array($scopeName, $this->knownScopes)) {
            $this->knownScopes[] = $scopeName;
        }

        $builder = new ScopeMiddlewareBuilder($this->scopeMiddleware[$scopeName]);
        $callback($builder);

        return $this;
    }

    /**
     * Register a GET route
     *
     * @param string $path
     * @param string|object $handler Handler class name or pre-instantiated handler object
     * @param array $middleware Route-specific middleware
     * @return self
     */
    public function get(string $path, string|object $handler, array $middleware = [], bool $isPublic = false, string $scope = 'shared'): self
    {
        return $this->addRoute('GET', $path, $handler, $middleware, $isPublic, $scope);
    }

    /**
     * Register a POST route
     *
     * @param string $path
     * @param string|object $handler Handler class name or pre-instantiated handler object
     * @param array $middleware Route-specific middleware
     * @param bool $isPublic Whether this route is public (no JWT required)
     * @return self
     */
    public function post(string $path, string|object $handler, array $middleware = [], bool $isPublic = false, string $scope = 'shared'): self
    {
        return $this->addRoute('POST', $path, $handler, $middleware, $isPublic, $scope);
    }

    /**
     * Register a route
     *
     * @param string $method HTTP method
     * @param string $path Route path
     * @param string|object $handler Handler class name or pre-instantiated handler object
     * @param array $middleware Route-specific middleware
     * @param bool $isPublic Whether this route is public (no JWT required). Default false (protected).
     * @param string $scope Route scope (e.g., 'portal', 'admin', 'shared'). Default 'shared'.
     * @return self
     */
    public function addRoute(string $method, string $path, string|object $handler, array $middleware = [], bool $isPublic = false, string $scope = 'shared'): self
    {
        $method = strtoupper($method);

        if (!isset($this->routes[$method])) {
            $this->routes[$method] = [];
        }

        $this->routes[$method][$path] = $handler;

        // Store route-level metadata (auth requirement + scope)
        $routeKey = "$method:$path";
        $this->routeMeta[$routeKey] = [
            'is_public' => $isPublic,
            'scope' => $scope,
        ];

        // Track known scopes
        if (!in_array($scope, $this->knownScopes)) {
            $this->knownScopes[] = $scope;
        }

        // Store route-specific middleware
        if (!empty($middleware)) {
            $this->routeMiddleware[$routeKey] = $middleware;
        }

        return $this;
    }

    /**
     * Normalize a route config value to extract handler, scope, and alias.
     *
     * Supports both old format (string handler) and new format (array with scope):
     *   Old: '/health' => HealthRoute::class
     *   New: '/portal/dashboard' => ['handler' => GetDashboardRoute::class, 'scope' => 'portal']
     *
     * @param string|array|object $config The route config value
     * @return RouteEntry
     */
    public static function normalizeRouteConfig(string|array|object $config): RouteEntry
    {
        if (is_string($config) || is_object($config)) {
            // Old format: handler class string or pre-instantiated object
            return new RouteEntry(handler: $config, scope: 'shared', isAlias: false);
        }

        // New array format
        return new RouteEntry(
            handler: $config['handler'],
            scope: $config['scope'] ?? 'shared',
            isAlias: $config['alias'] ?? false,
        );
    }

    /**
     * Load routes from configuration array.
     *
     * Supports multiple formats:
     *
     * Format 1 (public/protected sections):
     *   ['public'    => ['GET' => ['/health' => HealthRoute::class]],
     *    'protected' => ['GET' => ['/dashboard' => DashboardRoute::class]]]
     *
     * Format 2 (legacy flat format):
     *   ['GET' => ['/health' => HealthRoute::class]]
     *
     * Format 3 (with scopes):
     *   ['scopes' => ['portal', 'admin', 'shared'],
     *    'GET' => ['/portal/dashboard' => ['handler' => DashboardRoute::class, 'scope' => 'portal']]]
     *
     * Route values can be:
     *   - string: Handler class name (scope defaults to 'shared')
     *   - array:  ['handler' => class, 'scope' => 'portal', 'alias' => false]
     *
     * @param array $routesConfig
     * @return self
     */
    public function loadRoutes(array $routesConfig): self
    {
        // Extract optional top-level 'scopes' declaration
        if (isset($routesConfig['scopes']) && is_array($routesConfig['scopes'])) {
            foreach ($routesConfig['scopes'] as $scopeName) {
                if (!in_array($scopeName, $this->knownScopes)) {
                    $this->knownScopes[] = $scopeName;
                }
            }
        }

        if (array_key_exists('public', $routesConfig) || array_key_exists('protected', $routesConfig)) {
            // Format 1: public/protected sections
            foreach ($routesConfig['public'] ?? [] as $method => $routes) {
                if (is_array($routes)) {
                    foreach ($routes as $path => $config) {
                        $entry = self::normalizeRouteConfig($config);
                        $this->addRoute(strtoupper($method), $path, $entry->handler, [], true, $entry->scope);
                    }
                }
            }
            foreach ($routesConfig['protected'] ?? [] as $method => $routes) {
                if (is_array($routes)) {
                    foreach ($routes as $path => $config) {
                        $entry = self::normalizeRouteConfig($config);
                        $this->addRoute(strtoupper($method), $path, $entry->handler, [], false, $entry->scope);
                    }
                }
            }
        } else {
            // Format 2/3: flat format with optional scope in route values
            foreach ($routesConfig as $method => $routes) {
                // Skip non-HTTP-method keys like 'scopes'
                if (!is_array($routes) || $method === 'scopes') {
                    continue;
                }
                $method = strtoupper($method);
                foreach ($routes as $path => $config) {
                    $entry = self::normalizeRouteConfig($config);
                    $this->addRoute($method, $path, $entry->handler, [], false, $entry->scope);
                }
            }
        }
        return $this;
    }

    /**
     * Get route metadata for all routes.
     *
     * Returns an array of route info suitable for client generation:
     * [['method' => 'GET', 'path' => '/health', 'handler' => 'HealthRoute', 'scope' => 'shared', 'is_alias' => false], ...]
     *
     * @return array
     */
    public function getRouteMeta(): array
    {
        $result = [];
        foreach ($this->routes as $method => $methodRoutes) {
            foreach ($methodRoutes as $path => $handler) {
                $routeKey = "$method:$path";
                $meta = $this->routeMeta[$routeKey] ?? [];
                $result[] = [
                    'method' => $method,
                    'path' => $path,
                    'handler' => is_object($handler) ? get_class($handler) : $handler,
                    'scope' => $meta['scope'] ?? 'shared',
                    'is_public' => $meta['is_public'] ?? false,
                ];
            }
        }
        return $result;
    }

    /**
     * Get known scopes
     *
     * @return string[]
     */
    public function getKnownScopes(): array
    {
        return $this->knownScopes;
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

        // Pre-match route BEFORE running middleware so middleware (e.g. JwtAuthMiddleware)
        // can inspect the route's is_public flag without needing a separate excludedPaths list.
        $match = $this->matchRoute($method, $path);

        // Build request context — includes route metadata for middleware
        $request = [
            'method'  => $method,
            'path'    => $path,
            'input'   => $this->getInput(),
            'params'  => $match['params'] ?? [],
            'headers' => $this->getHeaders(),
            // null when route not found — middleware passes through, closure returns 404
            'route'   => $match ? [
                'pattern'       => $match['pattern'],
                'is_public'     => $match['is_public'] ?? false,
                'scope'         => $match['scope'] ?? 'shared',
                'handler_class' => is_object($match['handler']) ? get_class($match['handler']) : $match['handler'],
            ] : null,
        ];

        // Process through global middleware pipeline.
        // Closure captures $match from outer scope — avoids double-matching.
        return $this->globalMiddleware->process($request, function($request) use ($method, $match) {
            if (!$match) {
                return $this->error404();
            }

            $handler = $match['handler'];
            $request['params'] = $match['params'];
            // Normalize handler_class to string for middleware attribute checking
            $request['handler_class'] = is_object($handler) ? get_class($handler) : $handler;
            $this->routeParams = $match['params'];

            // Determine the scope for this route
            $routeScope = $match['scope'] ?? 'shared';

            // Build the middleware chain: scope middleware first, then route-specific middleware
            $routeKey = "$method:" . $match['pattern'];
            $routeMiddleware = $this->routeMiddleware[$routeKey] ?? [];

            // If there's scope-specific middleware, run it before route middleware
            $scopePipeline = $this->scopeMiddleware[$routeScope] ?? null;

            if ($scopePipeline && $scopePipeline->count() > 0) {
                // Run scope middleware, then route middleware, then handler
                return $scopePipeline->process($request, function($request) use ($handler, $routeMiddleware) {
                    if (!empty($routeMiddleware)) {
                        $routePipeline = new MiddlewarePipeline();
                        $routePipeline->pipes($routeMiddleware);
                        return $routePipeline->process($request, function($request) use ($handler) {
                            return $this->executeHandler($handler, $request);
                        });
                    }
                    return $this->executeHandler($handler, $request);
                });
            }

            // No scope middleware — check for route-specific middleware only
            if (!empty($routeMiddleware)) {
                $routePipeline = new MiddlewarePipeline();
                $routePipeline->pipes($routeMiddleware);

                return $routePipeline->process($request, function($request) use ($handler) {
                    return $this->executeHandler($handler, $request);
                });
            }

            // No scope or route-specific middleware, execute handler directly
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
            $routeKey = "$method:$pattern";

            // Exact match
            if ($pattern === $path) {
                return [
                    'handler'   => $handler,
                    'params'    => [],
                    'pattern'   => $pattern,
                    'is_public' => $this->routeMeta[$routeKey]['is_public'] ?? false,
                    'scope'     => $this->routeMeta[$routeKey]['scope'] ?? 'shared',
                ];
            }

            // Pattern match (with parameters like /users/:id)
            $regex = $this->buildRegex($pattern);
            if (preg_match($regex, $path, $matches)) {
                array_shift($matches); // Remove full match
                $params = $this->extractParams($pattern, $matches);

                return [
                    'handler'   => $handler,
                    'params'    => $params,
                    'pattern'   => $pattern,
                    'is_public' => $this->routeMeta[$routeKey]['is_public'] ?? false,
                    'scope'     => $this->routeMeta[$routeKey]['scope'] ?? 'shared',
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
     * @param string|object $handlerClass Handler class name or pre-instantiated handler object
     * @param array $request
     * @return ApiResponse
     */
    private function executeHandler(string|object $handlerClass, array $request): ApiResponse
    {
        try {
            if (is_object($handlerClass)) {
                // Pre-instantiated handler object (e.g. RefreshRoute with jwtHandler injected)
                $handler = $handlerClass;
                $handlerClass = get_class($handler);
            } else {
                if (!class_exists($handlerClass)) {
                    log_debug("Handler class not found: $handlerClass");
                    return $this->error404('Handler not found');
                }

                $handler = new $handlerClass();
            }

            // Check if handler implements IRouteHandler interface
            if (!($handler instanceof \StoneScriptPHP\IRouteHandler)) {
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
                    $value = $allInput[$propertyName];
                    // Coerce string values to match typed property declarations
                    if ($value !== null && $property->hasType()) {
                        $type = $property->getType();
                        $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;
                        if ($typeName === 'int' && is_string($value) && is_numeric($value)) {
                            $value = (int) $value;
                        } elseif ($typeName === 'float' && is_string($value) && is_numeric($value)) {
                            $value = (float) $value;
                        } elseif ($typeName === 'bool' && is_string($value)) {
                            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $value;
                        }
                    }
                    $handler->$propertyName = $value;
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
