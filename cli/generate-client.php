<?php

/**
 * API Client Generator — v4.0
 *
 * Generates per-service TypeScript client packages from PHP routes.
 * Implements CLIENT-SDK-SPEC §0 Amendments A1–A6 (approved 2026-06-14).
 *
 * Key behaviours (v4.0):
 *   - Reads `service` (group-level) and `group`/`action`/`streaming`/`param` (route-level)
 *     from route declarations via $router->group() / $router->get() / $router->post()
 *   - Emits ONE package per distinct non-excluded `service` (A6):
 *       docker/api/client/portal/  → T3 tenant-scoped client with setTenant()
 *       docker/api/client/admin/   → admin client, no setTenant(), no /tenant/{id} URLs
 *   - Routes with service:'infra' or service:'webhook' are EXCLUDED (A3)
 *   - Routes with streaming:true are SKIPPED + listed in a comment block (A1)
 *   - Missing `group` on an includable route = HARD ERROR that aborts generation (A2)
 *   - T3 platforms: URL shape /{service}/tenant/{tenantId}/{group}/{action}[/{id}]
 *   - T2 platforms: no /tenant/{tenantId} segment, no setTenant() (T2/JWT-tenant mode)
 *   - Generated method signature: api.{group}.{action}(id?: string | number, data?)
 *   - Tail path parameter always typed as `id: string | number` regardless of param: name (A5)
 *
 * Usage:
 *   php stone generate client
 *   php stone generate client --tenancy=T3   (default for most platforms)
 *   php stone generate client --tenancy=T2   (T2/JWT-tenant — no URL tenant segment)
 *   php stone generate client --tenancy=T1   (no tenant at all)
 *   php stone generate client --output=client --service=portal  (single package)
 *
 * Migration note:
 *   Routes declared without a `group:` key trigger the hard-error guard (A2).
 *   All routes must use v4.0 `service`/`group` declarations.
 */

// ============================================================================
// Bootstrap
// ============================================================================

if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
if (!defined('SRC_PATH'))  define('SRC_PATH',  ROOT_PATH . 'src' . DIRECTORY_SEPARATOR);

