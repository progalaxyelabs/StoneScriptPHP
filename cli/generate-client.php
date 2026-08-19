<?php

/**
 * API Client Generator — v4.10
 *
 * Generates per-service TypeScript client packages from PHP routes.
 * Implements CLIENT-SDK-SPEC §0 Amendments A1–A6 (approved 2026-06-14).
 *
 * v4.10 (framework v9.6.0 — request-DTO reflection + opt-in strict gate):
 *   - `request:` route declaration mirrors `response:` exactly — reflects a
 *     DTO's public typed properties into a TS interface and types the
 *     generated method's body parameter to it instead of the untyped
 *     `T.ApiRequestBody`. See routeRequestTsType()/buildMethodTs().
 *   - typedContractViolations() + `--strict-types` / STONE_CLIENT_GEN_STRICT_TYPES
 *     env var: an opt-in hard gate that turns "route missing a `response:`
 *     (any method) or `request:` (body verb) DTO" from a silent `unknown`
 *     fallback into a listed WARNING (default, unchanged) or a hard
 *     exit(1) (opt-in). See CHANGELOG.md v9.6.0 for the full rationale.
 *
 * v4.7 (T3 tenant-prefix guard — production incident on a downstream platform):
 *   - Added assertT3RoutesCarryTenantPrefix(), called once per T3-tenant-scoped
 *     service before URL templates are built.
 *   - Root cause: T3 mode strips a literal `/{service}/tenant/{tenantId}` prefix
 *     off each route path and replaces it with the runtime `${this.t}` accessor.
 *     When a platform's routes.php has NO such prefix (its real tenancy is T2 —
 *     JWT-tenant, tenant resolved server-side from the token) but the generator
 *     is invoked without `--tenancy=T2` and silently defaults to T3, the strip
 *     is a no-op: the emitted template becomes `${this.t}` + the UNSTRIPPED
 *     path, producing a doubled-service-segment URL
 *     (`/portal/tenant/{id}/portal/projects`) that 404s on every call. The
 *     generator exited 0 and wrote a client that looked entirely plausible —
 *     this shipped straight to a consuming platform's production portal.
 *   - Fix: before building URL templates for a T3-tenant-scoped service, every
 *     route's path is checked against the exact prefix pattern the template
 *     builder strips. Any route missing it aborts generation with a hard error
 *     listing the offending routes and pointing at `--tenancy=T2`. No more
 *     silent malformed-URL clients.
 *
 * v4.6 (orphan cross-product cleanup):
 *   - Added removeOrphanNestedPackages() that runs at the start of every generation.
 *   - Before v4.0, the generator emitted a {consumer}×{service} cross-product layout:
 *     client/{consumer}/{service}/ — e.g. client/admin/finance/, client/ats/finance/.
 *     These directories are not referenced by any Angular frontend and have no
 *     package-lock.json (they were never npm-installed in that nested form). They
 *     accumulated in repos and caused deploy-manager lint failures ("Missing
 *     package-lock.json" for ~100 dirs fleet-wide). The cleanup step removes them
 *     automatically on the next `php stone generate client` run so platforms do not
 *     need manual deletion.
 *   - Safe: only directories inside a flat service package dir (client/{service}/)
 *     that themselves contain a package.json are removed; src/, dist/, node_modules/,
 *     and streaming/ subdirs are left untouched.
 *
 * v4.5 (multi-scope clobber fix):
 *   - Each service package's `name` is now derived from the SERVICE NAME, not the
 *     <scope> CLI argument. When a routes.php declares multiple services (e.g.
 *     portal + admin), each emitted package gets its own correct name:
 *       client/portal/ → {composer-name}-portal-client
 *       client/admin/  → {composer-name}-admin-client
 *     Previously all packages in a single run shared the same scope-derived name,
 *     so a second run with a different scope silently clobbered the first run's
 *     package.json names (every multi-service platform was affected).
 *   - The <scope> positional argument is now OPTIONAL. When omitted, generation
 *     proceeds normally — every service in routes.php gets its own correctly-named
 *     package. When supplied it is accepted without error (backward-compatible) and
 *     has no effect on naming.
 *   - Deprecation notice: passing <scope> is accepted but unnecessary and will be
 *     removed in a future major version. Remove it from your `stone generate client`
 *     invocations.
 *
 * v4.4 (package naming — generate-api-client-spec.md §"Package Naming"):
 *   - Generated package `name` is derived deterministically as
 *     `{composer-name}-{service}-client`, where `{composer-name}` is the `name` field
 *     from the API project's composer.json used AS-IS (no hardcoded npm org scope
 *     prefix like `@stonescript/` is added or stripped). Replaces the prior
 *     `@stonescript/api-client-{service}` convention.
 *   - Example: composer name `exampleapp-api`, service `portal`
 *     → package name `exampleapp-api-portal-client`
 *   - The `--service=` filter remains for single-package generation.
 *
 * v4.3 (typed returns, CLIENT-SDK-SPEC §10): a route may declare a response DTO via
 *   `'response' => SomeDto::class` (+ optional `'collection' => true`). The generator
 *   reflects the DTO's public typed properties into a TS interface in types.ts and
 *   types the method `Promise<Dto>` / `Promise<Dto[]>`. Routes with no `response`
 *   keep the `ApiResponse` (= unknown) fallback — fully incremental.
 * v4.2: PUT/PATCH/DELETE transport + ApiResponse = unknown.
 *
 * Key behaviours (v4.0):
 *   - Reads `service`/`group`/`action`/`streaming`/`param` from each route's array
 *     declaration in routes.php: ['handler' => X::class, 'service' => ..., 'group' => ...].
 *     (Programmatic $router->group()/scope()-based route registration was removed in
 *     v6.0.0 — routing consolidation, see ROUTING-CONSOLIDATION-PLAN.md. It was never
 *     adopted by any real platform; the array format above is the only one now supported,
 *     matching what every platform's routes.php already used.)
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
 *   php stone generate client <scope>
 *   php stone generate client portal
 *   php stone generate client portal --tenancy=T3   (default for most platforms)
 *   php stone generate client portal --tenancy=T2   (T2/JWT-tenant — no URL tenant segment)
 *   php stone generate client portal --tenancy=T1   (no tenant at all)
 *   php stone generate client portal --output=client --service=portal  (single package)
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
$scopeArg       = null;          // OPTIONAL positional (deprecated — accepted but no longer used for naming)

// v9.6.0 (Phase 3, CLIENT-SDK-SPEC §10 amendment) — opt-in hard gate for typed
// contracts. Default OFF (today's warn-and-fall-back-to-unknown behavior,
// unchanged) so this ships to the whole fleet via composer without breaking
// platforms that haven't typed their routes yet. A platform
// turns it on per-generation via `--strict-types`, or persistently via
// STONE_CLIENT_GEN_STRICT_TYPES=1 in its own environment/.env (read here via
// getenv(), NOT the framework's Env class — the generator runs standalone,
// before any platform bootstrap). See typedContractViolations() below.
$strictTypedContracts = in_array(getenv('STONE_CLIENT_GEN_STRICT_TYPES'), ['1', 'true', 'yes'], true);

// Use the dispatcher-adjusted argv. When invoked via `php stone generate client [scope]`,
// the `stone` CLI sets $_SERVER['argv'] = [$scriptPath, ...$args_after_subcommand] — so
// $_SERVER['argv'][1] is the first real argument (e.g. "www"), NOT "generate" or "client".
// The global $argv still contains the full stone invocation (stone, generate, client, www …)
// and must NOT be read here, or the scope pick-up would misparse "generate" as the scope.
$argv = $_SERVER['argv'];

// Help check first — before positional parsing
if (array_intersect(['--help', '-h', 'help'], $argv)) {
    echo <<<HELP
API Client Generator v4.10
==========================

Generates per-service TypeScript client packages (CLIENT-SDK-SPEC §0 A1-A6).
Package name for each service package is derived as {composer-name}-{service}-client.

Usage: php stone generate client [options]

Arguments:
  [scope]             DEPRECATED / OPTIONAL. Previously required; now accepted without
                      error but has no effect on naming. Package names are derived from
                      the service name in routes.php, not from this argument:
                        portal service → {composer.json name}-portal-client
                        admin service  → {composer.json name}-admin-client
                      Remove this arg from your invocations — it will be removed in v5.

Options:
  --output=<dir>      Base output directory (default: client)
                      One subdirectory per service is created inside:
                        client/portal/   client/admin/
  --tenancy=<mode>    T3 (default) | T2 | T1
                      T3: multi-tenant/URL (e.g. a store or logistics platform)
                      T2: tenant in JWT (e.g. an analytics platform — no /tenant/{id} segment)
                      T1: no tenant scope
  --service=<name>    Generate only the named service package (default: all)
  --language=<lang>   typescript (only option in v4.0)
  --strict-types      Hard-fail (exit 1) instead of warn when a route lacks a
                      full typed contract (`response:` on any method,
                      `request:` on POST/PUT/PATCH/DELETE). Default: off —
                      opt in per-invocation here, or persistently via
                      STONE_CLIENT_GEN_STRICT_TYPES=1 in your environment.

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

foreach ($argv as $i => $arg) {
    if ($i === 0) continue; // skip script name
    if (str_starts_with($arg, '--output='))   { $outputBaseDir = substr($arg, 9); continue; }
    if (str_starts_with($arg, '--tenancy='))  { $tenancyMode   = strtoupper(substr($arg, 10)); continue; }
    if (str_starts_with($arg, '--service='))  { $serviceFilter = strtolower(substr($arg, 10)); continue; }
    if (str_starts_with($arg, '--language=')) { $language       = strtolower(substr($arg, 11)); continue; }
    if ($arg === '--strict-types')            { $strictTypedContracts = true; continue; }
    if (str_starts_with($arg, '--'))          { continue; } // unknown flag — skip

    // First non-flag argument = <scope>
    if ($scopeArg === null) {
        $scopeArg = strtolower($arg);
    }
}

// $scopeArg is now optional (v4.5). When supplied, emit a deprecation notice;
// package names are derived from each service name in the generation loop below.
if ($scopeArg !== null) {
    fwrite(STDERR, "[stone generate client] NOTICE: <scope> positional arg '$scopeArg' is deprecated (v4.5).\n" .
        "  Package names now derive from each service name in routes.php.\n" .
        "  Remove this arg from your invocations — it will be removed in v5.\n");
}

if (!in_array($tenancyMode, ['T1', 'T2', 'T3'])) {
    fwrite(STDERR, "Error: --tenancy must be T1, T2, or T3 (got '$tenancyMode').\n");
    exit(1);
}

// ============================================================================
// Derive package name from composer.json (generate-api-client-spec.md §"Package Naming")
// ============================================================================

/**
 * Read the composer.json `name` field from the API project root.
 * Returns null if the file is missing or malformed.
 */
