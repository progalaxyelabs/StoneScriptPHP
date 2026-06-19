# Changelog

All notable changes to StoneScriptPHP will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [4.2.0] - 2026-06-19

### Added
- **Generated client now supports PUT/DELETE/PATCH.** `php stone generate client` previously emitted a `Promise.reject(new Error('Unsupported HTTP method'))` stub for any non-GET/POST route, making every PUT/DELETE/PATCH endpoint uncallable fleet-wide. The emitted `MinimalHttp` transport now has `put()`, `patch()`, and `delete()` methods that delegate to the same private `request()` as `post()` — identical auth-header injection, 401-refresh retry, and error handling across all verbs. The method-emission switch now emits real typed methods (`this.http.put/patch/delete(...)`) for these verbs. DELETE carries an optional body.

### Changed
- **`ApiResponse` generated type is now `unknown`** (was `Record<string, unknown> | unknown[] | null`). The old union's `unknown[]` member broke strict narrowing and forced consumers into `as unknown as X` double-casts; `unknown` lets consumers narrow with a single `as X`. (CLIENT-SDK-SPEC §6)

### Added (earlier in Unreleased)
- New `php stone generate contract` CLI command for auto-generating contract interfaces and DTOs from route handlers
- Uses PHP Reflection to extract public properties from route classes without requiring AI
- Automatically infers required/optional fields from `validation_rules()` method
- Generates typed Request/Response DTOs with `readonly` constructor parameters
- Supports `--dry-run` flag for previewing generated files
- Supports `--force` flag for overwriting existing contracts
- Can generate for a single route or all routes at once
- Skips routes that already have contracts unless `--force` is used

## [3.21.0] - 2026-05-01

### Fixed
- **CORS preflight blocked PUT/PATCH/DELETE from browsers.** `CorsMiddleware` defaulted `Access-Control-Allow-Methods` to `GET, POST, OPTIONS`, so any state-changing request from a browser failed preflight with "Did not find method in CORS header 'Access-Control-Allow-Methods'". Default widened to `GET, POST, PUT, PATCH, DELETE, OPTIONS`.
- `Application` now sources allowed methods from `ALLOWED_METHODS` env (falls back to the new wider default), matching the `ALLOWED_ORIGINS` pattern.
- Stale `Access-Control-Allow-Methods: POST, GET, OPTIONS` fallbacks in `src/Router.php` and `cli/cli-server-router.php` updated to match. These only fire on error/404 paths and the dev cli-server, but would have leaked the old narrow list and confused debugging.

### Notes
- No server-side authorization change — the methods header is browser-side only; server already accepts whatever the routes table declares. JWT/scope/tenant middleware unchanged.
- Browsers may continue to fail PUT/PATCH/DELETE for up to `Access-Control-Max-Age` (900s) after deploy due to cached preflights.

## [3.14.0] - 2026-03-22

### Added
- **Route scope support** — routes in `routes.php` can now declare a `scope` (e.g., `portal`, `admin`, `shared`)
- New `RouteEntry` value object (`src/Routing/RouteEntry.php`) to hold handler, scope, and alias metadata
- `Router::normalizeRouteConfig()` static method for parsing both old string format and new array format
- `Router::scope()` method for grouping scope-specific middleware:
  ```php
  $router->scope('portal', function($r) {
      $r->use(new GatewayTenantMiddleware());
  });
  ```
- `ScopeMiddlewareBuilder` class for clean middleware registration within a scope
- `Router::getRouteMeta()` and `Router::getKnownScopes()` methods for introspecting route metadata
- Scope metadata included in `$request['route']['scope']` during dispatch
- `--scope` flag for `php stone generate client` — generates client with only scope + shared routes
- Alias support: routes marked `'alias' => true` are routable but excluded from client generation
- Optional top-level `'scopes'` key in routes.php for documenting available scopes
- Scope-aware resource name extraction strips scope prefix (e.g., `/portal/invoices` → resource `invoices`)

### Changed
- `Router::loadRoutes()` now supports route values as arrays: `['handler' => class, 'scope' => '...', 'alias' => bool]`
- Legacy `RequestParser` (old Router) normalizes new array format via `normalizeRoutes()` for backward compatibility
- `extractResourceName()` and `pathToMethodName()` in generate-client now accept `$knownScopes` parameter
- Scope-specific middleware runs after global middleware but before route-specific middleware

## [2.9.0] - 2026-02-11

### Added
- Built-in `/health` endpoint in Router for automatic health checks
- Default health check returns `{"status": "ok", "service": "stonescriptphp-api", "timestamp": "<ISO8601>"}` format
- Platform APIs can still override `/health` with custom implementation if needed

## [2.4.3] - 2026-01-16

### Changed
- Removed serve/stop commands from framework CLI (these are application-level commands, not framework commands)
- Framework CLI now focuses on code generation and framework utilities

## [2.4.2] - 2026-01-08

### Repository Cleanup
- Cleaned up repository for open-source distribution
- Improved documentation structure
- Updated package metadata

### Documentation
- Fixed broken documentation links in README.md
- Updated version references throughout documentation
- Consolidated documentation links to point to https://stonescriptphp.org/docs
- Updated HLD.md to reflect current architecture and version

### Changed
- Documentation now primarily hosted on official website
- Local docs/ directory removed in favor of online documentation

## Previous Versions

For versions prior to 2.4.2, please refer to:
- [GitHub Releases](https://github.com/progalaxyelabs/StoneScriptPHP/releases)
- [Git commit history](https://github.com/progalaxyelabs/StoneScriptPHP/commits/main)

---

## Version History Summary

- **2.4.x** - Current stable release with production improvements
- **2.3.x** - Enhanced authentication and security features
- **2.2.x** - Caching system improvements
- **2.1.x** - CLI tools enhancement
- **2.0.x** - Major framework refactor with PostgreSQL-first architecture
- **1.x.x** - Initial stable releases

For detailed upgrade guides, visit: https://stonescriptphp.org/docs/upgrade