// PSR-4 autoloader for framework + app classes
spl_autoload_register(function ($class) {
    foreach ([ROOT_PATH, SRC_PATH] as $base) {
        $file = $base . str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// ============================================================================
// CLI argument parsing
// ============================================================================

$outputBaseDir  = 'client';      // Emits one subdirectory per service
$tenancyMode    = 'T3';          // T3 | T2 | T1
$serviceFilter  = null;          // null = all services; string = one service only
$language       = 'typescript';  // Only TypeScript supported in v4.0

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--output='))   $outputBaseDir  = substr($arg, 9);
    if (str_starts_with($arg, '--tenancy='))  $tenancyMode    = strtoupper(substr($arg, 10));
    if (str_starts_with($arg, '--service='))  $serviceFilter  = strtolower(substr($arg, 10));
    if (str_starts_with($arg, '--language=')) $language        = strtolower(substr($arg, 11));

    if (in_array($arg, ['--help', '-h', 'help'])) {
        echo <<<HELP
API Client Generator v4.0
=========================

Generates per-service TypeScript client packages (CLIENT-SDK-SPEC §0 A1-A6).

Usage: php stone generate client [options]

Options:
  --output=<dir>      Base output directory (default: client)
                      One subdirectory per service is created inside:
                        client/portal/   client/admin/
  --tenancy=<mode>    T3 (default) | T2 | T1
                      T3: multi-tenant/URL (e.g. a store or logistics platform)
                      T2: tenant in JWT (e.g. an app-builder — no /tenant/{id} segment)
                      T1: no tenant scope
  --service=<name>    Generate only the named service package (default: all)
  --language=<lang>   typescript (only option in v4.0)

Each route MUST declare:
  service: 'portal'    (on the group — partition key)
  group:   'inventory' (on the route — domain concept, mandatory on includable routes)

Excluded services (never emitted):
  service: 'infra'    — health, JWKS, infrastructure probes (A3)
  service: 'webhook'  — inbound payment/job callbacks (A3)
  streaming: true     — SSE/chunked routes (A1); listed in a notice comment

HELP;
        exit(0);
    }
}

if (!in_array($tenancyMode, ['T1', 'T2', 'T3'])) {
    fwrite(STDERR, "Error: --tenancy must be T1, T2, or T3 (got '$tenancyMode').\n");
    exit(1);
}

// Convert to absolute path if relative
if (!str_starts_with($outputBaseDir, '/')) {
    $outputBaseDir = ROOT_PATH . $outputBaseDir;
}

// ============================================================================
// Route loading — uses $router->getRouteMeta() (v4.0 API)
// ============================================================================

/**
 * Load routes by instantiating a Router and requiring the platform's routes.php.
 *
 * Returns the raw route metadata array from Router::getRouteMeta() — each entry has:
 *   method, path, handler, service, group, action, streaming, param, is_public
 *
 * Falls back to the legacy flat-array format if the platform's routes.php returns
 * a raw array (old convention). In that case, routes have no `group` and will trigger
 * the hard-error guard in the generator (intentional — forces migration to v4.0).
 */
function loadRoutesFromPlatform(): array
{
    $routesFile = SRC_PATH . 'config' . DIRECTORY_SEPARATOR . 'routes.php';

    if (!file_exists($routesFile)) {
        fwrite(STDERR, "[stone generate client] ERROR: routes.php not found at $routesFile\n");
        exit(1);
    }

    // Try v4.0 path: routes.php calls $router->group()/get()/post() with a pre-built router
    // injected from the bootstrap, and returns the router OR an array.
    // We inject a fresh router and eval the routes file against it.
    $router = new \StoneScriptPHP\Routing\Router();

    // Inject $router into the routes file scope so it can call $router->group()/get()/post()
    $routesResult = (function() use ($router, $routesFile) {
        return require $routesFile;
    })();

    if ($routesResult instanceof \StoneScriptPHP\Routing\Router) {
        // v4.0: routes.php returned the router directly
        return $router->getRouteMeta();
    }

    if (is_array($routesResult)) {
        // Legacy flat array — load via loadRoutes()
        $router->loadRoutes($routesResult);
        return $router->getRouteMeta();
    }

    // routes.php mutated $router in place (most common v4.0 pattern)
    return $router->getRouteMeta();
}

// ============================================================================
// Route classification
// ============================================================================

/** Services that are unconditionally excluded from all generated packages (A3) */
const EXCLUDED_SERVICES = ['infra', 'webhook'];

/**
 * Determine if a route should be excluded entirely from client generation.
 * Returns the reason string or null (included).
 */
function exclusionReason(array $route): ?string
{
    $service = $route['service'] ?? 'shared';

    // A3: infra / webhook services
    if (in_array(strtolower($service), EXCLUDED_SERVICES)) {
        return "service:$service (excluded)";
    }

    // Legacy /api/internal/ prefix exclusion (pre-v4.0 convention)
    if (str_starts_with($route['path'] ?? '', '/api/internal/')) {
        return 'internal route (/api/internal/ prefix)';
    }

    // Alias routes
    if ($route['alias'] ?? false) {
        return 'alias route';
    }

    return null;
}

/**
 * Validate that an includable route has a declared group.
 * Exits with a hard error message if missing (A2).
 */
function assertGroupDeclared(array $route): void
{
    if (empty($route['group'])) {
        $method  = strtoupper($route['method'] ?? '?');
        $path    = $route['path'] ?? '?';
        $service = $route['service'] ?? '?';
        fwrite(STDERR, <<<ERR

[stone generate client] ERROR: Route $method $path (service:$service) has no `group` declaration.

Add group: '<concept>' to the route definition. Example:

  \$router->get('/items', ListItemsRoute::class, group: 'inventory');

Generation aborted. Every includable route must have a group: declaration (CLIENT-SDK-SPEC §0 A2).
ERR
        );
        exit(1);
    }
}

// ============================================================================
// Naming helpers
// ============================================================================

/**
 * Convert a kebab-case or snake_case string to camelCase.
 * 'assign-driver' → 'assignDriver', 'sales_daily' → 'salesDaily'
 */
function toCamelCase(string $str): string
{
    $str = str_replace(['_', '-'], ' ', $str);
    $str = ucwords($str);
    return lcfirst(str_replace(' ', '', $str));
}

/**
 * Derive the action name from a route path.
 *
 * Algorithm (A2):
 *   1. Strip the service prefix segment (first segment).
 *   2. Strip the /tenant/{tenantId} segment if present (T3).
 *   3. Strip the first remaining STATIC segment (= URL resource base, e.g. 'items', 'bills').
 *      This segment corresponds to the route group's URL root but is NOT required to
 *      equal the group name (group:'inventory' maps to URL segment 'items').
 *   4. Collect remaining segments: param segments ('{id}', ':id') and action segments (static).
 *   5. If there are action segments → camelCase join (e.g. 'daily-summary' → 'dailySummary').
 *   6. If no action segments → derive from HTTP method + :id presence:
 *        GET  + no :id  → 'list'
 *        GET  + :id     → 'get'
 *        POST + no :id  → 'create'
 *        POST + :id     → 'update'
 *   7. The explicit `action:` declaration on the route wins — generator reads it before calling here.
 *   8. Tail :id type is always string|number regardless of param: name (A5).
 *
 * @param string $path   Full route path as registered (e.g. '/portal/tenant/{tenantId}/items/{id}')
 * @param string $method HTTP method (GET or POST)
 * @param string $group  Declared group name (used only for fallback derivation context — not for path stripping)
 */
function deriveAction(string $path, string $method, string $group): string
{
    $parts = array_values(array_filter(explode('/', $path), fn($p) => $p !== ''));

    // 1. Remove service segment (first static segment: 'portal', 'admin', 'api', …)
    if (!empty($parts)) array_shift($parts);

    // 2. Remove /tenant/{tenantId} if present
    if (!empty($parts) && $parts[0] === 'tenant') {
        array_shift($parts); // 'tenant' keyword
        if (!empty($parts)) array_shift($parts); // '{tenantId}' or ':tenantId'
    }

    // 3. Remove first remaining STATIC segment (URL resource base: 'items', 'bills', 'routes', …)
    //    Skip param segments at the front (shouldn't happen at this position per spec, but defensive)
    if (!empty($parts) && !preg_match('/^\{.+\}$/', $parts[0]) && !preg_match('/^:/', $parts[0])) {
        array_shift($parts);
    }

    // 4. Partition remaining into param vs. action segments
    $paramParts  = [];
    $actionParts = [];
    foreach ($parts as $part) {
        if (preg_match('/^\{.+\}$/', $part) || preg_match('/^:/', $part)) {
            $paramParts[] = $part;
        } else {
            $actionParts[] = $part;
        }
    }

    $hasTailId  = !empty($paramParts);
    $httpMethod = strtoupper($method);

    // 5. Action segments present → camelCase
    if (!empty($actionParts)) {
        return toCamelCase(implode('-', $actionParts));
    }

    // 6. No action segments → derive from HTTP method + id presence
    return match(true) {
        $httpMethod === 'GET'  && !$hasTailId => 'list',
        $httpMethod === 'GET'  &&  $hasTailId => 'get',
        $httpMethod === 'POST' && !$hasTailId => 'create',
        $httpMethod === 'POST' &&  $hasTailId => 'update',
        default                               => toCamelCase($httpMethod),
    };
}

/**
 * Check whether a route path has a tail :id / {id} parameter.
 */
function hasTailId(string $path): bool
{
    $parts = explode('/', rtrim($path, '/'));
    $last  = end($parts);
    return preg_match('/^\{.+\}$/', $last) || preg_match('/^:/', $last);
}

// ============================================================================
// TypeScript verbatim file contents (emitted as-is per spec)
// ============================================================================

function verbatimHttpTs(): string
{
    return <<<'TS'
// src/http.ts — emitted verbatim by php stone generate client (CLIENT-SDK-SPEC §5)
// DO NOT EDIT MANUALLY.

import { TokenStore } from './tokens';
import { ApiError }   from './errors';

export interface HttpParams {
  [key: string]: string | number | boolean | null | undefined;
}

export class MinimalHttp {
  constructor(
    private readonly baseUrl: string,
    private readonly tokens: TokenStore,
    // Refresh endpoint pinned to AUTH-SPEC §4a: POST /api/auth/refresh.
    // Do not change without updating AUTH-SPEC §token-contract.
    private readonly refreshEndpoint: string = '/api/auth/refresh',
  ) {}

  async get<T = unknown>(path: string, params?: HttpParams): Promise<T> {
    return this.request<T>('GET', path, undefined, params);
  }

  async post<T = unknown>(path: string, body?: unknown): Promise<T> {
    return this.request<T>('POST', path, body);
  }

  private async request<T>(
    method: 'GET' | 'POST',
    path: string,
    body?: unknown,
    params?: HttpParams,
    isRetry = false,
  ): Promise<T> {
    const url = new URL(this.baseUrl + path);
    if (params) {
      for (const [k, v] of Object.entries(params)) {
        if (v !== undefined && v !== null) {
          url.searchParams.set(k, String(v));
        }
      }
    }

    const headers: Record<string, string> = { 'Content-Type': 'application/json' };
    const token = this.tokens.get();
    if (token) headers['Authorization'] = `Bearer ${token}`;

    let res: Response;
    try {
      res = await fetch(url.toString(), {
        method,
        headers,
        body: body !== undefined ? JSON.stringify(body) : undefined,
      });
    } catch (networkErr) {
      throw new ApiError('Network error — check your connection', 0, networkErr, null);
    }

    if (res.status === 401 && !isRetry) {
      const refreshed = await this.attemptRefresh();
      if (refreshed) {
        return this.request<T>(method, path, body, params, true);
      }
      this.tokens.clear();
      throw new ApiError('Session expired. Please log in again.', 401, null, null);
    }

    let data: unknown;
    try {
      data = await res.json();
    } catch {
      throw new ApiError(
        `Server returned non-JSON response (HTTP ${res.status})`,
        res.status,
        null,
        null,
      );
    }

    const envelope = data as Record<string, unknown>;
    if (!envelope || envelope['status'] !== 'ok') {
      const message = (envelope?.['message'] as string) ?? 'Request failed';
      const code    = (envelope?.['data'] as Record<string, unknown>)?.['error'] as string ?? null;
      throw new ApiError(message, res.status, envelope, code);
    }

    return envelope['data'] as T;
  }

  private async attemptRefresh(): Promise<boolean> {
    const refresh = this.tokens.getRefresh();
    if (!refresh) return false;

    let res: Response;
    try {
      res = await fetch(this.baseUrl + this.refreshEndpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ refresh_token: refresh }),
      });
    } catch {
      return false;
    }

    if (!res.ok) return false;

    let data: unknown;
    try { data = await res.json(); } catch { return false; }

    const envelope = data as Record<string, unknown>;
    const newAccess  = envelope?.['data'] !== undefined
      ? (envelope['data'] as Record<string, unknown>)?.['access_token'] as string | undefined
      : envelope?.['access_token'] as string | undefined;

    if (newAccess) {
      this.tokens.set(newAccess);
      const newRefresh = (envelope?.['data'] as Record<string, unknown>)?.['refresh_token']
        ?? envelope?.['refresh_token'];
      if (typeof newRefresh === 'string') this.tokens.setRefresh(newRefresh);
      return true;
    }

    return false;
  }
}
TS;
}

