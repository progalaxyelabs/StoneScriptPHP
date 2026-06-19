<?php

/**
 * API Client Generator — v4.3
 *
 * Generates per-service TypeScript client packages from PHP routes.
 * Implements CLIENT-SDK-SPEC §0 Amendments A1–A6 (approved 2026-06-14).
 *
 * v4.3 (typed returns, CLIENT-SDK-SPEC §10): a route may declare a response DTO via
 *   `'response' => SomeDto::class` (+ optional `'collection' => true`). The generator
 *   reflects the DTO's public typed properties into a TS interface in types.ts and
 *   types the method `Promise<Dto>` / `Promise<Dto[]>`. Routes with no `response`
 *   keep the `ApiResponse` (= unknown) fallback — fully incremental.
 * v4.2: PUT/PATCH/DELETE transport + ApiResponse = unknown.
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

/**
 * Injected refresh strategy (CLIENT-SDK-SPEC §12/§14). Resolves true if a fresh
 * access token is now present in the TokenStore (the handler is responsible for
 * performing the refresh AND writing the new token into the same TokenStore this
 * client reads). Lets external-auth / T3 platforms route refresh through their
 * auth-client (e.g. a central accounts server) instead of the built-in
 * same-origin POST, without baking auth topology into the generated client.
 */
export type RefreshHandler = () => Promise<boolean>;

export class MinimalHttp {
  private refreshHandler: RefreshHandler | null = null;

  constructor(
    private readonly baseUrl: string,
    private readonly tokens: TokenStore,
    // Default same-origin refresh endpoint, AUTH-SPEC §4a: POST /api/auth/refresh.
    // Used ONLY when no refresh handler is injected (self-contained / T2 same-origin).
    // Do not change without updating AUTH-SPEC §token-contract.
    private readonly refreshEndpoint: string = '/api/auth/refresh',
  ) {}

  /**
   * Inject a refresh strategy (CLIENT-SDK-SPEC §12/§14). When set, the 401 path
   * delegates to this instead of the built-in same-origin POST. The client still
   * owns token storage, attachment, and 401-detect+retry — only the refresh
   * transport is injected. Pass null to restore the built-in default.
   */
  setRefreshHandler(handler: RefreshHandler | null): void {
    this.refreshHandler = handler;
  }

  async get<T = unknown>(path: string, params?: HttpParams): Promise<T> {
    return this.request<T>('GET', path, undefined, params);
  }

  async post<T = unknown>(path: string, body?: unknown): Promise<T> {
    return this.request<T>('POST', path, body);
  }

  async put<T = unknown>(path: string, body?: unknown): Promise<T> {
    return this.request<T>('PUT', path, body);
  }

  async patch<T = unknown>(path: string, body?: unknown): Promise<T> {
    return this.request<T>('PATCH', path, body);
  }

  // DELETE may carry an optional body (e.g. bulk-delete payloads). Mirrors post().
  async delete<T = unknown>(path: string, body?: unknown): Promise<T> {
    return this.request<T>('DELETE', path, body);
  }

  private async request<T>(
    method: 'GET' | 'POST' | 'PUT' | 'DELETE' | 'PATCH',
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
    // Injected strategy wins (external-auth / T3): the handler refreshes and
    // writes the new token into this client's TokenStore; we just retry on true.
    if (this.refreshHandler) {
      try {
        return await this.refreshHandler();
      } catch {
        return false;
      }
    }
    return this.defaultRefresh();
  }

