<?php

declare(strict_types=1);

namespace StoneScriptPHP\Routing;

use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Validator;
use StoneScriptPHP\TenantDatabaseUnavailableException;
use StoneScriptPHP\Routing\MiddlewarePipeline;
use StoneScriptPHP\Routing\MiddlewareInterface;
use StoneScriptPHP\Routing\RouteEntry;

class Router
{
    private MiddlewarePipeline $globalMiddleware;
    private array $routeMiddleware = [];
    private array $routes = [];
    private array $routeParams = [];
    private array $routeMeta = []; // ['METHOD:path' => ['is_public' => bool, 'service' => string, ...]]

    /** @var array<string, MiddlewarePipeline> Scope/service-specific middleware pipelines */
    private array $scopeMiddleware = [];

    /** @var string[] Known service names (from routes config or scope()/service() calls) */
    private array $knownScopes = [];

    /**
     * Context set by group() — carried into individual route registrations.
     * Reset to null after each group() callback returns.
     */
    private ?array $groupContext = null;

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
     * Define scope/service-specific middleware.
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
     * @param string $scopeName The scope/service name (e.g., 'portal', 'admin')
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
     * Register a group of routes sharing a common path prefix and attributes.
     *
     * This is the v4.0 route-grouping API (CLIENT-SDK-SPEC §0 A2). Individual routes
     * inside the callback receive `group:`, `action:`, `streaming:`, and `param:` named
     * arguments to carry client-generation metadata.
     *
     * Usage:
     *   $router->group('/portal/tenant/{tenantId}', ['service' => 'portal', 'middleware' => 'tenant-access'], function() use ($router) {
     *       $router->get('/items',           ListItemsRoute::class,   group: 'inventory');
     *       $router->post('/items/create',   CreateItemRoute::class,  group: 'inventory');
     *       $router->get('/ws/{id}/events',  WsEventsRoute::class,    group: 'workspaces', streaming: true);
     *   });
     *
     *   // Excluded routes (no group required):
     *   $router->group('/', [], function() use ($router) {
     *       $router->get('/health',                HealthRoute::class,   service: 'infra');
     *       $router->post('/payments/webhook',     WebhookRoute::class,  service: 'webhook');
     *   });
     *
     * @param string   $prefix     URL prefix for all routes in this group
     * @param array    $attributes Group-level attributes: 'service', 'middleware', 'is_public'
     * @param callable $callback   Registers routes via $router->get()/post() inside the group
     * @return self
     */
    public function group(string $prefix, array $attributes, callable $callback): self
    {
        // Resolve service from attributes
        $service = $attributes['service'] ?? 'shared';
        $isPublic = $attributes['is_public'] ?? false;

        // Save previous context (supports nested groups)
        $previousContext = $this->groupContext;

        $this->groupContext = [
            'prefix'    => $prefix,
            'service'   => $service,
            'is_public' => $isPublic,
        ];

        // Register scope-level middleware if declared via 'middleware' key
        if (isset($attributes['middleware'])) {
            $middlewareName = $attributes['middleware'];
            if (!isset($this->scopeMiddleware[$service])) {
                $this->scopeMiddleware[$service] = new MiddlewarePipeline();
            }
            if (!in_array($service, $this->knownScopes)) {
                $this->knownScopes[] = $service;
            }
            // Note: string middleware names are resolved at dispatch time by the platform's
            // index.php. The group() API records the intent; it doesn't wire a PHP object here.
            // Platforms that use named middleware pass objects via scope() — this is fine for
            // recording metadata (the generator reads service/group/action, not middleware).
        }

        $callback();

        // Restore previous context
        $this->groupContext = $previousContext;

        return $this;
    }

    /**
     * Register a GET route.
     *
     * v4.0 named parameters (CLIENT-SDK-SPEC §0 A2):
     *   group:     domain-concept grouping for the generated client (MANDATORY on includable routes)
     *   action:    optional explicit method name override (kebab→camelCase)
     *   streaming: when true, exclude from generated client (A1)
     *   param:     documentation label for the tail :id parameter (A5, doc-only)
     *   service:   service partition key override (overrides group-level service when set)
     *
     * @param string      $path      Route path (relative to group prefix when inside group())
     * @param string|object $handler Handler class name or pre-instantiated handler object
     * @param array       $middleware Route-specific middleware
     * @param bool        $isPublic  Whether this route is public (no JWT required)
     * @param string|null $group     Domain-concept group for the generated client
     * @param string|null $action    Explicit action name override
     * @param bool        $streaming When true, exclude from client generation (A1)
     * @param string|null $param     Tail :id param documentation label (A5, doc-only)
     * @param string|null $service   Service partition key (overrides group-level service)
     * @return self
     */
    public function get(
        string $path,
        string|object $handler,
        array $middleware = [],
        bool $isPublic = false,
        ?string $group = null,
        ?string $action = null,
        bool $streaming = false,
        ?string $param = null,
        ?string $service = null,
        ?string $response = null,
        bool $collection = false,
    ): self {
        return $this->addRoute('GET', $path, $handler, $middleware, $isPublic, $group, $action, $streaming, $param, $service, $response, $collection);
    }