function verbatimTokensTs(): string
{
    return <<<'TS'
// src/tokens.ts — emitted verbatim by php stone generate client (CLIENT-SDK-SPEC §6)
// DO NOT EDIT MANUALLY.

// Key names are owned by AUTH-SPEC §token-contract. Do not rename.
const ACCESS_KEY  = 'ssp_access_token';
const REFRESH_KEY = 'ssp_refresh_token';

export class TokenStore {
  get(): string | null {
    return typeof localStorage !== 'undefined'
      ? localStorage.getItem(ACCESS_KEY)
      : null;
  }

  set(token: string): void {
    if (typeof localStorage !== 'undefined') {
      localStorage.setItem(ACCESS_KEY, token);
    }
  }

  getRefresh(): string | null {
    return typeof localStorage !== 'undefined'
      ? localStorage.getItem(REFRESH_KEY)
      : null;
  }

  setRefresh(token: string): void {
    if (typeof localStorage !== 'undefined') {
      localStorage.setItem(REFRESH_KEY, token);
    }
  }

  clear(): void {
    if (typeof localStorage !== 'undefined') {
      localStorage.removeItem(ACCESS_KEY);
      localStorage.removeItem(REFRESH_KEY);
    }
  }

  hasToken(): boolean {
    return !!this.get();
  }
}
TS;
}