  private async defaultRefresh(): Promise<boolean> {
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

    // Escape-hatch behaviour (CLIENT-SDK-SPEC §12/§433). Only tenant-scoped
    // (T3 non-admin) clients rewrite logical `/portal/...` paths to carry the active
    // tenant prefix (via escapePath() + the `t` getter). Admin and T2 clients are NOT
    // tenant-scoped (A6): their escape hatch passes paths through verbatim, with NO
    // escapePath()/setTenant() at all.
    if ($isTenantScoped) {
        $getArg        = 'this.escapePath(path)';
        $postArg       = 'this.escapePath(path)';
        $postDocSuffix = ' Tenant-aware for /portal/* paths.';
        $escapePathMethod = "\n"
            . "  /**\n"
            . "   * Tenant-aware escape-hatch path resolver (CLIENT-SDK-SPEC §12). Logical\n"
            . "   * `/portal/...` paths receive the active tenant prefix from the CLIENT; the\n"
            . "   * platform passes only the logical path and never builds /portal/tenant/{id}/…\n"
            . "   * itself (§433). Non-/portal paths (infra/auth) pass through untouched.\n"
            . "   */\n"
            . "  private escapePath(path: string): string {\n"
            . "    return path.startsWith('/portal/') ? `\${this.t}\${path.substring(7)}` : path;\n"
            . "  }\n";
        $hatchDoc = "  /**\n"
            . "   * Escape hatch for endpoints with no typed business method (CLIENT-SDK-SPEC §12/§433).\n"
            . "   * Two legitimate uses:\n"
            . "   *   1. Cross-cutting infra/auth probes (e.g. /api/devices/register, /subscription/status) —\n"
            . "   *      non-/portal paths pass through verbatim.\n"
            . "   *   2. Genuinely-generic / metadata-driven tenant endpoints (e.g. a table-driven\n"
            . "   *      list view at /portal/{group}/{table}) where a per-table typed method would be\n"
            . "   *      wrong — pass the logical `/portal/...` path and the CLIENT applies the active\n"
            . "   *      tenant prefix via setTenant() (the platform NEVER builds /portal/tenant/{id}/…).\n"
            . "   * NEVER use this for an endpoint that SHOULD have a typed api.<group>.<action>() —\n"
            . "   * if you reach for it there, the route is missing its group:/action: declaration; fix that.\n"
            . "   */";
    } else {
        $getArg        = 'path';
        $postArg       = 'path';
        $postDocSuffix = '';
        $escapePathMethod = '';
        $hatchDoc = "  /**\n"
            . "   * Escape hatch for endpoints with no typed business method (CLIENT-SDK-SPEC §12/§433).\n"
            . "   * Use for cross-cutting infra/auth probes (e.g. /api/devices/register,\n"
            . "   * /subscription/status). This client is NOT tenant-scoped, so paths pass through\n"
            . "   * verbatim (no tenant-prefix rewriting).\n"
            . "   * NEVER use this for an endpoint that SHOULD have a typed api.<group>.<action>() —\n"
            . "   * if you reach for it there, the route is missing its group:/action: declaration; fix that.\n"
            . "   */";
    }

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

import { MinimalHttp, HttpParams, RefreshHandler } from './http';
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

{$hatchDoc}
  get<R = unknown>(path: string, params?: HttpParams): Promise<R> {
    return this.http.get<R>({$getArg}, params);
  }

  /** Escape hatch — see {@link ApiClient.get}.{$postDocSuffix} */
  post<R = unknown>(path: string, body?: unknown): Promise<R> {
    return this.http.post<R>({$postArg}, body);
  }
{$escapePathMethod}

  /**
   * Inject the refresh strategy (CLIENT-SDK-SPEC §12/§14). ngx wires this to the
   * auth-client's refresh so external-auth / T3 platforms refresh against their
   * central accounts server while this client keeps ownership of token storage,
   * attachment, and 401-detect+retry. Pass null to use the built-in same-origin
   * default.
   */
  setRefreshHandler(handler: RefreshHandler | null): this {
    this.http.setRefreshHandler(handler);
    return this;
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

        // Resolve typed return (CLIENT-SDK-SPEC §10). null → ApiResponse fallback.
        $responseTs = routeResponseTsType($route);

        // Build method signature and call
        $methodTs = buildMethodTs($action, $method, $urlTemplate, $tailId, $responseTs);
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
 * Reads → GET; writes → POST/PUT/PATCH/DELETE. Tail id param always typed as
 * string | number (A5). Body verbs (POST/PUT/PATCH/DELETE) without :id take a
 * data parameter; with :id take (id, data?). DELETE's body is optional.
 * GET methods without :id take optional HttpParams; with :id take (id, params?).
 *
 * Typed returns (CLIENT-SDK-SPEC §10, v4.3.0): when $responseTs is non-null
 * (the route declared `response:`), the http generic + Promise are typed to that
 * DTO type (e.g. `Promise<T.Warehouse[]>` / `this.http.get<T.Warehouse[]>(...)`).
 * When null, the method keeps the `T.ApiResponse` (= unknown) fallback.
 *
 * @param string|null $responseTs Resolved TS return-payload type ('T.Warehouse[]'),
 *                                 or null for the ApiResponse fallback.
 * @return string TypeScript source for one method entry (including trailing comma)
 */
function buildMethodTs(
    string  $action,
    string  $httpMethod,
    string  $urlTemplate,
    bool    $tailId,
    ?string $responseTs = null,
): string {
    // Return-payload generic: typed DTO when declared, else ApiResponse (unknown).
    $ret = $responseTs ?? 'T.ApiResponse';

    $isGet  = $httpMethod === 'GET';
    // POST/PUT/PATCH all carry a body and share the same signature shape.
    // DELETE carries an OPTIONAL body (same shape — body defaults to undefined).
    $bodyVerb = match ($httpMethod) {
        'POST'   => 'post',
        'PUT'    => 'put',
        'PATCH'  => 'patch',
        'DELETE' => 'delete',
        default  => null,
    };

    if ($isGet) {
        if ($tailId) {
            // GET with :id — e.g. inventory.get(id)
            return <<<TS
    {$action}: (id: string | number) =>
      this.http.get<{$ret}>({$urlTemplate}),
TS;
        } else {
            // GET list / search / action — e.g. inventory.list(params?)
            return <<<TS
    {$action}: (params?: HttpParams) =>
      this.http.get<{$ret}>({$urlTemplate}, params),
TS;
        }
    }

    if ($bodyVerb !== null) {
        if ($tailId) {
            // Body verb with :id — e.g. inventory.update(id, data?) / workspace.delete(id)
            return <<<TS
    {$action}: (id: string | number, data?: T.ApiRequestBody) =>
      this.http.{$bodyVerb}<{$ret}>({$urlTemplate}, data),
TS;
        } else {
            // Body verb without :id — e.g. inventory.create(data?)
            return <<<TS
    {$action}: (data?: T.ApiRequestBody) =>
      this.http.{$bodyVerb}<{$ret}>({$urlTemplate}, data),
TS;
        }
    }

    // Fallback for genuinely unknown verbs (should not occur — GET/POST/PUT/PATCH/DELETE covered above)
    return <<<TS
    {$action}: (..._args: unknown[]) => Promise.reject(new Error('Unsupported HTTP method: {$httpMethod}')),
TS;
}

// ============================================================================
// Typed-return DTO reflection (CLIENT-SDK-SPEC §10) — v4.3.0
//
// For any route declaring `response: SomeDto::class`, the generator reflects the
// DTO's PUBLIC TYPED properties into a TypeScript interface and types the method
// `Promise<Dto>` (single) or `Promise<Dto[]>` (collection: true). Routes with no
// `response` keep the `ApiResponse` (= unknown) fallback — incremental + graceful.
//
// The reflection is recursive (nested DTO classes → their own interfaces),
// deduped (each interface emitted once), and cycle-safe (a class already being
// reflected is referenced by name, not re-expanded).
// ============================================================================

/**
 * Registry of DTO interfaces discovered during generation, keyed by short TS
 * interface name. Value = the emitted TS interface body (full `export interface …`).
 * Order-preserving so emission is deterministic.
 *
 * @var array<string,string>
 */
$GLOBALS['__dtoInterfaces'] = [];

/** Classes currently mid-reflection (cycle guard). @var array<string,true> */
$GLOBALS['__dtoInProgress'] = [];

/**
 * Map a short, unqualified TypeScript interface name from a PHP FQCN.
 * 'App\Models\Warehouse' → 'Warehouse'. Collisions across namespaces are
 * resolved by the registry (last-write-wins is acceptable: response DTOs are
 * expected to have unique class basenames within a service).
 */
function dtoTsName(string $fqcn): string
{
    $parts = explode('\\', $fqcn);
    return end($parts);
}

/**
 * Map a single PHP type to a TypeScript type expression.
 *
 * @param \ReflectionType|null $type      The reflected property type (may be null = untyped).
 * @param string|null          $docArray  Element type harvested from a `@var Foo[]` docblock, if any.
 * @return string TS type expression (e.g. 'number', 'string | null', 'Warehouse[]', 'unknown').
 */
function phpTypeToTs(?\ReflectionType $type, ?string $docArray = null): string
{
    // Untyped property — fall back to docblock array hint, else unknown.
    if ($type === null) {
        if ($docArray !== null) {
            $inner = reflectDtoElementType($docArray);
            return $inner . '[]';
        }
        return 'unknown';
    }

    if ($type instanceof \ReflectionNamedType) {
        $name     = $type->getName();
        $nullable = $type->allowsNull();
        $ts       = scalarPhpTypeToTs($name, $docArray);
        return $nullable && $name !== 'null' ? $ts . ' | null' : $ts;
    }

    // Union / intersection types → punt to unknown (documented limitation).
    if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
        return 'unknown';
    }

    return 'unknown';
}

/**
 * Map a named PHP type (scalar / class / array) to a TS type, recursing into
 * nested DTO classes. `array` with a `@var Foo[]` docblock → `Foo[]`; bare
 * `array` → `unknown[]`.
 */
function scalarPhpTypeToTs(string $name, ?string $docArray = null): string
{
    switch (strtolower($name)) {
        case 'int':
        case 'float':
            return 'number';
        case 'string':
            return 'string';
        case 'bool':
            return 'boolean';
        case 'array':
            if ($docArray !== null) {
                return reflectDtoElementType($docArray) . '[]';
            }
            return 'unknown[]';
        case 'mixed':
        case 'object':
            return 'unknown';
        case 'datetime':
        case 'datetimeimmutable':
        case 'datetimeinterface':
            return 'string'; // ISO-8601 string on the wire
    }

    // Fully-qualified or relative class name → nested DTO interface, or enum.
    $fqcn = ltrim($name, '\\');
    if (class_exists($fqcn) || interface_exists($fqcn)) {
        if (enum_exists($fqcn)) {
            return enumToTsUnion($fqcn);
        }
        reflectDto($fqcn); // emit the nested interface (recursive, deduped, cycle-safe)
        return dtoTsName($fqcn);
    }

    return 'unknown';
}

/**
 * Resolve a docblock element type token (e.g. 'Warehouse', 'App\Models\Warehouse',
 * 'int', 'string') to a TS type, recursing into DTO classes when it names one.
 */
function reflectDtoElementType(string $token): string
{
    $token = trim($token);
    $lower = strtolower($token);
    $scalarMap = ['int' => 'number', 'float' => 'number', 'string' => 'string', 'bool' => 'boolean'];
    if (isset($scalarMap[$lower])) {
        return $scalarMap[$lower];
    }
    // Try to resolve as a class (may be short name under App\Models or App\Dto).
    foreach ([$token, 'App\\Dto\\' . $token, 'App\\Models\\' . $token] as $candidate) {
        $candidate = ltrim($candidate, '\\');
        if (class_exists($candidate)) {
            reflectDto($candidate);
            return dtoTsName($candidate);
        }
    }
    return 'unknown';
}

/**
 * Convert a PHP backed/pure enum to a TS string-literal union (backed-string),
 * or `string` when not a pure string enum.
 */
function enumToTsUnion(string $fqcn): string
{
    try {
        $ref = new \ReflectionEnum($fqcn);
        if ($ref->isBacked() && (string) $ref->getBackingType() === 'string') {
            $cases = array_map(
                fn($c) => "'" . $c->getValue()->value . "'",
                $ref->getCases()
            );
            return empty($cases) ? 'string' : implode(' | ', $cases);
        }
    } catch (\Throwable) {
        // fall through
    }
    return 'string';
}

/**
 * Extract a `@var Foo[]` element token from a property's docblock, if present.
 * Returns the element token ('Foo') or null. Supports `Foo[]` and `array<Foo>`.
 */
function docblockArrayType(\ReflectionProperty $prop): ?string
{
    $doc = $prop->getDocComment();
    if ($doc === false) {
        return null;
    }
    if (preg_match('/@var\s+([A-Za-z0-9_\\\\]+)\s*\[\]/', $doc, $m)) {
        return $m[1];
    }
    if (preg_match('/@var\s+array<\s*([A-Za-z0-9_\\\\]+)\s*>/', $doc, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Reflect a DTO class into a TS interface, registering it in $__dtoInterfaces.
 * Recursive (nested DTOs), deduped (skip if already registered), cycle-safe
 * (a class mid-reflection is referenced by name, not re-expanded).
 *
 * Reflects ONLY public properties (constructor-promoted or plain). Static and
 * non-public properties are ignored. Returns the short TS interface name.
 */
function reflectDto(string $fqcn): string
{
    $fqcn   = ltrim($fqcn, '\\');
    $tsName = dtoTsName($fqcn);

    // Already emitted or currently mid-reflection (cycle) → reference by name.
    if (isset($GLOBALS['__dtoInterfaces'][$tsName]) || isset($GLOBALS['__dtoInProgress'][$tsName])) {
        return $tsName;
    }

    if (!class_exists($fqcn)) {
        fwrite(STDERR, "[stone generate client] WARNING: response DTO class '$fqcn' not found; method falls back to ApiResponse (unknown).\n");
        return 'unknown';
    }

    $GLOBALS['__dtoInProgress'][$tsName] = true;

    $ref   = new \ReflectionClass($fqcn);
    $lines = [];
    foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
        if ($prop->isStatic()) {
            continue;
        }
        $propName = $prop->getName();
        $type     = $prop->getType();
        $docArray = docblockArrayType($prop);
        $tsType   = phpTypeToTs($type, $docArray);

        // Nullable property → optional `?` marker in addition to `| null`.
        $optional = ($type !== null && $type->allowsNull()) ? '?' : '';

        $lines[] = "  {$propName}{$optional}: {$tsType};";
    }

    $body = "export interface {$tsName} {\n" . implode("\n", $lines) . "\n}";
    $GLOBALS['__dtoInterfaces'][$tsName] = $body;
    unset($GLOBALS['__dtoInProgress'][$tsName]);

    return $tsName;
}

/**
 * Resolve a route's `response` declaration into the TS return-payload type used
 * inside Promise<…> AND the http generic. Returns null when the route declares no
 * response (caller keeps the ApiResponse/unknown fallback).
 *
 * @return string|null e.g. 'Warehouse[]' (collection) or 'Warehouse' (single).
 */
function routeResponseTsType(array $route): ?string
{
    $dto = $route['response'] ?? null;
    if ($dto === null || $dto === '') {
        return null;
    }
    $tsName = reflectDto($dto);
    if ($tsName === 'unknown') {
        return null; // class missing — graceful fallback
    }
    $tsName = 'T.' . $tsName;
    return !empty($route['collection']) ? $tsName . '[]' : $tsName;
}

/**
 * Generate the types.ts file.
 *
 * In v4.0 the generator emits a minimal set of generic types. v4.3.0 additionally
 * emits one TS interface per DTO declared via a route `response:` slot (collected
 * in $__dtoInterfaces during method generation). The generator ensures the
 * baseline types used in client.ts are always present.
 */
function generateTypesTs(): string
{
    $baseline = <<<'TS'
/**
 * Auto-generated type definitions
 * DO NOT EDIT MANUALLY — Regenerate with: php stone generate client
 *
 * Platform-specific DTOs are generated from PHP DTO classes declared via a route
 * `response:` slot (CLIENT-SDK-SPEC §10). Routes without a `response:` keep the
 * generic `ApiResponse` (= unknown) fallback.
 * The types below are the minimum baseline required by the generated ApiClient.
 */

/**
 * Generic API response data payload (replace with specific types per endpoint).
 * Typed as `unknown` so consumers narrow with a single `as X` cast — the previous
 * `Record<string, unknown> | unknown[] | null` union broke strict narrowing (the
 * `unknown[]` member) and forced `as unknown as X` double-casts. (CLIENT-SDK-SPEC §6)
 */
export type ApiResponse = unknown;

/** Generic request body type (replace with specific types per endpoint) */
export type ApiRequestBody = Record<string, unknown> | unknown[] | null;
TS;

    // Append DTO interfaces collected during method generation for THIS service
    // package (the registry is reset per service before its routes are processed).
    $interfaces = $GLOBALS['__dtoInterfaces'] ?? [];
    if (empty($interfaces)) {
        return $baseline . "\n\n// No route declares a `response:` DTO in this service —\n"
            . "// all methods return ApiResponse (unknown). Declare `response:` on a route\n"
            . "// to generate a typed interface here.\n";
    }

    $block = "\n\n// ─────────────────────────────────────────────────────────────\n"
        . "// Response DTOs — reflected from PHP `response:` route declarations\n"
        . "// ─────────────────────────────────────────────────────────────\n\n"
        . implode("\n\n", array_values($interfaces)) . "\n";

    return $baseline . $block;
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

    // Reset the per-service DTO interface registry so response DTOs declared in
    // one service's routes do not leak into another service's types.ts (§10).
    $GLOBALS['__dtoInterfaces'] = [];
    $GLOBALS['__dtoInProgress'] = [];

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