function readComposerName(): ?string
{
    $composerPath = ROOT_PATH . 'composer.json';
    if (!file_exists($composerPath)) {
        return null;
    }
    $decoded = json_decode(file_get_contents($composerPath), true);
    return is_array($decoded) && isset($decoded['name']) ? (string) $decoded['name'] : null;
}

$composerName = readComposerName();
if ($composerName === null) {
    fwrite(STDERR, "[stone generate client] WARNING: composer.json not found or has no `name` field at " . ROOT_PATH . "composer.json\n");
    fwrite(STDERR, "[stone generate client] Falling back to package name '{scope}-client'.\n");
    $composerName = '';
}

/**
 * Derive the generated npm package name per generate-api-client-spec.md §"Package Naming".
 *
 * The $serviceName parameter is the backend service name from routes.php (e.g. 'portal',
 * 'admin', 'www'). Using the service name — not the CLI scope arg — ensures that when a
 * routes.php declares multiple services, each package gets a distinct, correct name:
 *   portal service → {composer-name}-portal-client
 *   admin  service → {composer-name}-admin-client
 *
 * Rules:
 *   - When {composer-name} has NO slash (e.g. 'exampleapp-api'):
 *       → unscoped npm name: `{composer-name}-{service}-client`
 *       → example: 'exampleapp-api', service 'portal' → 'exampleapp-api-portal-client'
 *
 *   - When {composer-name} has a vendor prefix (e.g. 'progalaxyelabs/progalaxy-api'):
 *       → scoped npm name: `@{vendor}/{pkg}-{service}-client`
 *       → example: 'progalaxyelabs/progalaxy-api', service 'www' → '@progalaxyelabs/progalaxy-api-www-client'
 *
 *   - When {composer-name} is empty (composer.json missing or no name field):
 *       → fallback: `{service}-client`
 *
 * The vendor/pkg split maps the Composer vendor prefix to an npm org scope (@vendor),
 * producing a valid npm scoped package name. No other transformation is applied.
 * No hardcoded org scope (no @stonescript/, no @progalaxyelabs/) is added beyond
 * what the composer name itself provides.
 *
 * @param string $composerName  Value of `name` from composer.json (e.g. 'exampleapp-api'
 *                              or 'progalaxyelabs/progalaxy-api')
 * @param string $serviceName   The service being generated (e.g. 'portal', 'admin', 'www')
 * @return string               Derived npm package name
 */
function derivePackageName(string $composerName, string $serviceName): string
{
    if ($composerName === '') {
        return $serviceName . '-client';
    }

    // Vendor-prefixed composer name (e.g. 'vendor/pkg') → npm scoped form '@vendor/pkg-{service}-client'
    if (str_contains($composerName, '/')) {
        [$vendor, $pkg] = explode('/', $composerName, 2);
        return '@' . $vendor . '/' . $pkg . '-' . $serviceName . '-client';
    }

    // No vendor prefix (e.g. 'exampleapp-api') → unscoped 'exampleapp-api-{service}-client'
    return $composerName . '-' . $serviceName . '-client';
}

// Convert to absolute path if relative
if (!str_starts_with($outputBaseDir, '/')) {
    $outputBaseDir = ROOT_PATH . $outputBaseDir;
}

// ============================================================================
// Route loading — uses $router->getRouteMeta() (v4.0 API)
// ============================================================================