function verbatimErrorsTs(): string
{
    return <<<'TS'
// src/errors.ts — emitted verbatim by php stone generate client (CLIENT-SDK-SPEC §11)
// DO NOT EDIT MANUALLY.

export class ApiError extends Error {
  constructor(
    message: string,
    public readonly httpStatus: number,
    public readonly response: unknown,
    public readonly code: string | null,
  ) {
    super(message);
    this.name = 'ApiError';
  }
}
TS;
}

// ============================================================================
// Client TypeScript generation
// ============================================================================

/**
 * Generate the ApiClient TypeScript source for one service package.
 *
 * @param string   $serviceName     e.g. 'portal', 'admin'
 * @param array    $serviceRoutes   Routes with service === $serviceName (already filtered)
 * @param array    $streamingRoutes Routes with streaming:true that were skipped
 * @param string   $tenancyMode     'T3' | 'T2' | 'T1'
 * @param bool     $isAdminService  true when service === 'admin' (omit setTenant)
 * @return string  TypeScript source for src/client.ts
 */
function generateClientTs(
    string $serviceName,
    array  $serviceRoutes,
    array  $streamingRoutes,
    string $tenancyMode,
    bool   $isAdminService,
): string {
    // Determine if this service produces a tenant-scoped client
    $isTenantScoped = $tenancyMode === 'T3' && !$isAdminService;

    // Group routes by declared group
    $groups = [];
    foreach ($serviceRoutes as $route) {
        $group = $route['group'];
        if (!isset($groups[$group])) {
            $groups[$group] = [];
        }
        $groups[$group][] = $route;
    }

    // Build streaming notice comment (A1)
    $streamingNotice = '';
    if (!empty($streamingRoutes)) {
        $lines = ["  // ─────────────────────────────────────────────────────────────────────",
                  "  // STREAMING ROUTES — excluded from this generated client (A1)",
                  "  // These routes use text/event-stream or chunked transfer and cannot be",
                  "  // modelled as Promise-returning methods. Consume them via EventSource or",
                  "  // fetch(ReadableStream) in a hand-written helper placed at:",
                  "  //   docker/api/client/{$serviceName}/streaming/",
                  "  //"];
        foreach ($streamingRoutes as $sr) {
            $lines[] = "  //   {$sr['method']} {$sr['path']}";
        }
        $lines[] = "  // ─────────────────────────────────────────────────────────────────────";
        $streamingNotice = "\n" . implode("\n", $lines) . "\n";
    }

    // Build group method blocks
    $groupBlocks = '';
    foreach ($groups as $groupName => $routes) {
        $camelGroup = toCamelCase($groupName);
        $methods    = buildGroupMethods($groupName, $routes, $isTenantScoped, $serviceName);
        $groupBlocks .= <<<TS

  // ─────────────────────────────────────────────────────────────
  // {$groupName}
  // ─────────────────────────────────────────────────────────────
  readonly {$camelGroup} = {
{$methods}  };

TS;
    }

    // Build setTenant / tenant accessor (T3 only, non-admin services)
    $tenantCode = '';
    if ($isTenantScoped) {
        // Use concatenation to avoid PHP variable interpolation conflicts inside heredoc
        // The TypeScript template literal `/${service}/tenant/${this._tenantId}` must be
        // emitted literally — PHP would try to expand ${this._tenantId} otherwise.
        // Single-quote to prevent PHP from interpolating ${this._tenantId}
        $tsReturnLine = '    return `/' . $serviceName . '/tenant/${this._tenantId}`;';
        $tenantCode = "\n"
            . "  /**\n"
            . "   * Set the active tenant context (T3 platforms only).\n"
            . "   * Call once after the user selects a tenant. Re-callable for tenant switching.\n"
            . "   * All tenant-scoped calls use this tenantId in the URL path silently.\n"
            . "   * Single-active-context: this instance tracks one tenant at a time.\n"
            . "   * For simultaneous multi-tenant access create separate ApiClient instances.\n"
            . "   */\n"
            . "  setTenant(id: string | number): this {\n"
            . "    this._tenantId = id;\n"
            . "    return this;\n"
            . "  }\n"
            . "\n"
            . "  private get t(): string {\n"
            . "    if (this._tenantId === null) {\n"
            . "      throw new Error(\n"
            . "        '[ApiClient] Tenant context not set. Call setTenant(id) after tenant selection.',\n"
            . "      );\n"
            . "    }\n"
            . $tsReturnLine . "\n"
            . "  }\n\n";
    }

    $tenantIdField = $isTenantScoped
        ? "\n  private _tenantId: string | number | null = null;"
        : '';

    $tokensExport = '  /** Exposed so ngx wrapper and streaming helpers can read auth state. */' . "\n" .
                    '  readonly tokens: TokenStore;';

    $ts = <<<TS
/**
 * Auto-generated TypeScript API Client — {$serviceName} service
 * Tenancy mode: {$tenancyMode}
 *
 * DO NOT EDIT MANUALLY — Regenerate with: php stone generate client
 * CLIENT-SDK-SPEC §0 A1–A6 (approved 2026-06-14)
 */

import { MinimalHttp, HttpParams } from './http';
import { TokenStore }              from './tokens';
import * as T                      from './types';

// NOTE: this client is intentionally ZERO-dependency and self-contained. It
// structurally satisfies the shared IApiClient contract so the ngx wrapper can
// accept it under the API_CLIENT token — that compatibility is checked at the
// platform provide-site, NOT via `implements` here (importing the interface
// would break self-containment / the zero-dep invariant).
export class ApiClient {{$tenantIdField}
{$tokensExport}
  private readonly http: MinimalHttp;

  constructor(baseUrl: string) {
    this.tokens = new TokenStore();
    this.http   = new MinimalHttp(baseUrl, this.tokens);
  }

  /**
   * ⚠️ INFRA-PROBE ESCAPE HATCH ONLY (IApiClient / CLIENT-SDK-SPEC §433). ⚠️
   * Low-level GET passthrough for cross-cutting infrastructure probes with no
   * typed business method (e.g. subscriptionGuard, health probes). Bypasses the
   * tenant base — pass a full path. NEVER call from business/feature code:
   * use the typed api.<group>.<action>() methods. Reaching for this in a
   * component/feature service means a route is missing its group:/action:
   * declaration — fix that instead (this is the §433 dead-weight guard).
   */
  get<R = unknown>(path: string, params?: HttpParams): Promise<R> {
    return this.http.get<R>(path, params);
  }

  /** ⚠️ INFRA-PROBE ESCAPE HATCH ONLY — see {@link ApiClient.get}. */
  post<R = unknown>(path: string, body?: unknown): Promise<R> {
    return this.http.post<R>(path, body);
  }
{$tenantCode}{$streamingNotice}{$groupBlocks}}
TS;

    return $ts;
}

