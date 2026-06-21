# Changelog

All notable changes to StoneScriptPHP will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [4.5.0] - 2026-06-21

### Fixed

- **Multi-scope clobber — every multi-service platform affected (`cli/generate-client.php` main generation loop).** When a `routes.php` declares multiple backend services (e.g. `portal` + `admin`), running `php stone generate client portal` then `php stone generate client admin` caused the second run to overwrite the first run's `portal/package.json` name with the admin scope — leaving both packages named `...-admin-client`. Root cause: `derivePackageName()` was called with `$scopeArg` (the CLI argument) instead of `$serviceName` (the service currently being generated). Every multi-service platform was affected (medstoreapp, logisticsapp, emcircuitsystems, aasaanwork, progalaxyelabs, and others). Fixed by passing `$serviceName` to `derivePackageName()` so each service package always gets its own correct name regardless of which scope arg was passed on the CLI.

### Changed

- **`<scope>` positional argument is now OPTIONAL and DEPRECATED.** The argument is accepted without error for backward compatibility but no longer affects the generated package names — those now derive from each service's name in `routes.php`. A deprecation notice is emitted to stderr when the arg is supplied. Remove it from your `php stone generate client` invocations; it will be removed in v5. The recommended invocation is now simply `php stone generate client` (or with flags like `--tenancy=T2`).
- **`derivePackageName(string $composerName, string $serviceName)` parameter rename.** The second parameter was previously described as `$scope` (the CLI arg); it is now correctly documented as `$serviceName` (the routes.php service name). The calling convention is unchanged.

### Tests

- Added `test_generator_package_name_uses_service_name_not_scope_arg` — asserts that passing `scope=www` does NOT affect package names when the services in routes.php are `portal` and `admin`.
- Added `test_multi_scope_sequential_runs_do_not_clobber_package_names` — the exact regression test for the multi-scope clobber bug: runs the generator twice (scope=portal, then scope=admin) and asserts `portal/package.json` is not overwritten by the second run.
- Added `test_generator_succeeds_when_scope_arg_omitted` — verifies the generator exits 0 and produces correct per-service names when no scope arg is provided (v4.5 behavior).
- Replaced `test_generator_package_naming_canonical_examples` — now tests vendor-prefixed and bare composer names, both producing per-service distinct names.
- Replaced `test_vendor_prefix_with_www_scope` with `test_vendor_prefix_each_service_gets_distinct_scoped_name` — verifies both `portal` and `admin` services get distinct `@vendor/pkg-{service}-client` names.
- Updated `test_non_vendor_prefixed_composer_name_keeps_unscoped_form` — now asserts both `portal` and `admin` packages get distinct unscoped names.
- Updated `test_empty_composer_name_falls_back_to_service_name_client` — renamed from `test_empty_composer_name_falls_back_to_scope_client`; verifies fallback uses service name, not scope arg.
- Updated `test_scope_parsed_from_dispatcher_adjusted_argv_not_raw_argv` (Bug 1 regression) — updated expected name from scope-based to service-based; anti-regression assertions still verify stone subcommand tokens (`"generate"`, `"client"`) don't appear in generated names.
- Total: 66 tests in ClientGeneratorV4Test (was 65); full suite 315 tests all passing.

## [4.4.1] - 2026-06-21

### Fixed
- **Bug 1 — scope arg mis-parsed on the real `stone` dispatch path (`cli/generate-client.php` line ~84).** When invoked as `php stone generate client <scope>`, the `stone` dispatcher sets `$_SERVER['argv']` to `[$scriptPath, $scope, ...flags]` and then `require`s the generator. The global `$argv` still held the full stone invocation (`stone generate client <scope>`), so the generator's first-non-flag pick-up would grab `"generate"` instead of the actual scope — producing invalid package names ending in `-generate-client`. Fixed by rewriting `$argv` from `$_SERVER['argv']` at the top of `generate-client.php`. `--service=` workaround is no longer required.
- **Bug 2 — vendor-prefixed composer name produced an invalid npm package name (`cli/generate-client.php` `derivePackageName()` ~line 208).** The original rule emitted `{composer-name}-{scope}-client` using the composer name AS-IS. Composer names with a vendor prefix (e.g. `progalaxyelabs/progalaxy-api`) produced `progalaxyelabs/progalaxy-api-portal-client` — an invalid npm name (bare slash without `@`). Fixed: when the composer `name` contains a `/`, the generator now emits the valid npm scoped form `@{vendor}/{pkg}-{scope}-client` (e.g. `@progalaxyelabs/progalaxy-api-portal-client`). Non-vendor names (no slash) keep the existing unscoped form `{name}-{scope}-client` unchanged.

## [4.4.0] - 2026-06-19

### Added
- **`php stone generate client <scope>` — scope-derived package naming.** The `<scope>` positional argument (the Angular service directory name: `portal`, `admin`, `www`, `business`, …) is now **required**. The generator derives the npm package `name` deterministically as `{composer.json name}-{scope}-client` (e.g. `medstoreapp-api` + `portal` → `medstoreapp-api-portal-client`). This replaces the prior `@stonescript/api-client-{service}` convention. The `--service=` filter remains for single-package generation. Omitting `<scope>` is a hard error with a usage message.

## [4.3.1] - 2026-06-19

### Fixed
- **Escape-hatch passthroughs now cover all five verbs (CLIENT-SDK-SPEC §12).** The generated `ApiClient` previously exposed only `get` and `post` escape-hatch methods; `put`, `patch`, and `delete` were absent. Services calling PUT/DELETE/PATCH routes via the escape hatch (rather than via typed `api.<group>.<action>()` methods) received a TypeScript compile error. The generator now emits matching `put`/`patch`/`delete` passthroughs that mirror the `post` shape exactly: same `body?: unknown` signature, same `escapePath()` tenant-awareness for T3 portal clients, same verbatim path pass-through for admin/T2 clients. `MinimalHttp` already carried these verbs since v4.2.0 — this fix wires them to the escape-hatch surface.

## [4.3.0] - 2026-06-19

### Added
- **Typed return types in the generated client (CLIENT-SDK-SPEC §10).** A route may now declare a response DTO via a `'response' => SomeDto::class` slot (plus optional `'collection' => true`). `php stone generate client` reflects the DTO's public typed properties into a TypeScript `interface` emitted in `src/types.ts`, and types the generated method `Promise<Dto>` (single) or `Promise<Dto[]>` (collection) with a matching `this.http.<verb><Dto[]>(...)` generic. Consumers call typed endpoints with **zero casts**. Routes with **no** `'response'` slot are unchanged — they keep the `ApiResponse` (= `unknown`) fallback, so the feature is fully incremental and graceful.
  - PHP→TS type mapping: `int`/`float`→`number`, `string`→`string`, `bool`→`boolean`, `?T`→`T | null` + optional `?`, `DateTimeInterface`→`string`, untyped/bare `array`→`unknown[]`, a `/** @var Foo[] */` (or `array<Foo>`) docblock array→`Foo[]`, a nested DTO class→its own interface (emitted recursively, deduped, cycle-safe), a string-backed enum→a string-literal union (other enums→`string`), union/intersection/`mixed`→`unknown`.
  - The route metadata pipeline (`RouteEntry`, `Router::normalizeRouteConfig`, `Router::addRoute`/`get`/`post`, `Router::getRouteMeta`) now threads the `response` and `collection` keys through to the generator. Backward-compatible additive change → MINOR bump 4.2.0 → 4.3.0.

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