    /**
     * Register a POST route.
     *
     * v4.0 named parameters — same as get(). See get() for full documentation.
     */
    public function post(
        string $path,
        string|object $handler,
        array $middleware = [],
        bool $isPublic = false,
        ?string $group = null,
        ?string $action = null,
        bool $streaming = false,
        ?string $param = null,
        ?string $service = null,
        ?string $response = null,
        bool $collection = false,
    ): self {
        return $this->addRoute('POST', $path, $handler, $middleware, $isPublic, $group, $action, $streaming, $param, $service, $response, $collection);
    }

    /**
     * Register a route.
     *
     * @param string      $method    HTTP method
     * @param string      $path      Route path (relative to group prefix when inside group())
     * @param string|object $handler Handler class name or pre-instantiated handler object
     * @param array       $middleware Route-specific middleware
     * @param bool        $isPublic  Whether this route is public (no JWT required). Default false (protected).
     * @param string|null $group     Domain-concept group for the generated client (v4.0, A2)
     * @param string|null $action    Explicit action name override (v4.0, A2)
     * @param bool        $streaming When true, exclude from client generation (v4.0, A1)
     * @param string|null $param     Tail :id param documentation label (v4.0, A5, doc-only)
     * @param string|null $service   Service partition key override (v4.0, A2/A3)
     * @return self
     */
    public function addRoute(
        string $method,
        string $path,
        string|object $handler,
        array $middleware = [],
        bool $isPublic = false,
        ?string $group = null,
        ?string $action = null,
        bool $streaming = false,
        ?string $param = null,
        ?string $service = null,
        ?string $response = null,
        bool $collection = false,
    ): self {
        $method = strtoupper($method);

        // Resolve full path and service from group context (if inside group())
        $fullPath = $path;
        $effectiveService = $service ?? 'shared';
        $effectiveIsPublic = $isPublic;

        if ($this->groupContext !== null) {
            $prefix = rtrim($this->groupContext['prefix'], '/');
            $fullPath = $prefix . '/' . ltrim($path, '/');
            // Group service wins unless route explicitly sets its own service
            if ($service === null) {
                $effectiveService = $this->groupContext['service'];
            } else {
                $effectiveService = $service;
            }
            if (!$isPublic) {
                $effectiveIsPublic = $this->groupContext['is_public'];
            }
        }

        if (!isset($this->routes[$method])) {
            $this->routes[$method] = [];
        }

        $this->routes[$method][$fullPath] = $handler;

        // Store route-level metadata
        $routeKey = "$method:$fullPath";
        $this->routeMeta[$routeKey] = [
            'is_public' => $effectiveIsPublic,
            'service'   => $effectiveService,
            'group'      => $group,
            'action'     => $action,
            'streaming'  => $streaming,
            'param'      => $param,
            'response'   => $response,
            'collection' => $collection,
        ];

        // Track known services/scopes
        if (!in_array($effectiveService, $this->knownScopes)) {
            $this->knownScopes[] = $effectiveService;
        }

        // Store route-specific middleware
        if (!empty($middleware)) {
            $this->routeMiddleware[$routeKey] = $middleware;
        }

        return $this;
    }