/**
 * Build the method entries for one group object.
 *
 * @param string $groupName     e.g. 'inventory'
 * @param array  $routes        All routes in this group (for this service)
 * @param bool   $isTenantScoped True when T3 non-admin service
 * @param string $serviceName   e.g. 'portal'
 * @return string TypeScript source lines for the group object body
 */
function buildGroupMethods(
    string $groupName,
    array  $routes,
    bool   $isTenantScoped,
    string $serviceName,
): string {
    $lines = [];

    // Deduplication: track action names already emitted in this group
    $emittedActions = [];

    foreach ($routes as $route) {
        $path       = $route['path'];
        $method     = strtoupper($route['method']);
        $actionDecl = $route['action'] ?? null;

        // Derive action name
        $action = $actionDecl !== null
            ? toCamelCase($actionDecl)
            : deriveAction($path, $method, $groupName);

        // Deduplicate: append numeric suffix if action already emitted
        if (in_array($action, $emittedActions)) {
            $suffix = 2;
            while (in_array($action . $suffix, $emittedActions)) {
                $suffix++;
            }
            $action .= $suffix;
        }
        $emittedActions[] = $action;

        // Build TypeScript URL template
        $urlTemplate = buildUrlTemplate($path, $isTenantScoped, $serviceName);

        // Determine if there's a tail id param (A5: always typed as string | number)
        $tailId = hasTailId($path);

        // Build method signature and call
        $methodTs = buildMethodTs($action, $method, $urlTemplate, $tailId);
        $lines[] = $methodTs;
    }

    return implode("\n", $lines) . "\n";
}

