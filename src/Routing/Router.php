<?php

declare(strict_types=1);

namespace StoneScriptPHP\Routing;

use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Validator;
use StoneScriptPHP\TenantDatabaseUnavailableException;
use StoneScriptPHP\Routing\MiddlewarePipeline;
use StoneScriptPHP\Routing\MiddlewareInterface;
use StoneScriptPHP\Routing\RouteEntry;
use StoneScriptPHP\Binding\TypedArray;

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
     * The global middleware instances, in pipe order (read-only introspection).
     *
     * @return TypedArray<MiddlewareInterface>
     */
    public function getGlobalMiddleware(): TypedArray
    {
        return $this->globalMiddleware->getMiddleware();
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
     * Register a GET route.
     *
     * v4.0 named parameters (the client SDK contract):
     *   group:     domain-concept grouping for the generated client (MANDATORY on includable routes)
     *   action:    optional explicit method name override (kebab→camelCase)
     *   streaming: when true, exclude from generated client (A1)
     *   param:     documentation label for the tail :id parameter (A5, doc-only)
     *   service:   service partition key override (overrides group-level service when set)
     *
     * @param string      $path      Route path
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
        ?string $access = null,
        string $tokenType = 'access',
        ?string $request = null,
        ?string $head = null,
    ): self {
        return $this->addRoute('GET', $path, $handler, $middleware, $isPublic, $group, $action, $streaming, $param, $service, $response, $collection, $access, $tokenType, null, $request, $head);
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
        ?string $access = null,
        string $tokenType = 'access',
        ?string $request = null,
    ): self {
        return $this->addRoute('POST', $path, $handler, $middleware, $isPublic, $group, $action, $streaming, $param, $service, $response, $collection, $access, $tokenType, null, $request);
    }

    /**
     * Register a route.
     *
     * @param string      $method    HTTP method
     * @param string      $path      Route path
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
        ?string $access = null,
        string $tokenType = 'access',
        ?int $clientTimeoutMs = null,
        ?string $request = null,
        ?string $head = null,
    ): self {
        $method = strtoupper($method);
        $fullPath = $path;
        $effectiveService = $service ?? 'shared';

        // Reconcile the legacy `is_public` boolean with the v6.2.0 `access` model.
        // `access` wins when set; otherwise derive it from `is_public`. `is_public`
        // stays populated (== access is 'public') so the legacy JwtAuthMiddleware
        // path keeps working unchanged.
        if ($access !== null && !RouteAccess::isValidAccess($access)) {
            throw new \InvalidArgumentException(
                "Route '$method $fullPath' declares invalid access '$access' — expected one of "
                . implode('|', RouteAccess::ACCESS_VALUES) . '.'
            );
        }
        if ($access === null) {
            $access = $isPublic ? RouteAccess::PUBLIC : RouteAccess::AUTHORIZATION;
        }
        $effectiveIsPublic = $access === RouteAccess::PUBLIC;
        if (!RouteAccess::isValidTokenType($tokenType)) {
            throw new \InvalidArgumentException(
                "Route '$method $fullPath' declares invalid token_type '$tokenType'."
            );
        }

        if (!isset($this->routes[$method])) {
            $this->routes[$method] = [];
        }

        $this->routes[$method][$fullPath] = $handler;

        // Store route-level metadata
        $routeKey = "$method:$fullPath";
        $this->routeMeta[$routeKey] = [
            'is_public' => $effectiveIsPublic,
            'access'    => $access,
            'token_type' => $tokenType,
            'service'   => $effectiveService,
            'group'      => $group,
            'action'     => $action,
            'streaming'  => $streaming,
            'param'      => $param,
            'response'   => $response,
            'collection' => $collection,
            'client_timeout_ms' => $clientTimeoutMs,
            'request'    => $request,
            'head'       => $head,
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
            isPublic:  $config['is_public']  ?? false,
            access:    $config['access']     ?? null,
            tokenType: $config['token_type'] ?? 'access',
            clientTimeoutMs: isset($config['client_timeout_ms']) ? (int) $config['client_timeout_ms'] : null,
            request:   $config['request']    ?? null,
            head:      $config['head']       ?? null,
        );
    }

    /**
     * Load routes from configuration array.
     *
     * ONE format, as of v6.0.0 (see ROUTING-CONSOLIDATION-PLAN.md): a flat
     * array keyed by HTTP method.
     *
     *   ['GET' => ['/health' => HealthRoute::class]]
     *
     * Route values can be:
     *   - string: Handler class name (service defaults to 'shared', protected by default)
     *   - array:  ['handler' => class, 'service' => 'portal', 'group' => 'billing',
     *              'action' => 'get', 'is_public' => false, 'alias' => false]
     *
     * The previously-supported 'public'/'protected'-sectioned format (removed
     * in v6.0.0 — it was never adopted by any real platform; every one of the
     * 11 platforms already used the flat format above) is detected and
     * rejected with a clear migration error below, rather than silently
     * registering routes that will never match a real request (their HTTP
     * method keys would be the strings "public"/"protected", not GET/POST/etc).
     *
     * @param array $routesConfig
     * @return self
     * @throws \Exception If $routesConfig uses the removed 'public'/'protected' sectioned format.
     */
    public function loadRoutes(array $routesConfig): self
    {
        if (array_key_exists('public', $routesConfig) || array_key_exists('protected', $routesConfig)) {
            throw new \Exception(
                "Router::loadRoutes(): the 'public'/'protected' sectioned route format was removed in v6.0.0. " .
                "Use the flat format instead: ['GET' => ['/path' => ['handler' => X::class, 'is_public' => true, ...]]]. " .
                'See SPEC.md §3 Routing Conventions.'
            );
        }

        foreach ($routesConfig as $method => $routes) {
            // Skip non-HTTP-method keys
            if (!is_array($routes)) {
                continue;
            }
            $method = strtoupper($method);
            foreach ($routes as $path => $config) {
                $entry = self::normalizeRouteConfig($config);
                $this->addRoute($method, $path, $entry->handler, [], $entry->isPublic, $entry->group, $entry->action, $entry->streaming, $entry->param, $entry->service !== 'shared' ? $entry->service : null, $entry->response, $entry->collection, $entry->access, $entry->tokenType, $entry->clientTimeoutMs, $entry->request, $entry->head);
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
                    'access'     => $meta['access']     ?? null,
                    'token_type' => $meta['token_type'] ?? 'access',
                    'client_timeout_ms' => $meta['client_timeout_ms'] ?? null,
                    'request'    => $meta['request']    ?? null,
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
     * Process the incoming request.
     *
     * @param IncomingRequest|null $incoming When provided, method/path/headers/
     *   query/body/cookies are read from this object instead of PHP
     *   superglobals — the seam that makes route-level testing possible
     *   (the testability notes T1-1). When null (the default — every current
     *   production call site), behavior is unchanged: reads
     *   $_SERVER/$_GET/$_POST/php://input/getallheaders()/$_COOKIE exactly as
     *   before.
     * @return ApiResponse
     */
    public function dispatch(?IncomingRequest $incoming = null): ApiResponse
    {
        \StoneScriptPHP\Persistence\PersistenceLedger::reset();
        $method = strtoupper($incoming?->method ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $path = $incoming?->path ?? parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        // Pre-match route BEFORE running middleware so middleware (e.g. JwtAuthMiddleware)
        // can inspect the route's is_public flag without needing a separate excludedPaths list.
        // HEAD (RFC 9110 9.3.2): an explicit HEAD route wins; otherwise the GET route
        // answers it. `$match['method']` is the method the route was REGISTERED under
        // (GET for a HEAD request served by a GET route) and is what the per-route
        // middleware lookup keys on; `$request['method']` stays 'HEAD' so every
        // middleware sees the real (safe, read-only) method.
        $match = $this->matchRoute($method, $path);
        if ($match === null && $method === 'HEAD') {
            $match = $this->matchRoute('GET', $path);
        }
        $allowedMethods = $match === null ? $this->allowedMethodsFor($path) : [];

        // Build request context — includes route metadata for middleware
        $request = [
            'method'  => $method,
            'path'    => $path,
            'input'   => $incoming !== null ? $this->resolveIncomingInput($incoming, $method) : $this->getInput(),
            'params'  => $match['params'] ?? [],
            'headers' => $incoming?->headers ?? $this->getHeaders(),
            'cookies' => $incoming?->cookies ?? $_COOKIE,
            // Methods registered for this path when no route matched the request method
            // (drives 405 + Allow and the OPTIONS Allow header). Empty when matched / unknown path.
            'allowed_methods' => $allowedMethods,
            // null when route not found — middleware passes through, closure returns 404
            'route'   => $match ? [
                'pattern'       => $match['pattern'],
                'is_public'     => $match['is_public'] ?? false,
                'access'        => $match['access'] ?? null,
                'token_type'    => $match['token_type'] ?? 'access',
                'service'       => $match['service'] ?? 'shared',
                'handler_class' => is_object($match['handler']) ? get_class($match['handler']) : $match['handler'],
                'request'       => $match['request'] ?? null,
                'streaming'     => $match['streaming'] ?? false,
                'head'          => $match['head'] ?? null,
            ] : null,
        ];

        // Process through global middleware pipeline.
        // Closure captures $match from outer scope — avoids double-matching.
        $response = $this->globalMiddleware->process($request, function($request) use ($method, $match, $allowedMethods) {
            if (!$match) {
                if ($allowedMethods !== []) {
                    return $method === 'OPTIONS'
                        ? $this->optionsResponse($allowedMethods)
                        : $this->error405($allowedMethods);
                }
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
            $routeKey = ($match['method'] ?? $method) . ':' . $match['pattern'];
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

        // A failed write must never ship as 2xx (see Persistence\ResponseGuard).
        $response = \StoneScriptPHP\Persistence\ResponseGuard::apply($response);
        \StoneScriptPHP\Persistence\PersistenceLedger::reset();
        return $response;
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
                    'access'     => $this->routeMeta[$routeKey]['access'] ?? null,
                    'token_type' => $this->routeMeta[$routeKey]['token_type'] ?? 'access',
                    'service'   => $this->routeMeta[$routeKey]['service'] ?? 'shared',
                    'request'   => $this->routeMeta[$routeKey]['request'] ?? null,
                    'streaming' => $this->routeMeta[$routeKey]['streaming'] ?? false,
                    'head'      => $this->routeMeta[$routeKey]['head'] ?? null,
                    'method'    => $method,
                ];
            }

            // Pattern match (with parameters like /users/{id})
            $regex = $this->regexCache[$pattern] ??= $this->buildRegex($pattern);
            if (preg_match($regex, $path, $matches)) {
                array_shift($matches); // Remove full match
                $params = $this->extractParams($pattern, $matches);

                return [
                    'handler'   => $handler,
                    'params'    => $params,
                    'pattern'   => $pattern,
                    'is_public' => $this->routeMeta[$routeKey]['is_public'] ?? false,
                    'access'     => $this->routeMeta[$routeKey]['access'] ?? null,
                    'token_type' => $this->routeMeta[$routeKey]['token_type'] ?? 'access',
                    'service'   => $this->routeMeta[$routeKey]['service'] ?? 'shared',
                    'request'   => $this->routeMeta[$routeKey]['request'] ?? null,
                    'streaming' => $this->routeMeta[$routeKey]['streaming'] ?? false,
                    'head'      => $this->routeMeta[$routeKey]['head'] ?? null,
                    'method'    => $method,
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
        // generator (the client SDK contract), which emits {curly} placeholders.
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
        // HEAD must be SAFE BY CONSTRUCTION: answer as a probe (200, headers only, handler NOT run) when the
        // route says `head: 'probe'`, is a streaming route, has a streaming-type handler, or the global
        // default `HEAD_EXECUTES_GET=false` is in force (and the route did not opt back in with 'execute').
        if (($request['method'] ?? '') === 'HEAD' && $this->headIsProbe($handlerClass, $request)) {
            $probe = new ApiResponse('ok', '', null, 200);
            $probe->headProbe = true;
            if (($request['route']['streaming'] ?? false) || $this->isStreamingHandler($handlerClass)) {
                $probe->headers = ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache'];
            }
            return $probe;
        }

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

            // Merge input and params
            $allInput = array_merge($request['input'] ?? [], $request['params'] ?? []);

            // Typed-request-binder path. A handler
            // opting into this pattern implements ONLY ITypedRouteHandler + a
            // single `execute(FooRequest $request): FooResponse` method — no
            // process(), no validation_rules(), no public ?array properties.
            // Checked BEFORE the legacy IRouteHandler check below since a typed
            // handler is not required to implement IRouteHandler at all.
            if ($handler instanceof \StoneScriptPHP\ITypedRouteHandler) {
                $response = $this->executeTypedHandler($handler, $handlerClass, $allInput, $request);
                \StoneScriptPHP\Audit\AuditRecorder::record($request, $handler, $response);
                return $response;
            }

            // Check if handler implements IRouteHandler interface
            if (!($handler instanceof \StoneScriptPHP\IRouteHandler)) {
                log_debug("Handler does not implement IRouteHandler: $handlerClass");
                return $this->error404('Handler not implemented correctly');
            }

            // Honor the handler's declared validation_rules() at the edge. Without
            // this, declared required-field rules were dead code under the new
            // router: missing/invalid input reached the handler (and SQL functions)
            // as NULL, surfacing as a 500 instead of a clean 400.
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

            /** @var array<int, array{line: ?int, field: string, message: string}> $bindingErrors */
            $bindingErrors = [];

            foreach ($properties as $property) {
                $propertyName = $property->getName();
                if (array_key_exists($propertyName, $allInput)) {
                    $value = $allInput[$propertyName];
                    // Coerce string values to match typed property declarations
                    if ($value !== null && $property->hasType()) {
                        $type = $property->getType();
                        $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;
                        $isBuiltin = $type instanceof \ReflectionNamedType && $type->isBuiltin();

                        if ($typeName === 'int' && is_string($value) && is_numeric($value)) {
                            $value = (int) $value;
                        } elseif ($typeName === 'float' && is_string($value) && is_numeric($value)) {
                            $value = (float) $value;
                        } elseif ($typeName === 'bool' && is_string($value)) {
                            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $value;
                        } elseif ($typeName !== null && !$isBuiltin && class_exists($typeName)) {
                            // Property typed as a userland DTO class — previously this
                            // raw-assigned the decoded array straight onto a typed-object
                            // property, throwing an uncaught TypeError under
                            // strict_types on every real request (regression #7399).
                            // Hydrate it recursively instead, same engine the typed
                            // ITypedRouteHandler path uses below, collecting errors so
                            // a shape problem is a clean 400, never a 500.
                            try {
                                $value = \StoneScriptPHP\Binding\DtoHydrator::hydrate($typeName, $value, $propertyName);
                            } catch (\StoneScriptPHP\Binding\BindingException $e) {
                                $bindingErrors = [...$bindingErrors, ...$e->errors()];
                                continue;
                            }
                        }
                    }
                    $handler->$propertyName = $value;
                }
            }

            if ($bindingErrors !== []) {
                log_debug('Binding failed: ' . json_encode($bindingErrors));
                http_response_code(400);
                return new ApiResponse('error', 'Validation failed', null, 400, $bindingErrors);
            }

            // Execute handler
            $response = $handler->process();

            if (!($response instanceof ApiResponse)) {
                log_debug('Handler did not return ApiResponse');
                return new ApiResponse('error', 'Invalid handler response');
            }

            \StoneScriptPHP\Audit\AuditRecorder::record($request, $handler, $response);

            return $response;

        } catch (TenantDatabaseUnavailableException $e) {
            log_error('Tenant database unavailable: ' . \StoneScriptPHP\Persistence\LogSanitizer::describe($e));
            http_response_code(401);
            return new ApiResponse('error', 'Unauthorized: session references an unavailable tenant. Please sign in again.');
        } catch (\StoneScriptPHP\Persistence\PersistenceException $e) {
            // A write that did not persist: classified safe message (or explicit public business error) + the real 4xx/5xx.
            return \StoneScriptPHP\Persistence\DbErrorMapper::toResponse($e);
        } catch (\Exception $e) {
            $dbOrigin = \StoneScriptPHP\Persistence\DbErrorMapper::isDatabaseOrigin($e);
            // Raw database text may embed customer values: only ever log/show the sanitised form.
            log_debug('Exception in handler: ' . \StoneScriptPHP\Persistence\LogSanitizer::describe($e));
            $coded = $e instanceof \RuntimeException && $e->getCode() >= 400 && $e->getCode() < 600;
            if ($dbOrigin || $coded) {
                $mapped = \StoneScriptPHP\Persistence\DbErrorMapper::resolve($e);
                if (\StoneScriptPHP\Persistence\PersistenceContract::enforced()) {
                    return \StoneScriptPHP\Persistence\DbErrorMapper::toResponse($e);
                }
                if ($mapped->status !== 500) {
                    \StoneScriptPHP\Persistence\PersistenceContract::wouldEnforce(
                        'db-error-mapping',
                        "an unhandled database error answered HTTP 500 but classifies as HTTP {$mapped->status}."
                    );
                }
            }
            // A response carries only a PublicError message, a message deliberately marked public (PublicMessage), or
            // a generic sentence plus a correlation id. Raw exception text never reaches a client, in any mode.
            return $this->genericFailure($e, 500, $dbOrigin ? 'Database error (details are in the server log)' : null, false);
        }
    }

    /**
     * Dispatch a {@see \StoneScriptPHP\ITypedRouteHandler} route — hydrate its
     * `execute()` method's single request-DTO parameter, call it, wrap the
     * returned response DTO into the standard ApiResponse envelope.
     *
     * @param array<array-key, mixed> $allInput
     * @param array<string, mixed> $request
     */
    private function executeTypedHandler(object $handler, string $handlerClass, array $allInput, array $request): ApiResponse
    {
        $methodRef = new \ReflectionMethod($handler, 'execute');
        $params = $methodRef->getParameters();

        if (count($params) !== 1) {
            throw new \LogicException("$handlerClass::execute() must declare exactly one parameter (the request DTO).");
        }

        $paramType = $params[0]->getType();
        if (!($paramType instanceof \ReflectionNamedType) || $paramType->isBuiltin()) {
            throw new \LogicException("$handlerClass::execute() parameter must be typed as a concrete request DTO class.");
        }
        $requestClass = $paramType->getName();

        // Enforce that the class the binder hydrates is the SAME class
        // cli/generate-client.php reflects for the TS `request:` interface —
        // the runtime-side counterpart to the build-time --strict-types gate.
        // Only checked when the route declares `request:` in routes.php (it
        // may be legitimately absent on a route excluded from client
        // generation, e.g. service: 'infra'/'webhook').
        $declaredRequestClass = $request['route']['request'] ?? null;
        if ($declaredRequestClass !== null && ltrim((string) $declaredRequestClass, '\\') !== ltrim($requestClass, '\\')) {
            throw new \LogicException(
                "$handlerClass::execute() parameter type ($requestClass) does not match the route's declared " .
                "request: {$declaredRequestClass} — these must be the same class so the generated TS client and " .
                'the runtime binder never drift apart.'
            );
        }

        try {
            $requestDto = \StoneScriptPHP\Binding\DtoHydrator::hydrate($requestClass, $allInput);
        } catch (\StoneScriptPHP\Binding\BindingException $e) {
            log_debug('Binding failed: ' . json_encode($e->errors()));
            http_response_code(400);
            return new ApiResponse('error', 'Validation failed', null, 400, $e->errors());
        }

        try {
            /** @var object $responseDto */
            $responseDto = $handler->execute($requestDto);
        } catch (\StoneScriptPHP\Binding\BindingException $e) {
            // A handler's execute() may throw this for a structured
            // BUSINESS-rule rejection (not a shape/type problem) that wants
            // the same {line,field,message}[] wire shape as a hydration
            // failure — e.g. a 409 duplicate-key conflict.
            log_debug('Structured business validation failed: ' . json_encode($e->errors()));
            http_response_code($e->httpCode());
            return new ApiResponse('error', 'Validation failed', null, $e->httpCode(), $e->errors());
        } catch (\StoneScriptPHP\Persistence\PersistenceException $e) {
            // keeps error_code / fields / errors and the not-found upgrade; never raw database text
            return \StoneScriptPHP\Persistence\DbErrorMapper::toResponse($e);
        } catch (\RuntimeException $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
            if ($e instanceof \StoneScriptPHP\Exceptions\PublicMessage) {
                log_debug('Public RuntimeException in typed handler: ' . \StoneScriptPHP\Persistence\LogSanitizer::describe($e));
                http_response_code($code);
                return new ApiResponse('error', $e->getMessage(), null, $code);
            }
            return $this->genericFailure($e, $code);
        }

        // Convention: a response DTO's `message` property (if it declares
        // one) is promoted to ApiResponse's top-level `message` field and
        // excluded from `data`, matching the wire shape every hand-written
        // `process()`/`res_ok($data, $message)` route already produced
        // (`data` = the business payload; `message` = a separate top-level
        // human-readable string) — see an order-submit response DTO
        // for a real example.
        $data = self::dtoToArray($responseDto);
        $message = '';
        if (is_array($data) && array_key_exists('message', $data) && is_string($data['message'])) {
            $message = $data['message'];
            unset($data['message']);
        }

        return new ApiResponse('ok', $message, $data);
    }

    /**
     * Recursively converts a response DTO (and any nested DTOs/backed enums)
     * into a plain array for JSON encoding via ApiResponse.
     *
     * @return mixed
     */
    private static function dtoToArray(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if (is_object($value)) {
            $out = [];
            foreach (get_object_vars($value) as $key => $v) {
                $out[$key] = self::dtoToArray($v);
            }
            return $out;
        }
        if (is_array($value)) {
            return array_map([self::class, 'dtoToArray'], $value);
        }
        return $value;
    }

    /**
     * Resolve the 'input' array for an injected IncomingRequest, mirroring
     * getInput()'s per-method branching (query for GET-like methods, body for
     * mutating methods) but sourced from the object instead of superglobals.
     *
     * @param IncomingRequest $incoming
     * @param string $method Already-uppercased method (from dispatch())
     * @return array
     */
    private function resolveIncomingInput(IncomingRequest $incoming, string $method): array
    {
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            return $incoming->body ?? [];
        }

        // GET, DELETE, and anything else fall back to query params — matching
        // getInput()'s "everything not POST/PUT/PATCH that isn't GET returns
        // []" behavior would be a silent regression for injected GET-like
        // requests, so we deliberately serve query params for any non-body
        // method rather than mimicking that fallback-to-empty branch.
        return $incoming->query;
    }

    /**
     * Get input based on request method
     *
     * @return array
     */
    private function getInput(): array
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method === 'GET' || $method === 'HEAD') {
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

    /** Global default for HEAD on GET routes: true = run the GET handler and drop the body. */
    private bool $headExecutesGet = true;

    /** @var array<string, string> compiled route regexes, by pattern */
    private array $regexCache = [];

    public function setHeadExecutesGet(bool $executes): self
    {
        $this->headExecutesGet = $executes;
        return $this;
    }

    private function headIsProbe(string|object $handler, array $request): bool
    {
        $route = $request['route'] ?? [];
        if (($route['streaming'] ?? false) || $this->isStreamingHandler($handler)) {
            return true;
        }
        $mode = $route['head'] ?? null;
        if ($mode === 'probe') {
            return true;
        }
        if ($mode === 'execute') {
            return false;
        }
        return !$this->headExecutesGet;
    }

    private function isStreamingHandler(string|object $handler): bool
    {
        return is_object($handler)
            ? $handler instanceof \StoneScriptPHP\IStreamingRouteHandler
            : (class_exists($handler) && is_subclass_of($handler, \StoneScriptPHP\IStreamingRouteHandler::class));
    }

    /**
     * Methods registered for $path (any method whose route pattern matches), plus HEAD
     * when GET is present (served by the GET route) and OPTIONS. Sorted, de-duplicated.
     *
     * @return string[]
     */
    private function allowedMethodsFor(string $path): array
    {
        $allowed = [];
        foreach (array_keys($this->routes) as $registered) {
            if ($this->matchRoute((string) $registered, $path) !== null) {
                $allowed[] = (string) $registered;
            }
        }
        if ($allowed === []) {
            return [];
        }
        if (in_array('GET', $allowed, true)) {
            $allowed[] = 'HEAD';
        }
        $allowed[] = 'OPTIONS';
        $allowed = array_values(array_unique($allowed));
        sort($allowed);
        return $allowed;
    }

    /** 405 Method Not Allowed with the mandatory Allow header (RFC 9110 15.5.6). */
    private function error405(array $allowed): ApiResponse
    {
        http_response_code(405);
        $r = new ApiResponse('error', 'Method not allowed', null, 405);
        $r->headers = ['Allow' => implode(', ', $allowed)];
        return $r;
    }

    /** OPTIONS for a known path that reached the router (no CORS layer answered it first). */
    private function optionsResponse(array $allowed): ApiResponse
    {
        http_response_code(204);
        $r = new ApiResponse('ok', '', null, 204);
        $r->headers = ['Allow' => implode(', ', $allowed)];
        return $r;
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
    /**
     * A failure whose own text must not reach the client: the generic sentence for the status (or an explicit safe
     * `$message`) plus a correlation id; the sanitised detail is logged under that id. Same in every mode.
     */
    private function genericFailure(\Throwable $e, int $status, ?string $message = null, bool $explicitStatus = true): ApiResponse
    {
        $correlationId = bin2hex(random_bytes(6));
        log_error('Request failed [correlation_id=' . $correlationId . ', HTTP ' . $status . ']: ' . \StoneScriptPHP\Persistence\LogSanitizer::describe($e));
        http_response_code($status);
        return new ApiResponse('error', $message ?? \StoneScriptPHP\Persistence\DbErrorMapper::genericMessage($status), ['correlation_id' => $correlationId], $explicitStatus ? $status : null);
    }
}