    /**
     * Normalize a route config value to extract handler, service, group, and other metadata.
     *
     * Supports multiple formats:
     *   String handler: '/health' => HealthRoute::class
     *   Array (v4.0):   ['handler' => ListItemsRoute::class, 'service' => 'portal', 'group' => 'inventory']
     *
     * @param string|array|object $config The route config value
     * @return RouteEntry
     */
    public static function normalizeRouteConfig(string|array|object $config): RouteEntry
    {
        if (is_string($config) || is_object($config)) {
            // Handler class string or pre-instantiated object
            return new RouteEntry(handler: $config, service: 'shared', isAlias: false);
        }

        // Array format — v4.0 uses 'service'
        $service = $config['service'] ?? 'shared';
        return new RouteEntry(
            handler:   $config['handler'],
            service:   $service,
            isAlias:   $config['alias']     ?? false,
            group:     $config['group']     ?? null,
            action:    $config['action']    ?? null,
            streaming: $config['streaming'] ?? false,
            param:     $config['param']     ?? null,
            response:  $config['response']   ?? null,
            collection: $config['collection'] ?? false,
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
     * Format 2 (flat format):
     *   ['GET' => ['/health' => HealthRoute::class]]
     *
     * Route values can be:
     *   - string: Handler class name (service defaults to 'shared')
     *   - array:  ['handler' => class, 'service' => 'portal', 'group' => 'billing', 'alias' => false]
     *
     * @param array $routesConfig
     * @return self
     */
    public function loadRoutes(array $routesConfig): self
    {
        if (array_key_exists('public', $routesConfig) || array_key_exists('protected', $routesConfig)) {
            // Format 1: public/protected sections
            foreach ($routesConfig['public'] ?? [] as $method => $routes) {
                if (is_array($routes)) {
                    foreach ($routes as $path => $config) {
                        $entry = self::normalizeRouteConfig($config);
                        $this->addRoute(strtoupper($method), $path, $entry->handler, [], true, $entry->group, $entry->action, $entry->streaming, $entry->param, $entry->service !== 'shared' ? $entry->service : null, $entry->response, $entry->collection);
                    }
                }
            }
            foreach ($routesConfig['protected'] ?? [] as $method => $routes) {
                if (is_array($routes)) {
                    foreach ($routes as $path => $config) {
                        $entry = self::normalizeRouteConfig($config);
                        $this->addRoute(strtoupper($method), $path, $entry->handler, [], false, $entry->group, $entry->action, $entry->streaming, $entry->param, $entry->service !== 'shared' ? $entry->service : null, $entry->response, $entry->collection);
                    }
                }
            }
        } else {
            // Format 2: flat format with optional service in route values
            foreach ($routesConfig as $method => $routes) {
                // Skip non-HTTP-method keys
                if (!is_array($routes)) {
                    continue;
                }
                $method = strtoupper($method);
                foreach ($routes as $path => $config) {
                    $entry = self::normalizeRouteConfig($config);
                    $this->addRoute($method, $path, $entry->handler, [], false, $entry->group, $entry->action, $entry->streaming, $entry->param, $entry->service !== 'shared' ? $entry->service : null, $entry->response, $entry->collection);
                }
            }
        }
        return $this;
    }

    /**
     * Get route metadata for all routes.
     *
     * Returns an array of route info suitable for client generation (v4.0):
     * [
     *   [
     *     'method'    => 'GET',
     *     'path'      => '/portal/tenant/{tenantId}/items',
     *     'handler'   => 'App\Routes\ListItemsRoute',
     *     'service'   => 'portal',          // partition key (A2)
     *     'group'     => 'inventory',       // domain-concept group (null = not declared)
     *     'action'    => null,              // explicit action override (null = derive)
     *     'streaming' => false,             // SSE/streaming route flag (A1)
     *     'param'     => null,              // tail :id documentation label (A5)
     *     'is_public' => false,
     *   ],
     *   ...
     * ]
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
                    'method'    => $method,
                    'path'      => $path,
                    'handler'   => is_object($handler) ? get_class($handler) : $handler,
                    'service'   => $meta['service']   ?? 'shared',
                    'group'     => $meta['group']     ?? null,
                    'action'    => $meta['action']    ?? null,
                    'streaming' => $meta['streaming'] ?? false,
                    'param'     => $meta['param']     ?? null,
                    'response'   => $meta['response']   ?? null,
                    'collection' => $meta['collection'] ?? false,
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
                'service'       => $match['service'] ?? 'shared',
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

            // Determine the service for this route (for middleware pipeline lookup)
            $routeScope = $match['service'] ?? 'shared';

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
                    'service'   => $this->routeMeta[$routeKey]['service'] ?? 'shared',
                ];
            }

            // Pattern match (with parameters like /users/{id})
            $regex = $this->buildRegex($pattern);
            if (preg_match($regex, $path, $matches)) {
                array_shift($matches); // Remove full match
                $params = $this->extractParams($pattern, $matches);

                return [
                    'handler'   => $handler,
                    'params'    => $params,
                    'pattern'   => $pattern,
                    'is_public' => $this->routeMeta[$routeKey]['is_public'] ?? false,
                    'service'   => $this->routeMeta[$routeKey]['service'] ?? 'shared',
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
        // {curly}-ONLY param syntax (v4.0.1). The legacy ":colon" syntax is no
        // longer supported — runtime matching now agrees with the client
        // generator (CLIENT-SDK-SPEC §0), which emits {curly} placeholders.
        // preg_quote first so any other regex-special chars in the path are
        // safely escaped, then turn each {param} into a named capture group.
        $regex = preg_quote($pattern, '#');
        $regex = preg_replace('/\\\{([a-zA-Z0-9_]+)\\\}/', '(?P<$1>[^/]+)', $regex);
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

            // Honor the handler's declared validation_rules() at the edge. Without
            // this, declared required-field rules were dead code under the new
            // router: missing/invalid input reached the handler (and SQL functions)
            // as NULL, surfacing as a 500 instead of a clean 400. (#3055)
            $validationRules = $handler->validation_rules();
            if (!empty($validationRules)) {
                $validator = new Validator($allInput, $validationRules);
                if (!$validator->validate()) {
                    $errors = $validator->errors();
                    log_debug('Validation failed: ' . json_encode($errors));
                    http_response_code(400);
                    return new ApiResponse(
                        'error',
                        'Validation failed',
                        DEBUG_MODE ? $errors : null,
                        400,
                        $errors
                    );
                }
            }

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

        } catch (TenantDatabaseUnavailableException $e) {
            log_error('Tenant database unavailable: ' . $e->getMessage());
            http_response_code(401);
            return new ApiResponse('error', 'Unauthorized: session references an unavailable tenant. Please sign in again.');
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