/**
 * Build the TypeScript URL template string for a route path.
 *
 * T3 non-admin: `${this.t}/items` (this.t = /{service}/tenant/{id})
 * T3 admin:     `/{service}/{group}/{action}` (no tenant segment)
 * T2/T1:        `/{service}/{group}/{action}` (no tenant segment)
 * Routes without any tenant segment: raw path
 *
 * @param string $path           Raw route path from routes.php
 * @param bool   $isTenantScoped T3 non-admin
 * @param string $serviceName    Service name (portal, admin, …)
 * @return string TypeScript expression (template literal or quoted string)
 */
function buildUrlTemplate(string $path, bool $isTenantScoped, string $serviceName): string
{
    if ($isTenantScoped) {
        // Strip leading /{service}/tenant/{tenantId} — replaced by ${this.t}
        $stripped = preg_replace(
            '#^/' . preg_quote($serviceName, '#') . '/tenant/\{[^}]+\}#',
            '',
            $path
        );

        if ($stripped === null) {
            $stripped = $path;
        }

        // Replace remaining {param} placeholders with ${id} (A5: always 'id')
        $tpl = preg_replace('/\{[^}]+\}/', '${id}', $stripped ?? $path);

        // Does the template still have interpolations?
        if (str_contains($tpl, '${')) {
            return '`${this.t}' . $tpl . '`';
        } else {
            return '`${this.t}' . $tpl . '`';
        }
    } else {
        // T2, T1, or admin service.
        // Strip /tenant/{param} segment if present (T2 tenancy is implicit in JWT,
        // so the URL does not include a /tenant/{id} path component).
        $stripped = preg_replace('#/tenant/\{[^}]+\}#', '', $path);
        $stripped = $stripped ?? $path;

        // Replace remaining {param} placeholders with ${id} (A5: always 'id')
        $tpl = preg_replace('/\{[^}]+\}/', '${id}', $stripped);
        if (str_contains($tpl, '${')) {
            return '`' . $tpl . '`';
        } else {
            return "'" . $stripped . "'";
        }
    }
}