/**
 * Load routes by requiring the platform's routes.php (must return the flat
 * array format — the only format supported since v6.0.0's routing
 * consolidation, see ROUTING-CONSOLIDATION-PLAN.md) and loading it into a
 * fresh Router.
 *
 * Returns the raw route metadata array from Router::getRouteMeta() — each entry has:
 *   method, path, handler, service, group, action, streaming, param, is_public
 */
function loadRoutesFromPlatform(): array
{
    $routesFile = SRC_PATH . 'config' . DIRECTORY_SEPARATOR . 'routes.php';

    if (!file_exists($routesFile)) {
        fwrite(STDERR, "[stone generate client] ERROR: routes.php not found at $routesFile\n");
        exit(1);
    }

    $routesResult = require $routesFile;

    if (!is_array($routesResult)) {
        fwrite(STDERR,
            "[stone generate client] ERROR: routes.php must return an array, e.g.:\n" .
            "  return ['GET' => ['/path' => ['handler' => X::class, 'service' => 'portal', 'group' => 'billing']]];\n" .
            "Programmatic \$router->group()/get()/post() route registration (a routes.php that calls\n" .
            "Router methods instead of returning an array) was removed in v6.0.0.\n"
        );
        exit(1);
    }

    $router = new \StoneScriptPHP\Routing\Router();
    $router->loadRoutes($routesResult);
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

    // A3: infra / webhook services — EXCEPT a route that declares
    // access: RouteAccess::AUTHENTICATION (v4.8, see the access-aware
    // token-selection feature below). 'infra' was designed for genuinely
    // unauthenticated infrastructure probes (health, JWKS) and 'webhook' for
    // inbound signed/public callbacks — neither plausibly declares
    // access=authentication. Real-world identity-scoped endpoints
    // (provision-tenant, select-tenant, memberships) get registered by
    // platforms under service:'infra' too, purely because they don't belong
    // to a specific portal/admin business service — that tagging choice
    // should not also make them permanently ungeneratable. A route that
    // explicitly asks for an identity/auth token is, by definition, not a
    // pure infra probe.
    if (in_array(strtolower($service), EXCLUDED_SERVICES) && ($route['access'] ?? null) !== 'authentication') {
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

/**
 * v9.6.0 (Phase 3, CLIENT-SDK-SPEC §10 amendment). Report every way an
 * includable route falls short of a FULL typed contract: a missing
 * `response:` DTO, or (for a body-carrying verb) a missing `request:` DTO.
 * Pure — returns human-readable violation strings, does not print or exit;
 * the caller decides whether that's a warning or a hard failure based on
 * $strictTypedContracts (see top-of-file CLI arg / env parsing).
 *
 * @param array<string,mixed> $route
 * @return string[] Empty when the route is fully typed.
 */
function typedContractViolations(array $route): array
{
    $violations = [];
    $method  = strtoupper($route['method'] ?? '?');
    $hasBody = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);

    if (empty($route['response'])) {
        $violations[] = 'missing `response:` DTO';
    }
    if ($hasBody && empty($route['request'])) {
        $violations[] = "missing `request:` DTO ($method carries a body)";
    }

    return $violations;
}

/**
 * True when a route is identity-scoped/public rather than tenant-scoped,
 * regardless of which `service` it's tagged under. A route declaring
 * `access: authentication` (runs on an auth/identity token, before any
 * tenant is selected — e.g. provision-tenant, select-tenant) or `public`
 * (no token at all) can never legitimately carry a `/{service}/tenant/{id}`
 * URL segment, no matter what `service` value it happens to be tagged with
 * (real fleet convention: these land under `service: 'infra'` purely
 * because they don't belong to a specific business service — see A3's
 * exclusion carve-out in exclusionReason()). This is the single shared
 * predicate both the T3-prefix guard (assertT3RoutesCarryTenantPrefix()) and
 * the URL-template builder (buildGroupMethods()) key off of, so they can
 * never drift out of sync with each other — the guard must exempt exactly
 * the routes the template builder also treats as non-tenant-scoped, or one
 * silently reintroduces the doubled-URL bug the other was built to prevent.
 * Added 2026-08-12 alongside the guard fix below.
 *
 * @param array<string,mixed> $route
 */
function routeIsTenantUrlExempt(array $route): bool
{
    return in_array($route['access'] ?? null, ['authentication', 'public'], true);
}

/**
 * v4.7 hard-error guard (production incident on a downstream T2 platform).
 *
 * T3 (URL-tenant) mode builds every tenant-scoped business-method URL by
 * STRIPPING the exact `/{service}/tenant/{tenantId}` prefix off the route path
 * and prepending the runtime tenant accessor `${this.t}` (which itself resolves
 * to `/{service}/tenant/{id}`). When a route's path does NOT carry that prefix
 * — because the platform's real tenancy is T2 (JWT-tenant, tenant resolved
 * server-side from the token, never in the URL) but the generator was invoked
 * without `--tenancy=T2` and silently fell back to the T3 default — the strip
 * is a no-op and the emitted template becomes `${this.t}` + the UNSTRIPPED
 * path, e.g. `/portal/tenant/{id}` + `/portal/projects` =
 * `/portal/tenant/{id}/portal/projects`. That is a doubled-service-segment URL
 * that 404s on every call. The generator previously exited 0 and wrote a
 * client that looked entirely plausible — this was a SILENT failure mode that
 * shipped straight to production on a downstream T2 (JWT-tenant) platform
 * (its portal dashboard/projects list 404'd for every tenant-scoped call
 * after a routine client regeneration omitted the --tenancy flag and picked
 * up the T3 default).
 *
 * Fails loudly instead of writing a broken client: if ANY genuinely
 * tenant-scoped route in a T3 service lacks the expected prefix, abort
 * generation with every offending route listed and a direct pointer to
 * --tenancy=T2.
 *
 * v9.1.0 made `access: authentication`/`public` routes includable in a
 * service that would otherwise be entirely excluded (A3's infra/webhook
 * carve-out) — real identity-scoped tier-2 endpoints like provision-tenant
 * get tagged `service: 'infra'` purely because they don't belong to a
 * specific business service, not because they're tenant-scoped. Those
 * routes structurally CANNOT carry a `/{service}/tenant/{tenantId}` prefix
 * (there is no tenant yet — that's the entire point of a route like
 * provision-tenant), so this guard must not flag them: it exists to catch
 * genuinely tenant-scoped routes that are MISSING the prefix by mistake, not
 * to reject routes that were never tenant-scoped in the first place. Fixed
 * 2026-08-12 — this exact carve-out (`access` !== authorization) is already
 * used for the A3 exclusion itself (see exclusionReason()) and for token
 * selection (buildMethodTs()); this guard was the one place that still
 * treated every route in the service uniformly, which made a T3 platform's
 * `php stone generate client` hard-abort the moment it declared
 * `access: authentication` on an infra-tagged route — exactly the
 * downstream-platform/provision-tenant case that surfaced this.
 *
 * @param array<int,array<string,mixed>> $routes All routes for one T3-tenant-scoped service.
 * @param string $serviceName e.g. 'portal'
 */
function assertT3RoutesCarryTenantPrefix(array $routes, string $serviceName): void
{
    $prefixPattern = '#^/' . preg_quote($serviceName, '#') . '/tenant/\{[^}]+\}#';

    $offending = [];
    foreach ($routes as $route) {
        if (routeIsTenantUrlExempt($route)) {
            continue;
        }
        $path = $route['path'] ?? '';
        if (!preg_match($prefixPattern, $path)) {
            $offending[] = strtoupper($route['method'] ?? '?') . ' ' . ($path === '' ? '?' : $path);
        }
    }

    if (empty($offending)) {
        return;
    }

    $list = implode("\n", array_map(fn($r) => "    - $r", $offending));
    fwrite(STDERR, <<<ERR

[stone generate client] ERROR: --tenancy=T3 (URL-tenant) was requested for service '$serviceName',
but the following route(s) do not carry the expected /$serviceName/tenant/{tenantId} URL prefix:
$list

T3 mode builds every tenant-scoped method URL by stripping that exact prefix and prepending the
runtime tenant accessor (this.t). A route missing the prefix produces a DOUBLED, 404ing URL at
runtime (this.t + the unstripped path) — this is the production incident that motivated this guard:
GET /portal/tenant/{id}/portal/projects instead of GET /portal/projects.

If tenant_id is resolved server-side from the JWT (not the URL) for this platform, regenerate with
--tenancy=T2 instead (JWT-tenant — no URL tenant segment, tenant comes from the token):

    php stone generate client --tenancy=T2

Generation aborted — no files were written for service '$serviceName'.
ERR
    );
    exit(1);
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

/**
 * Check whether a route path contains ANY {param} / :param placeholder.
 *
 * This is the correct signal for whether the generated TypeScript URL template
 * will contain `${id}` and therefore whether the method signature must declare
 * `id: string | number`. Using hasTailId() for this purpose was the bug: it
 * only checks the LAST path segment, so paths like `/routes/{id}/start`
 * (where {id} is NOT the tail) produced templates with `${id}` in them but
 * method signatures without the `id` parameter — TS2304 under strict tsc.
 *
 * NOTE: the /tenant/{tenantId} placeholder is intentionally included here.
 * For T3 routes the tenant segment is stripped before URL template emission, but
 * for T2/T1/admin routes with a /tenant/{param} in the path this check would
 * still correctly identify that another param follows. In practice T3 routes
 * always have the tenant prefix, so the check degrades gracefully to "does the
 * path contain any non-tenantId placeholder", which is exactly what we need:
 * we just need to know if the URL template will contain `${id}`.
 */
function hasAnyPathParam(string $path): bool
{
    return (bool) preg_match('/\{[^}]+\}/', $path) || str_contains($path, ':');
}

/**
 * Check whether the emitted URL template for a route will contain `${id}`.
 *
 * This replaces hasTailId() as the decision function for whether the method
 * signature must include `id: string | number`. The URL template replaces ALL
 * {param} placeholders (after stripping /tenant/{tenantId}) with `${id}`, so
 * any route that has a {param} anywhere in its non-tenant path will produce a
 * template that references `id` — and the method signature must declare it.
 *
 * For T3 routes the tenant-prefix param ({tenantId}) is stripped and replaced
 * by ${this.t} before emitting, so it never produces a ${id} reference.
 * For T2/admin routes /tenant/{param} is also stripped. The remaining {param}
 * segments — those that actually appear as ${id} in the template — are what
 * this function counts.
 *
 * @param string $path          Raw route path from routes.php
 * @param string $serviceName   e.g. 'portal' (used to strip T3 tenant prefix)
 * @param bool   $isTenantScoped True when T3 non-admin (uses ${this.t} prefix)
 */
function templateNeedsIdParam(string $path, string $serviceName, bool $isTenantScoped): bool
{
    if ($isTenantScoped) {
        // Strip /{service}/tenant/{tenantId} — this becomes ${this.t}, not ${id}
        $stripped = preg_replace(
            '#^/' . preg_quote($serviceName, '#') . '/tenant/\{[^}]+\}#',
            '',
            $path
        ) ?? $path;
    } else {
        // Strip /tenant/{param} for T2/admin if present
        $stripped = preg_replace('#/tenant/\{[^}]+\}#', '', $path) ?? $path;
    }
    // Any remaining {param} becomes ${id} in the template
    return (bool) preg_match('/\{[^}]+\}/', $stripped);
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
 * v4.8 (access-aware token selection). Mirrors RouteAccess's three values
 * (StoneScriptPHP\Routing\RouteAccess — public|authentication|authorization)
 * exactly, rather than a derived boolean, so a 4th value can never silently
 * fall through a stale true/false split. Defaults to 'authorization' (the
 * tenant-scoped API token) — today's only behavior, unchanged for every
 * route that doesn't declare `access:` or declares `authorization`.
 *
 *   - 'authorization'  — API token (TokenStore.get()). Default.
 *   - 'authentication' — identity/auth token (TokenStore.getAuthToken()) —
 *     for routes like provision-tenant that run BEFORE a tenant-scoped card
 *     exists, so there is no API token yet to send.
 *   - 'public'         — no token attached at all, even if one happens to be
 *     in storage. Previously EVERY generated call attached whatever the
 *     TokenStore held regardless of the route's actual access requirement;
 *     a public route now genuinely sends no Authorization header.
 *
 * v4.9 — timeoutMs (opt-in, per-route). Every generated `fetch()` call was
 * previously UNBOUNDED — no timeout at all, relying entirely on the browser's
 * own (effectively unlimited) fetch default. Fine for typical sub-second
 * calls, but wrong for routes that are legitimately slow by design (e.g.
 * provision-tenant, which physically creates a new tenant database + deploys
 * its full schema via the gateway — observed 4.2-5.0s consistently in
 * production). Without a bound, ANY caller with its own shorter expectation
 * (a test harness, a upstream proxy, a future UI-level abort) can give up
 * before the request finishes, while the server keeps working and completes
 * successfully moments later — the client then has no idea, and a naive
 * retry can collide with server-side idempotency/duplicate guards. Declared
 * per-route via `client_timeout_ms` in routes.php (mirrors the `access:`
 * pattern above) rather than a global default: a blanket timeout would risk
 * prematurely aborting some OTHER platform's genuinely-slower endpoint this
 * change has no visibility into. Routes that don't set it keep today's
 * unbounded behavior, byte-identical to before this feature.
 */
export interface HttpRequestOptions {
  tokenMode?: 'public' | 'authentication' | 'authorization';
  timeoutMs?: number;
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

  async get<T = unknown>(path: string, params?: HttpParams, options?: HttpRequestOptions): Promise<T> {
    return this.request<T>('GET', path, undefined, params, options);
  }

  async post<T = unknown>(path: string, body?: unknown, options?: HttpRequestOptions): Promise<T> {
    return this.request<T>('POST', path, body, undefined, options);
  }

  async put<T = unknown>(path: string, body?: unknown, options?: HttpRequestOptions): Promise<T> {
    return this.request<T>('PUT', path, body, undefined, options);
  }

  async patch<T = unknown>(path: string, body?: unknown, options?: HttpRequestOptions): Promise<T> {
    return this.request<T>('PATCH', path, body, undefined, options);
  }

  // DELETE may carry an optional body (e.g. bulk-delete payloads). Mirrors post().
  async delete<T = unknown>(path: string, body?: unknown, options?: HttpRequestOptions): Promise<T> {
    return this.request<T>('DELETE', path, body, undefined, options);
  }

  /**
   * Join a base URL and a path into a single, correctly-separated URL.
   *
   * `environment.apiServer.host` is written with a trailing slash across the
   * fleet (CLIENT-SDK-SPEC convention) and call-site paths are written with a
   * leading slash (both typed generated methods and hand-written escape-hatch
   * callers like `ApiService.get('/products', …)`). A raw `base + path`
   * concat therefore produces a double slash (`https://api.example.com//products`)
   * whenever both sides carry their slash, which PHP's exact-match route
   * matcher does not normalize — the request 404s upstream of CORS handling.
   * This normalizes exactly one separator regardless of which side (if any)
   * already has one, so every combination — trailing/no-trailing base x
   * leading/no-leading path — yields a single slash.
   */
  private static joinUrl(base: string, path: string): string {
    const trimmedBase = base.replace(/\/+$/, '');
    const trimmedPath = path.replace(/^\/+/, '');
    return `${trimmedBase}/${trimmedPath}`;
  }

  private async request<T>(
    method: 'GET' | 'POST' | 'PUT' | 'DELETE' | 'PATCH',
    path: string,
    body?: unknown,
    params?: HttpParams,
    options?: HttpRequestOptions,
    isRetry = false,
  ): Promise<T> {
    const url = new URL(MinimalHttp.joinUrl(this.baseUrl, path));
    if (params) {
      for (const [k, v] of Object.entries(params)) {
        if (v !== undefined && v !== null) {
          url.searchParams.set(k, String(v));
        }
      }
    }

    // v4.8 access-aware token selection — see HttpRequestOptions. Defaults to
    // 'authorization' (the API token), today's only behavior, unchanged.
    const tokenMode = options?.tokenMode ?? 'authorization';
    const headers: Record<string, string> = { 'Content-Type': 'application/json' };
    if (tokenMode === 'authentication') {
      const token = this.tokens.getAuthToken();
      if (token) headers['Authorization'] = `Bearer ${token}`;
    } else if (tokenMode === 'authorization') {
      const token = this.tokens.get();
      if (token) headers['Authorization'] = `Bearer ${token}`;
    }
    // tokenMode === 'public' -> no Authorization header, even if a token
    // happens to be in storage.

    // v4.9 — see HttpRequestOptions.timeoutMs docblock. Undefined signal when
    // not set == today's unbounded behavior, unchanged.
    const signal = options?.timeoutMs !== undefined ? AbortSignal.timeout(options.timeoutMs) : undefined;

    let res: Response;
    try {
      res = await fetch(url.toString(), {
        method,
        headers,
        body: body !== undefined ? JSON.stringify(body) : undefined,
        signal,
      });
    } catch (networkErr) {
      if (networkErr instanceof DOMException && networkErr.name === 'TimeoutError') {
        // AbortSignal.timeout() fired before the server responded. The server
        // may still complete the request (it has no idea the client gave up)
        // — this is distinct from a genuine network failure, so callers get a
        // distinguishable message instead of the generic "check your connection".
        throw new ApiError(
          `Request timed out after ${options?.timeoutMs}ms. It may still complete on the server — please wait a moment before retrying.`,
          0,
          networkErr,
          'request_timeout',
        );
      }
      throw new ApiError('Network error — check your connection', 0, networkErr, null);
    }

    // The built-in refresh-and-retry cycle is scoped to the API token
    // (tokenMode 'authorization') — it's the only mode with a refresh token
    // in this TokenStore at all (see tokens.ts). A 401 on an 'authentication'
    // or 'public' call surfaces directly as an ApiError instead of attempting
    // an API-token refresh that has nothing to do with the credential (or
    // lack of one) that was actually sent.
    if (res.status === 401 && !isRetry && tokenMode === 'authorization') {
      const refreshed = await this.attemptRefresh();
      if (refreshed) {
        return this.request<T>(method, path, body, params, options, true);
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
      res = await fetch(MinimalHttp.joinUrl(this.baseUrl, this.refreshEndpoint), {
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
    // The StoneScriptPHP response envelope is a FIXED, non-deviating contract
    // ({status, message, data} — see framework-spec.md §"API Response
    // Contract") — every route response body, including refresh, is built via
    // ApiResponse('ok', [...]) / res_ok(...), which always nests payload
    // fields under `data`. A flat top-level access_token/refresh_token is not
    // a real shape this server ever sends (confirmed against RefreshRoute.php
    // and BaseExternalAuthRoute::proxyCall()) — trying both shapes here was
    // dead defensive code masking uncertainty about a contract that is
    // actually fixed (2026-07-21 no-fallback cleanup).
    const responseData = envelope?.['data'] as Record<string, unknown> | undefined;
    const newAccess = responseData?.['access_token'] as string | undefined;

    if (newAccess) {
      this.tokens.set(newAccess);
      const newRefresh = responseData?.['refresh_token'];
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
//
// 2026-07-06 storage redesign: this TokenStore is used ONLY for calling THIS
// platform's own business API (never the shared auth server) — it always
// wants the API token, the same one AuthService (ngx-stonescriptphp-client)
// stores under `ssp_api_access_token`. Deliberately sessionStorage for the
// standard (tenancy='multi') case, NOT localStorage: the API token is tenant+role-
// scoped and must be per-tab. A user in N tenants can hold N tabs, each acting
// as a different tenant (the "Gmail account-switcher" model) — a shared
// localStorage key would mean any tab that exchanged into a new tenant
// silently overwrote every other tab's active session (found 2026-07-06
// building a "Switch Org" UI that opens a tenant picker in a new tab of the
// same origin).
//
// 2026-08-01 single-token-mode fix (real bug, found live on a downstream
// platform's offline Android build, root-caused in two passes):
//
// Pass 1 (WRONG — storage location only): assumed TokenService just moved
// `ssp_api_access_token` from sessionStorage to localStorage in single-token
// mode (`apiUsesSession = tenancy === 'multi'`). Checking both storages for
// that same key still found nothing.
//
// Pass 2 (the REAL cause, confirmed by reading TokenService.setApiAccessToken()
// directly): in single-token mode TokenService does not write the
// `ssp_api_access_token` key AT ALL — `setApiAccessToken()` redirects to
// `setAuthAccessToken()`, i.e. the API token is stored ALIASED under
// `ssp_auth_access_token` (localStorage), because in that mode there is
// only ever one token for the life of the install and it IS the auth
// token. This generated TokenStore had no idea that key even existed, so
// `get()` always returned null, every business-API request went out with NO
// Authorization header, and the real android-server route correctly 401'd
// ("Unauthorized: Missing authentication token") — masked by the client's
// own generic "unexpected error" retry-exhaustion message, which is why this
// took a live device trace (Network domain + storage dump) to actually find.
//
// This class has no visibility into the platform's chosen tenancy mode at
// generation time (routes.php introspection doesn't carry that config), so
// the robust, mode-agnostic fix checks, in priority order: sessionStorage
// api-token (standard per-tab multi-tenant case) → localStorage api-token
// (defensive) → localStorage auth-token (the single-token-mode alias,
// last resort — never wins over a real api-token if one is genuinely
// present). Inert and correct regardless of which mode a given platform
// runs, without this file needing to know which one it is.
//
// Key names are owned by AUTH-SPEC §token-contract. Do not rename.
const ACCESS_KEY      = 'ssp_api_access_token';
const REFRESH_KEY     = 'ssp_api_refresh_token';
const AUTH_ACCESS_KEY = 'ssp_auth_access_token';
const AUTH_REFRESH_KEY = 'ssp_auth_refresh_token';

export class TokenStore {
  get(): string | null {
    if (typeof sessionStorage !== 'undefined') {
      const v = sessionStorage.getItem(ACCESS_KEY);
      if (v) return v;
    }
    if (typeof localStorage !== 'undefined') {
      const v = localStorage.getItem(ACCESS_KEY);
      if (v) return v;
      // Single-token-mode alias — see the 2026-08-01 note above.
      const auth = localStorage.getItem(AUTH_ACCESS_KEY);
      if (auth) return auth;
    }
    return null;
  }

  set(token: string): void {
    if (typeof sessionStorage !== 'undefined') sessionStorage.setItem(ACCESS_KEY, token);
    if (typeof localStorage !== 'undefined') localStorage.setItem(ACCESS_KEY, token);
  }

  /**
   * v4.8 (access-aware token selection). Reads the identity/auth token —
   * `ssp_auth_access_token`, always localStorage, never per-tab sessionStorage
   * (unlike the API token: the auth token is the SAME across every tab for
   * one logged-in user — it's the API/card token that's tenant-scoped and
   * needs per-tab isolation, see get()'s own docblock above). Used by
   * generated methods for routes declaring access: RouteAccess::AUTHENTICATION
   * (e.g. provision-tenant) — calls that run BEFORE a tenant-scoped API token
   * exists yet, so there is nothing in ACCESS_KEY to send. This is the exact
   * same key ngx-stonescriptphp-client's AuthService already writes — no new
   * storage convention introduced, just a second accessor for a key this
   * class could already partially see (as the single-token-mode alias in
   * get()) but had no direct, honestly-named way to read on its own terms.
   */
  getAuthToken(): string | null {
    return typeof localStorage !== 'undefined' ? localStorage.getItem(AUTH_ACCESS_KEY) : null;
  }

  getRefresh(): string | null {
    if (typeof sessionStorage !== 'undefined') {
      const v = sessionStorage.getItem(REFRESH_KEY);
      if (v) return v;
    }
    if (typeof localStorage !== 'undefined') {
      const v = localStorage.getItem(REFRESH_KEY);
      if (v) return v;
      const auth = localStorage.getItem(AUTH_REFRESH_KEY);
      if (auth) return auth;
    }
    return null;
  }

  setRefresh(token: string): void {
    if (typeof sessionStorage !== 'undefined') sessionStorage.setItem(REFRESH_KEY, token);
    if (typeof localStorage !== 'undefined') localStorage.setItem(REFRESH_KEY, token);
  }

  clear(): void {
    if (typeof sessionStorage !== 'undefined') {
      sessionStorage.removeItem(ACCESS_KEY);
      sessionStorage.removeItem(REFRESH_KEY);
    }
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

    // v4.7 hard-error guard: T3 routes MUST carry the /{service}/tenant/{tenantId}
    // prefix the URL-template builder strips — otherwise it silently emits a
    // doubled-prefix 404ing URL (see assertT3RoutesCarryTenantPrefix() docblock).
    if ($isTenantScoped) {
        assertT3RoutesCarryTenantPrefix($serviceRoutes, $serviceName);
    }

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
        $bodyEscapeArg = 'this.escapePath(path)';
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
        $bodyEscapeArg = 'path';
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

  /** Escape hatch — see {@link ApiClient.get}.{$postDocSuffix} */
  put<R = unknown>(path: string, body?: unknown): Promise<R> {
    return this.http.put<R>({$bodyEscapeArg}, body);
  }

  /** Escape hatch — see {@link ApiClient.get}.{$postDocSuffix} */
  patch<R = unknown>(path: string, body?: unknown): Promise<R> {
    return this.http.patch<R>({$bodyEscapeArg}, body);
  }

  /** Escape hatch — see {@link ApiClient.get}.{$postDocSuffix} */
  delete<R = unknown>(path: string, body?: unknown): Promise<R> {
    return this.http.delete<R>({$bodyEscapeArg}, body);
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
 * @param bool   $isTenantScoped Service-level default: true when T3 non-admin
 *                                 service. Overridden PER ROUTE to false for
 *                                 any route routeIsTenantUrlExempt() flags
 *                                 (access: authentication|public) — see that
 *                                 function's docblock.
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

        // v9.1.0+9.3.0: access-aware token selection AND per-route tenant-URL
        // exemption both key off the same routeIsTenantUrlExempt() predicate —
        // an identity-scoped (`authentication`) or `public` route is neither
        // tenant-URL-scoped nor sent with the API token, regardless of which
        // service it's tagged under (see routeIsTenantUrlExempt() docblock).
        // Computed BEFORE building the URL template (moved up from below) so
        // buildUrlTemplate()/templateNeedsIdParam() get the per-route override,
        // not the blanket service-level $isTenantScoped — otherwise an exempt
        // route still got ${this.t} prepended, reproducing the exact doubled-
        // URL bug assertT3RoutesCarryTenantPrefix() exists to prevent.
        $accessType = $route['access'] ?? null;
        $routeIsTenantScoped = $isTenantScoped && !routeIsTenantUrlExempt($route);

        // v4.9: opt-in per-route client-side fetch timeout — see
        // HttpRequestOptions.timeoutMs docblock in verbatimHttpTs(). Mirrors
        // the `access:` pattern: a route declares it in routes.php, the
        // generator threads it through, unset routes are unaffected.
        $timeoutMs = isset($route['client_timeout_ms']) ? (int) $route['client_timeout_ms'] : null;

        // Build TypeScript URL template
        $urlTemplate = buildUrlTemplate($path, $routeIsTenantScoped, $serviceName);

        // Determine if the emitted URL template will contain ${id} and therefore
        // whether the method signature must declare `id: string | number`.
        // Previously used hasTailId() — WRONG: only detected tail params.
        // The URL template replaces ALL {param} segments with ${id}, so any route
        // with a non-tail {id} (e.g. /routes/{id}/start) also needs the id param.
        $needsIdParam = templateNeedsIdParam($path, $serviceName, $routeIsTenantScoped);

        // Resolve typed return (CLIENT-SDK-SPEC §10). null → ApiResponse fallback.
        $responseTs = routeResponseTsType($route);

        // Resolve typed request body (v9.6.0, CLIENT-SDK-SPEC §10 amendment).
        // null → T.ApiRequestBody fallback. Meaningless on GET (buildMethodTs
        // only consumes it inside the body-verb branch) — computed unconditionally
        // for simplicity, same pattern as $responseTs above.
        $requestTs = routeRequestTsType($route);

        // Build method signature and call
        $methodTs = buildMethodTs($action, $method, $urlTemplate, $needsIdParam, $responseTs, $accessType, $timeoutMs, $requestTs);
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
 * Reads → GET; writes → POST/PUT/PATCH/DELETE. Path id param always typed as
 * string | number (A5). Body verbs (POST/PUT/PATCH/DELETE) without path id take
 * a data parameter; with path id take (id, data?). DELETE's body is optional.
 * GET methods without path id take optional HttpParams; with id take (id, params?).
 *
 * $needsIdParam: true when the URL template contains `${id}` — i.e. the route
 * path has any {param} placeholder in its non-tenant portion. This covers both
 * tail-id routes (/items/{id}) AND mid-path routes (/routes/{id}/start). The
 * previous parameter was named $tailId and used hasTailId() — which only
 * detected TAIL params, missing /routes/{id}/start shapes (v4.6.0 fix).
 *
 * Typed returns (CLIENT-SDK-SPEC §10, v4.3.0): when $responseTs is non-null
 * (the route declared `response:`), the http generic + Promise are typed to that
 * DTO type (e.g. `Promise<T.Warehouse[]>` / `this.http.get<T.Warehouse[]>(...)`).
 * When null, the method keeps the `T.ApiResponse` (= unknown) fallback.
 *
 * @param bool        $needsIdParam True when the URL template will contain `${id}`
 *                                   (replaces $tailId — see templateNeedsIdParam()).
 * @param string|null $responseTs   Resolved TS return-payload type ('T.Warehouse[]'),
 *                                   or null for the ApiResponse fallback.
 * @param string|null $accessType   v4.8: the route's RouteAccess value —
 *                                   'public' | 'authentication' | 'authorization' | null.
 *                                   Threaded through as the actual value (not a
 *                                   derived boolean) so 'public' can mean "send no
 *                                   token at all", distinct from 'authentication'
 *                                   (identity/auth token). null/'authorization'
 *                                   (unset routes default to authorization) both
 *                                   mean today's only behavior — the API token —
 *                                   and emit no extra call-site argument, so every
 *                                   existing generated client is byte-identical to
 *                                   before this feature for the common case. See
 *                                   verbatimHttpTs()/verbatimTokensTs().
 * @param int|null    $timeoutMs    v4.9: the route's `client_timeout_ms`, or null
 *                                   for today's default (unbounded fetch, unchanged).
 *                                   See HttpRequestOptions.timeoutMs docblock.
 * @return string TypeScript source for one method entry (including trailing comma)
 */
function buildMethodTs(
    string  $action,
    string  $httpMethod,
    string  $urlTemplate,
    bool    $needsIdParam,
    ?string $responseTs = null,
    ?string $accessType = null,
    ?int    $timeoutMs = null,
    ?string $requestTs = null,
): string {
    // Return-payload generic: typed DTO when declared, else ApiResponse (unknown).
    $ret = $responseTs ?? 'T.ApiResponse';

    // v4.8/v4.9: emitted as a trailing options object only when at least one
    // field deviates from today's defaults (authorization token, unbounded
    // fetch) — see the $accessType/$timeoutMs docblocks above for why this
    // keeps the common case byte-identical to every client generated before
    // either feature existed.
    $optionsFields = [];
    if ($accessType === 'public' || $accessType === 'authentication') {
        $optionsFields[] = "tokenMode: '{$accessType}'";
    }
    if ($timeoutMs !== null) {
        $optionsFields[] = "timeoutMs: {$timeoutMs}";
    }
    $optionsArg = $optionsFields ? ', { ' . implode(', ', $optionsFields) . ' }' : '';

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
        if ($needsIdParam) {
            // GET with path id — e.g. inventory.get(id) or routes.shipments(id).
            // No `params` slot here, so an `undefined` placeholder is only
            // inserted when $optionsArg is actually non-empty — the common
            // (unset access) case stays byte-identical to before this feature.
            $getIdArgs = $optionsArg !== '' ? ", undefined{$optionsArg}" : '';
            return <<<TS
    {$action}: (id: string | number) =>
      this.http.get<{$ret}>({$urlTemplate}{$getIdArgs}),
TS;
        } else {
            // GET list / search / action — e.g. inventory.list(params?)
            return <<<TS
    {$action}: (params?: HttpParams) =>
      this.http.get<{$ret}>({$urlTemplate}, params{$optionsArg}),
TS;
        }
    }

    if ($bodyVerb !== null) {
        // v9.6.0 (CLIENT-SDK-SPEC §10 amendment): a route may declare
        // `request: SomeDto::class` to type the body parameter instead of the
        // generic `T.ApiRequestBody` fallback. Mirrors the `response:` DTO
        // reflection this generator already does — see reflectDto()/
        // routeRequestTsType() below.
        $dataTs = $requestTs ?? 'T.ApiRequestBody';

        if ($needsIdParam) {
            // Body verb with path id — e.g. inventory.update(id, data?) / routes.start(id, data?)
            return <<<TS
    {$action}: (id: string | number, data?: {$dataTs}) =>
      this.http.{$bodyVerb}<{$ret}>({$urlTemplate}, data{$optionsArg}),
TS;
        } else {
            // Body verb without path id — e.g. inventory.create(data?)
            return <<<TS
    {$action}: (data?: {$dataTs}) =>
      this.http.{$bodyVerb}<{$ret}>({$urlTemplate}, data{$optionsArg}),
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
 * Resolve a route's `request` declaration into the TS request-body type used
 * for the generated method's `data` parameter. Returns null when the route
 * declares no `request` (caller keeps the `T.ApiRequestBody`/unknown fallback).
 *
 * v9.6.0 — mirrors {@see routeResponseTsType()} exactly, using the same
 * `reflectDto()` machinery (recursive, deduped, cycle-safe). A request body
 * is always a single object — there is no `collection` concept on the way
 * IN (unlike `response`, which may legitimately be a bare JSON array).
 *
 * @return string|null e.g. 'T.CreateItemRequest', or null.
 */
function routeRequestTsType(array $route): ?string
{
    $dto = $route['request'] ?? null;
    if ($dto === null || $dto === '') {
        return null;
    }
    $tsName = reflectDto($dto);
    if ($tsName === 'unknown') {
        return null; // class missing — graceful fallback
    }
    return 'T.' . $tsName;
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
 *
 * @param string $serviceName   e.g. 'portal', 'admin'
 * @param string $packageName   Derived npm package name (generate-api-client-spec.md §"Package Naming"):
 *                              {composer-name}-{serviceName}-client (e.g. 'exampleapp-api-portal-client')
 */
function generatePackageJson(string $serviceName, string $packageName): string
{
    return json_encode([
        'name'        => $packageName,
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
// Orphan cross-product cleanup (v4.6)
// ============================================================================

/**
 * Remove orphan nested API-client packages left behind by pre-v4.0 generator versions.
 *
 * Before v4.0, the generator was invoked per-consumer with the consumer as the scope
 * and emitted all service packages nested under that consumer:
 *   client/{consumer}/{service}/   (e.g. client/admin/finance/, client/ats/finance/)
 * These dirs accumulate in git and cause lint failures because they lack package-lock.json
 * (they were never npm-installed in the nested location). The current generator uses a
 * flat layout (client/{service}/) which is the only correct layout.
 *
 * This function runs once at the start of every generate-client invocation. It:
 *   1. Enumerates service-package dirs at depth 1 from $outputBaseDir (skipping
 *      known non-package dirs: node_modules, src, dist, .git).
 *   2. Inside each service-package dir, finds sub-directories that contain a
 *      package.json (i.e., are themselves nested API-client packages).
 *   3. Removes those nested dirs entirely (recursively).
 *   4. Logs each removal and returns the total count.
 *
 * Safe guards:
 *   - Only processes dirs at exactly depth 2 (client/{service}/{orphan}/).
 *   - Skips src/, dist/, node_modules/, streaming/ subdirs — these are legitimate
 *     sub-dirs of a flat service package and must not be removed.
 *   - Only removes a nested dir when it contains a package.json — the presence of
 *     a package.json is the unambiguous signal that it is an npm package, not user content.
 *   - Does NOT remove the flat service packages themselves (depth 1).
 *
 * @param string $outputBaseDir Absolute path to the client output root (e.g. /…/docker/api/client)
 * @return int   Number of orphan directories removed
 */
function removeOrphanNestedPackages(string $outputBaseDir): int
{
    if (!is_dir($outputBaseDir)) {
        return 0;
    }

    /** Sub-dir names inside a flat service package that are NOT orphan nested packages */
    $skipNames = ['node_modules', 'src', 'dist', 'streaming', '.git', '.'];

    $removed = 0;

    // Enumerate depth-1 entries: $outputBaseDir/{item}
    $depth1 = scandir($outputBaseDir);
    if ($depth1 === false) {
        return 0;
    }

    foreach ($depth1 as $item1) {
        if ($item1 === '.' || $item1 === '..') {
            continue;
        }
        $serviceDir = $outputBaseDir . DIRECTORY_SEPARATOR . $item1;
        if (!is_dir($serviceDir)) {
            continue;
        }

        // Skip dirs that are not service packages (no package.json at this level)
        if (!file_exists($serviceDir . '/package.json')) {
            continue;
        }

        // This is a flat service package dir (e.g. client/admin/).
        // Now look for nested package dirs inside it.
        $depth2 = scandir($serviceDir);
        if ($depth2 === false) {
            continue;
        }

        foreach ($depth2 as $item2) {
            if ($item2 === '.' || $item2 === '..') {
                continue;
            }
            if (in_array($item2, $skipNames, true)) {
                continue;
            }

            $nestedDir = $serviceDir . DIRECTORY_SEPARATOR . $item2;
            if (!is_dir($nestedDir)) {
                continue;
            }

            // Remove only if this nested dir is itself an npm package
            if (!file_exists($nestedDir . '/package.json')) {
                continue;
            }

            // Orphan confirmed — remove it recursively
            removeDirectoryRecursive($nestedDir);
            echo "  Removed orphan nested package: {$item1}/{$item2}\n";
            $removed++;
        }
    }

    return $removed;
}

/**
 * Recursively delete a directory and all its contents.
 *
 * Uses RecursiveIteratorIterator so it handles arbitrarily deep trees.
 * Silently returns if $dir does not exist.
 *
 * @param string $dir Absolute path to the directory to delete
 */
function removeDirectoryRecursive(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getRealPath());
        } else {
            unlink($item->getRealPath());
        }
    }

    rmdir($dir);
}

// ============================================================================
// Main execution
// ============================================================================

// Allow the file to be included for unit testing without triggering generation.
// When GENERATE_CLIENT_TESTING is defined, function definitions above are loaded
// but the main execution block below is skipped.
if (defined('GENERATE_CLIENT_TESTING')) {
    return;
}

// Purge orphan nested packages left by pre-v4.0 generator before writing anything (v4.6)
$orphansRemoved = removeOrphanNestedPackages($outputBaseDir);
if ($orphansRemoved > 0) {
    echo "Cleaned up $orphansRemoved orphan nested package(s) from previous generator version.\n\n";
}

echo "Scanning routes...\n";
$allRoutes = loadRoutesFromPlatform();
echo "Found " . count($allRoutes) . " route(s)\n";

// Classify routes
$included        = [];  // service → [route, ...]
$skippedReasons  = [];  // path → reason
$streamingByService = []; // service → [streaming route, ...]
$typedContractIssues = []; // "METHOD /path: reason" — Phase 3 gate, see typedContractViolations()

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

    // Phase 3 (v9.6.0): record typed-contract gaps for routes that will
    // actually be generated this run. Reporting (warn vs hard-fail) happens
    // once, after the full route list has been classified — see below.
    foreach (typedContractViolations($route) as $violation) {
        $typedContractIssues[] = strtoupper($route['method'] ?? '?') . ' ' . ($route['path'] ?? '?') . ': ' . $violation;
    }

    $included[$service][] = $route;
}

// Phase 3 (v9.6.0): report typed-contract gaps. Default (STONE_CLIENT_GEN_STRICT_TYPES
// unset, no --strict-types) = WARNING, generation proceeds with the existing
// ApiResponse/ApiRequestBody (= unknown) fallback — byte-identical to pre-v9.6.0
// behavior for every platform that hasn't opted in yet. Strict mode = hard
// failure, exit(1), nothing written — see CLIENT-SDK-SPEC §10 amendment.
if (!empty($typedContractIssues)) {
    if ($strictTypedContracts) {
        fwrite(STDERR, "\n[stone generate client] ERROR: --strict-types (or STONE_CLIENT_GEN_STRICT_TYPES) is enabled and " . count($typedContractIssues) . " route(s) lack a full typed contract:\n\n");
        foreach ($typedContractIssues as $issue) {
            fwrite(STDERR, "  - $issue\n");
        }
        fwrite(STDERR, "\nDeclare `response:` (and `request:` for POST/PUT/PATCH/DELETE) DTOs on every route above,\n"
            . "or drop --strict-types / STONE_CLIENT_GEN_STRICT_TYPES for now. Generation aborted — nothing written.\n");
        exit(1);
    }

    fwrite(STDERR, "\n[stone generate client] WARNING: " . count($typedContractIssues) . " route(s) lack a full typed contract (falls back to ApiResponse/ApiRequestBody = unknown):\n");
    foreach ($typedContractIssues as $issue) {
        fwrite(STDERR, "  - $issue\n");
    }
    fwrite(STDERR, "Set --strict-types (or STONE_CLIENT_GEN_STRICT_TYPES=1) to make this a hard failure once every route on this platform is typed.\n\n");
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

    // Derive npm package name per generate-api-client-spec.md §"Package Naming" (v4.5).
    // Rule: {composer-name}-{serviceName}-client — each service package gets its own name.
    // The service name (from routes.php) is used, NOT the deprecated $scopeArg, so that
    // all packages emitted in a single run get distinct correct names:
    //   portal → {composer-name}-portal-client
    //   admin  → {composer-name}-admin-client
    $packageName = derivePackageName($composerName, $serviceName);

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
    file_put_contents($packageDir . '/package.json',    generatePackageJson($serviceName, $packageName));
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
    echo "  npm name: $packageName\n";
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