/**
 * Build a single TypeScript method entry for a group object.
 *
 * Reads → GET, writes → POST. Tail id param always typed as string | number (A5).
 * POST methods without :id take a data parameter; with :id take (id, data).
 * GET methods without :id take optional HttpParams; with :id take (id, params?).
 *
 * @return string TypeScript source for one method entry (including trailing comma)
 */
function buildMethodTs(
    string $action,
    string $httpMethod,
    string $urlTemplate,
    bool   $tailId,
): string {
    $isGet  = $httpMethod === 'GET';
    $isPost = $httpMethod === 'POST';

    if ($isGet) {
        if ($tailId) {
            // GET with :id — e.g. inventory.get(id)
            return <<<TS
    {$action}: (id: string | number) =>
      this.http.get<T.ApiResponse>({$urlTemplate}),
TS;
        } else {
            // GET list / search / action — e.g. inventory.list(params?)
            return <<<TS
    {$action}: (params?: HttpParams) =>
      this.http.get<T.ApiResponse>({$urlTemplate}, params),
TS;
        }
    }

    if ($isPost) {
        if ($tailId) {
            // POST with :id — e.g. inventory.update(id, data?)
            return <<<TS
    {$action}: (id: string | number, data?: T.ApiRequestBody) =>
      this.http.post<T.ApiResponse>({$urlTemplate}, data),
TS;
        } else {
            // POST without :id — e.g. inventory.create(data?)
            return <<<TS
    {$action}: (data?: T.ApiRequestBody) =>
      this.http.post<T.ApiResponse>({$urlTemplate}, data),
TS;
        }
    }

    // Fallback (should not occur — spec mandates GET+POST only)
    return <<<TS
    {$action}: (..._args: unknown[]) => Promise.reject(new Error('Unsupported HTTP method: {$httpMethod}')),
TS;
}

/**
 * Generate the types.ts file.
 *
 * In v4.0 the generator emits a minimal set of generic types. Full DTO generation
 * from PHP DTO classes remains available and platforms may extend this file.
 * The generator ensures the baseline types used in client.ts are always present.
 */
function generateTypesTs(): string
{
    return <<<'TS'
/**
 * Auto-generated type definitions
 * DO NOT EDIT MANUALLY — Regenerate with: php stone generate client
 *
 * Platform-specific DTOs are generated from PHP DTO classes.
 * The types below are the minimum baseline required by the generated ApiClient.
 */

/** Generic API response data payload (replace with specific types per endpoint) */
export type ApiResponse = Record<string, unknown> | unknown[] | null;

/** Generic request body type (replace with specific types per endpoint) */
export type ApiRequestBody = Record<string, unknown> | unknown[] | null;

// Add platform-specific interfaces below (or regenerate from PHP DTOs):
// export interface InventoryItem { ... }
TS;
}

/**
 * Generate package.json for a service package.
 */
function generatePackageJson(string $serviceName): string
{
    return json_encode([
        'name'        => "@stonescript/api-client-{$serviceName}",
        'version'     => '0.0.0',
        'description' => "Auto-generated API client for {$serviceName} service — do not edit manually",
        'main'        => 'dist/index.js',
        'types'       => 'dist/index.d.ts',
        'scripts'     => [
            'build' => 'tsc',
        ],
        'dependencies'    => new stdClass(), // empty object — fully self-contained (§13)
        'peerDependencies' => new stdClass(),
        'devDependencies' => [
            'typescript' => '^5.0.0',
        ],
        'files' => ['dist', 'src'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

/**
 * Generate tsconfig.json for a service package.
 */
function generateTsConfig(): string
{
    return json_encode([
        'compilerOptions' => [
            'target'                          => 'ES2020',
            'module'                          => 'ES2020',
            'lib'                             => ['ES2020', 'DOM'],
            'declaration'                     => true,
            'outDir'                          => './dist',
            'rootDir'                         => './src',
            'strict'                          => true,
            'esModuleInterop'                 => true,
            'skipLibCheck'                    => true,
            'forceConsistentCasingInFileNames' => true,
            'moduleResolution'                => 'node',
        ],
        'include' => ['src/**/*'],
        'exclude' => ['node_modules', 'dist'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

/**
 * Generate .gitignore for a service package.
 * dist/ is intentionally NOT ignored (B3 — un-gitignored dist is a CI build-gate).
 */
function generateGitignore(): string
{
    return "node_modules/\n*.log\n.DS_Store\n";
}

/**
 * Generate src/index.ts that re-exports everything.
 */
function generateIndexTs(): string
{
    return <<<'TS'
// Auto-generated index — DO NOT EDIT MANUALLY
export { ApiClient }    from './client';
export { TokenStore }   from './tokens';
export { MinimalHttp }  from './http';
export { ApiError }     from './errors';
export * as T           from './types';
TS;
}

// ============================================================================
// Main execution
// ============================================================================

echo "Scanning routes...\n";
$allRoutes = loadRoutesFromPlatform();
echo "Found " . count($allRoutes) . " route(s)\n";

// Classify routes
$included        = [];  // service → [route, ...]
$skippedReasons  = [];  // path → reason
$streamingByService = []; // service → [streaming route, ...]

foreach ($allRoutes as $route) {
    $service = strtolower($route['service'] ?? 'shared');

    // A3: excluded services
    $reason = exclusionReason($route);
    if ($reason !== null) {
        $skippedReasons[$route['path']] = $reason;
        continue;
    }

    // A1: streaming routes — skip from generation but collect for notice
    if (!empty($route['streaming'])) {
        $streamingByService[$service][] = $route;
        $skippedReasons[$route['path']] = 'streaming:true (A1)';
        continue;
    }

    // A2: hard error if group missing
    assertGroupDeclared($route);

    // Apply service filter if requested
    if ($serviceFilter !== null && $service !== strtolower($serviceFilter)) {
        continue;
    }

    $included[$service][] = $route;
}

if (empty($included)) {
    $filterNote = $serviceFilter !== null ? " for service '$serviceFilter'" : '';
    echo "No includable routes found{$filterNote}. Nothing to generate.\n";
    if (!empty($skippedReasons)) {
        echo "Skipped routes:\n";
        foreach ($skippedReasons as $path => $reason) {
            echo "  - $path: $reason\n";
        }
    }
    exit(0);
}

// Generate one package per service
foreach ($included as $serviceName => $serviceRoutes) {
    $isAdmin       = $serviceName === 'admin';
    $streamingRoutes = $streamingByService[$serviceName] ?? [];

    // Output directory for this package
    $packageDir = $outputBaseDir . DIRECTORY_SEPARATOR . $serviceName;
    $srcDir     = $packageDir . DIRECTORY_SEPARATOR . 'src';

    if (!is_dir($srcDir) && !mkdir($srcDir, 0755, true)) {
        fwrite(STDERR, "Error: Failed to create $srcDir\n");
        exit(1);
    }

    $clientTs = generateClientTs(
        serviceName:    $serviceName,
        serviceRoutes:  $serviceRoutes,
        streamingRoutes: $streamingRoutes,
        tenancyMode:    $tenancyMode,
        isAdminService: $isAdmin,
    );

    // Write package files
    file_put_contents($packageDir . '/package.json',    generatePackageJson($serviceName));
    file_put_contents($packageDir . '/tsconfig.json',   generateTsConfig());
    file_put_contents($packageDir . '/.gitignore',      generateGitignore());
    file_put_contents($srcDir . '/http.ts',             verbatimHttpTs());
    file_put_contents($srcDir . '/tokens.ts',           verbatimTokensTs());
    file_put_contents($srcDir . '/errors.ts',           verbatimErrorsTs());
    file_put_contents($srcDir . '/types.ts',            generateTypesTs());
    file_put_contents($srcDir . '/client.ts',           $clientTs);
    file_put_contents($srcDir . '/index.ts',            generateIndexTs());

    $routeCount     = count($serviceRoutes);
    $streamingCount = count($streamingRoutes);

    echo "✓ Generated package: $packageDir\n";
    echo "  Routes: $routeCount  |  Streaming (skipped): $streamingCount\n";
    $groups = array_unique(array_column($serviceRoutes, 'group'));
    sort($groups);
    echo "  Groups: " . implode(', ', $groups) . "\n";
    if (!empty($streamingRoutes)) {
        echo "  Streaming skipped:\n";
        foreach ($streamingRoutes as $sr) {
            echo "    - {$sr['method']} {$sr['path']}\n";
        }
    }
    echo "\n";
}

// Summary of all skipped routes
$totalSkipped = count($skippedReasons);
if ($totalSkipped > 0) {
    echo "Skipped $totalSkipped route(s):\n";
    $reasonCounts = [];
    foreach ($skippedReasons as $reason) {
        $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
    }
    foreach ($reasonCounts as $reason => $count) {
        echo "  $count × $reason\n";
    }
}
