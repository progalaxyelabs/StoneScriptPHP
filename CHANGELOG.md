# Changelog

All notable changes to StoneScriptPHP will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [7.0.0] - 2026-07-15

**BREAKING RELEASE.** The typed access/token_type route model + typed-auth
middleware are now the framework's single, strict auth model — there is no pre-7.0
fallback. Every platform with protected routes MUST migrate on upgrade (declare
`access`/`token_type` and wire `AuthMiddlewareRegistrar::create()`), or the app
fails fast at boot with an actionable migration error. See **BREAKING CHANGES** and
**Migration** below, and `UPGRADING-7.0.md`. Also folds in the CORS fail-closed fix.

### Fixed — CORS: fail-closed by default, allowed-origins.php actually wired up

Found live during a progalaxy-platform browser test: a real Google-OAuth login
completed server-side (200 OK) but the browser blocked reading the response —
`Access-Control-Allow-Origin` was silently missing for a real, listed dev
origin. Root cause was three compounding framework bugs, not one:

- `Env::$ALLOWED_ORIGINS` defaulted to a hardcoded, generic
  `'http://localhost:3000,http://localhost:4200'` — silently wrong for any
  platform running its Angular dev server on a different port (every real
  platform in the fleet does), with no loud failure to notice it by.
- `Application.php`'s `$env->ALLOWED_ORIGINS ?? '*'` fallback was dead code —
  `Env::$ALLOWED_ORIGINS` is a non-nullable typed string with an explicit
  default, so it is never actually `null`. The intended "unconfigured ->
  allow all" fallback never ran, and would have been unsafe if it had:
  `CorsMiddleware` always sends `Access-Control-Allow-Credentials: true`, and
  `Access-Control-Allow-Origin: *` combined with credentials is invalid in
  browsers and a real security hole.
- `src/config/allowed-origins.php` has been scaffolded by the skeleton since
  day one but was never read by `Application.php`/`Env.php` at all — every
  platform that populated it (following the skeleton's implication that it
  does something) was configuring dead weight.

Fix:
- **`Env`** now reads `src/config/allowed-origins.php` (if the app defines
  one) as the base for `ALLOWED_ORIGINS`, with the `ALLOWED_ORIGINS` env var
  still overriding it exactly like every other typed `Env` property already
  resolves (env > default). Unconfigured (neither file nor env var) is now
  genuinely empty — fail-closed, never `'*'`. The config file is validated at
  the **token level before it is ever executed**: only a bare
  `return [...]` array-literal-of-strings is accepted. This isn't a style
  rule — this file is read mid-`Env::__construct()`, before
  `Env::get_instance()` is safe to call reentrantly and before most of
  `Env`'s own properties are resolved, so a config file calling any
  function (`Env::get_instance()`, `getenv()`, `array_merge()`, closures,
  `new`, etc.) would run in that same unsafe window. Rejecting the file at
  the token level (`Env::isPureArrayReturnSource()`) guarantees the code in
  it never executes at all, rather than merely discouraging it in a comment.
  A defensive `self::$_instance = $this;` was also added as the first line
  of `Env::__construct()`, closing a real reentrancy hazard this change
  could otherwise have introduced (a config file calling
  `Env::get_instance()` before this guard would recurse into a second
  construction — infinite loop / boot crash).
- **`CorsMiddleware`**: a configured `'*'` is stripped (with a loud
  `log_warning`, not silently dropped or, worse, honored) rather than ever
  being treated as match-all, for the credentials-safety reason above.
  Origin matching is now case-insensitive on both sides (configured origins
  are lowercased too, not just the incoming header). The origin-match
  decision (`resolveAllowedOrigin()`) is extracted as its own pure,
  independently-testable method.

Verified: 616/616 tests pass (600 pre-existing + 16 new, including a test
that a config file calling `Env::get_instance()` is rejected without ever
executing, not merely discouraged). Root cause was reproduced live against
progalaxy-platform (missing `Access-Control-Allow-Origin` on both the
`OPTIONS` preflight and the actual `POST /api/auth/exchange` for a real
browser `Origin: http://localhost:3040` header) before this fix was written;
re-verification against progalaxy-platform with this exact framework version
is a separate, subsequent step — not yet done as of this commit.

### Added — Typed auth-token model (access/refresh, authentication/authorization)

Every auth token now carries two orthogonal, strictly-checked claims, routes
declare a typed access model, and refresh tokens are DB-gated with hard revocation.
This is the framework's single auth model in 7.0.0 — strict, no opt-out. See
**BREAKING CHANGES** for what every consuming platform must do on upgrade.

- **`StoneScriptPHP\Auth\TokenClaims`** — the typed vocabulary. `type` ∈
  {`access`, `refresh`} (what the credential IS) and `purpose` ∈
  {`authentication`, `authorization`} (what it is FOR). These are DISTINCT from
  the pre-existing `token_type` (card/platform) CLASS claim — not an overload.
- **`RsaJwtHandler::generateToken()`** now stamps `type` (from its token-type arg,
  previously used only to pick the expiry) and takes + stamps a new `$purpose`
  argument. Both are first-class defaults; an explicit `$payload` value still wins
  (so the internal OAuth `purpose=oauth_state` marker is preserved).
  `HybridCardJwtHandler::generateToken()` gains the same `$purpose` passthrough.
- **`TokenExchangeService::exchangeCard()`** stamps `purpose=authorization` and a
  `type` (new `$tokenType` arg, default `access`) on cards, alongside the existing
  `token_type=card`. `validateIdentityToken()` gains an optional
  `$expectedPurpose` assertion (off by default — see notes).
- **`StoneScriptPHP\Auth\TrustedIssuerVerifier`** — mandatory-`iss`, issuer-selects-
  the-key verification. A token's `iss` selects its verification key from a trusted
  map (local RSA public key, or JWKS for federated identity); an untrusted or
  missing `iss` is rejected outright, and a token whose `iss` was forged to route
  it to a different key fails the signature check. This is the typed evolution of
  `MultiAuthJwtValidator.auth_servers[]`, unifying the local-key and JWKS paths.
- **`StoneScriptPHP\Auth\RefreshTokenStore`** (+ reference
  **`InMemoryRefreshTokenStore`**) — framework-owned persistence for ALL refresh
  tokens (identity AND card), discriminated by `purpose`. **Revoke = DELETE the
  row (hard).** A refresh token is valid iff its row exists.
- **`StoneScriptPHP\Auth\Middleware\AccessTokenMiddleware`** — STATELESS. For a
  route whose `access` is authentication/authorization, verifies a Bearer access
  token: signature + `iss`(→key-select) + `exp` + `type==access` +
  `purpose==route.access`. No DB.
- **`StoneScriptPHP\Auth\Middleware\RefreshTokenMiddleware`** — STATEFUL. For a
  `token_type=refresh` route, verifies the body refresh token (sig + `iss` + `exp`
  + `type==refresh` + `purpose`) AND that its row exists in the `RefreshTokenStore`
  (absent ⇒ revoked ⇒ reject). `purpose` is a constructor parameter, not a third
  class — the two middleware split by TYPE only (owner preference).
- **`StoneScriptPHP\Routing\RouteAccess`** + new `RouteEntry`/`Router` fields —
  routes declare `access` ∈ {`public`, `authentication`, `authorization`} plus a
  `token_type` ∈ {`access`, `refresh`}, replacing the `is_public` boolean. `is_public`
  still resolves (`is_public=true` ⇒ `access=public`); a protected route with no
  explicit `access` is treated as `authorization`/`access`.
- **`StoneScriptPHP\Auth\Middleware\AuthMiddlewareRegistrar`** — safe-by-construction
  wiring. `create()` returns BOTH typed-auth middleware as a unit (install one, get
  both). `assertFullyWired()` is a fail-fast boot guard — wired automatically into
  `Application::run()` — that refuses to boot when protected routes exist but the
  typed-auth middleware are not (fully) wired. It distinguishes two cases with
  distinct, actionable messages (both `AuthMiddlewareRegistrar::AuthWiringException`,
  caught by `run()` and rendered as a clean 500 — never a bare stack trace): an
  **un-migrated** platform (neither middleware wired → full migration guide, also
  exposed as `AuthMiddlewareRegistrar::migrationMessage()`) and a **half-wired** one
  (one credential class left unprotected → names the missing middleware). Adds
  read-only `Router::getGlobalMiddleware()` and `MiddlewarePipeline::getMiddleware()`.
- **`RefreshTokenMiddleware` body-parser robustness** — reads the refresh token from
  the request body; documents that `JsonBodyParserMiddleware` should precede it, and
  self-parses `php://input` as a fallback when the `body` key is absent, so a
  mis-ordered pipeline cannot silently 401 every refresh endpoint.
- **`MultiAuthJwtValidator` bounded stale JWKS** — new optional `max_stale_ttl`
  per-issuer config caps how long a stale key set is served when the auth service is
  unreachable; past the ceiling it fails closed rather than trust a possibly
  rotated/revoked key. Unset ⇒ unbounded (prior behavior preserved).
  `TrustedIssuerVerifier` passes it through for `jwks` issuers.

### Deprecated

- **`TokenStorageInterface`** — deprecated in favour of `RefreshTokenStore`. Its
  contract said "revoke = UPDATE revoked_at, keep an audit trail" (soft-delete) and
  has no `purpose` discriminator; it serves only the legacy cookie/CSRF refresh
  flow. The settled model is hard-delete via `RefreshTokenStore`. Retained only so
  the legacy cookie routes keep compiling.

### BREAKING CHANGES (on upgrade)

Merely vendoring 7.0.0 changes boot behaviour for every platform with protected
routes. This is the intended, strict, single-mode cutover.

- **Boot now requires the typed-auth middleware.** `Application::run()` fails fast if
  protected routes exist but `AccessTokenMiddleware` + `RefreshTokenMiddleware` are
  not wired. An un-migrated (old `is_public`-only) platform — including one that just
  wanted the CORS fix — gets the migration error at boot and does NOT serve requests.
  (This corrects a 6.2.0-line regression where the guard fired with only a cryptic
  assertion/500; it now emits a clear, actionable migration message and `run()`
  renders it as a controlled 500 rather than an uncaught stack trace.)
- **Refresh routes are DB-gated.** `RefreshTokenMiddleware` rejects any refresh token
  with no `RefreshTokenStore` row — including all previously-issued (rowless) tokens
  ⇒ a one-time forced re-login fleet-wide. Identity refresh tokens, never persisted
  before, must be written to the store at mint time.
- **Access routes are strictly typed.** `AccessTokenMiddleware` requires `type=access`
  and `purpose==route.access`; an identity token can no longer satisfy a card route
  (or vice-versa) even when signature + `iss` match — closing the
  issuer-indistinguishable token-confusion hole on single-key/builtin platforms.
- **`iss` verification is mandatory.** `TrustedIssuerVerifier` requires an explicit
  trusted-issuer→key map; there is no "skip issuer check when unset" escape hatch.
- **`TokenStorageInterface` is deprecated** in favour of `RefreshTokenStore` (see
  above). `validateIdentityToken()`'s `$expectedPurpose` stays OFF by default: the
  federated passport minter stamps `purpose` in a later phase, so enabling it before
  that would reject in-flight passports. Builtin-mode platforms may enable it today.

### Migration

To take 7.0.0, each consuming platform must:

1. **Declare `access`/`token_type` on routes** in `routes.php` — `public` for open
   endpoints; `authentication`+`access` for identity-bearer routes; `authorization`+
   `access` for card/business routes; `authentication`/`authorization`+`refresh` for
   the refresh/logout endpoints.
2. **Wire both typed-auth middleware:**
   `$auth = AuthMiddlewareRegistrar::create($trustedIssuerVerifier, $refreshTokenStore);`
   then `Application::run(['middleware' => [...$auth], /* ... */])`.
3. **Provide a `TrustedIssuerVerifier`** (issuer→key map: local RSA key for
   builtin/card, JWKS for federated identity) and a **`RefreshTokenStore`**
   implementation (persist every refresh token; revoke = delete the row).

The boot error text emitted to an un-migrated platform is itself the migration
checklist (`AuthMiddlewareRegistrar::migrationMessage()`). Full guide in
`UPGRADING-7.0.md`.

## [6.1.0] - 2026-07-09

### Added — Plugin extensibility seam (Phase 1, non-breaking)

Adds hook points so multi-tenancy (and other optional functionality) can
later be extracted into opt-in plugins, WITHOUT changing any current
behavior. Purely additive — every contribution point defaults to "nothing",
so a platform that never touches `plugins`/`tenancy` config gets byte-for-byte
identical behavior to 6.0.0.

- **`StoneScriptPHP\Plugin\PluginInterface`** (+ `AbstractPlugin` convenience
  base with no-op defaults) — a plugin contributes middleware, routes,
  migration paths, schema paths, and/or a tenancy strategy. Wired via
  `Application::run(['plugins' => [...]])`, conventionally sourced from a
  platform's own `src/config/plugins.php`. Plugin middleware is appended after
  a platform's own custom middleware; plugin routes are merged UNDER a
  platform's own `routes.php` (an explicit platform route always wins on a
  METHOD+path collision). Invalid `plugins[]` entries are dropped with a
  warning, never fatal.
- **`StoneScriptPHP\Tenancy\TenancyStrategyInterface`** + default
  **`NoTenantStrategy`** — `GatewayTenantMiddleware` no longer reads
  `tenant_id` directly off the authenticated user; it delegates to a strategy.
  `NoTenantStrategy` reproduces the pre-6.1.0 middleware body exactly
  (`$user->tenant_id ?? null`, nothing derived from elsewhere) — every
  existing T1 and card-model T2 platform is unaffected. A future
  multi-tenancy plugin can register an alternate strategy via
  `PluginInterface::tenancyStrategy()` or `Application::run(['tenancy' =>
  ['strategy' => ...]])`.
- **`Migrations::addMigrationPath()` / `Migrations::addSchemaPath()`** —
  process-wide registries a plugin can populate at boot so its own
  `*.sql` migrations and `{tables,functions}` schema-drift directories are
  scanned additively alongside the app's own `ROOT_PATH/migrations/` and
  `src/App/Database/postgresql/{tables,functions}/`. Empty by default.
- **`StoneScriptPHP\Auth\ExternalAuth\TenantRouteProviderInterface`** +
  default **`DefaultTenantRouteProvider`** — `ExternalAuthRoutes` no longer
  hard-`use`-imports the tenant-specific route classes (`SelectTenantRoute`,
  `ProvisionTenantRoute`, `InviteMemberRoute`, `MembershipsRoute`,
  `UpdateMembershipRoute`, `CheckTenantSlugRoute`, `AcceptInviteRoute`); it
  delegates both registration AND public/protected path computation to a
  provider (default: `DefaultTenantRouteProvider`, which registers the exact
  same routes under the exact same feature toggles as before this refactor).
  Registration and the `RequireCardMiddleware` exemption-path derivation
  (`ExternalAuthRoutes::protectedPaths()`) are bundled into ONE interface so
  they cannot drift apart — a plugin author replacing the provider must
  implement both together, preventing a recurrence of the 2026-07-05
  fleet-wide RequireCardMiddleware incident. Identity-only routes (register,
  login, logout, refresh, password reset, change-password, profile, OAuth,
  exchange, etc.) stay in `ExternalAuthRoutes` — nothing about their
  registration changed.

Verified non-breaking: full framework test suite (541 tests, up from 517)
green; phpstan clean against the existing baseline (0 new errors); `composer
update` via a local path-repo override against a real single-tenant platform
(`AUTH_MODE=builtin`) and a real multi-tenant platform (`AUTH_MODE=external`,
card model) installs cleanly and both boot with identical route/middleware
wiring — including a live check that a platform's own `select_tenant: false`
/ `check_slug: false` config still correctly 404s those routes, and its
enabled tenant routes (`memberships`, `invite-member`) still correctly
require auth (401), unchanged from before this refactor.

## [6.0.0] - 2026-07-08

### Routing consolidation — BREAKING CHANGES

The framework had accumulated four coexisting routing philosophies since a
Jan 2026 architecture pivot: two router engines (one dead), three
`routes.php` config formats, two Route-Handler authoring patterns, and a CLI
generator actively incompatible with both the official skeleton's format and
the real 11-platform fleet's format. Consolidated onto exactly ONE routing
implementation — the one already load-bearing in production across all 11
real platforms — with no deprecation window. Full evidence trail, rationale,
and phased rollout plan: `ROUTING-CONSOLIDATION-PLAN.md`.

**Removed** (confirmed zero real-world usage across all 11 platforms before removal):

- **Legacy `StoneScriptPHP\Router` + `RequestParser` family** (`src/Router.php`:
  `Router`, `RequestParser`, `GetRequestParser`, `PostRequestParser`,
  `OptionsRequestParser`, `NullRequestParser`). Deprecated since 3.28.0; had
  zero call sites in the framework, skeleton, or any of the 11 fleet
  platforms. `Routing\Router` (the middleware-pipeline router) is now the
  only router.
- **`BaseRoute`** (`src/BaseRoute.php`) — the template-method Route/Service/
  Contract/DTO split. Zero `extends BaseRoute` anywhere in any real
  platform's route directory; only `cli/generate-route.php` ever produced
  it. Route handlers implement `IRouteHandler` directly now (see below).
- **The `'public'`/`'protected'` sectioned `routes.php` format.**
  `Routing\Router::loadRoutes()` now *rejects* this shape with a clear
  migration error, rather than silently registering routes under the bogus
  HTTP methods "PUBLIC"/"PROTECTED" (which would never match any real
  request — the failure mode before this fix would have been *silent*, not
  loud). Only `StoneScriptPHP-Server`'s own skeleton template used this
  format; no real platform did.
- **Programmatic `$router->group()` route registration** (a `routes.php`
  that calls Router methods directly instead of returning an array).
  `Router::group()` and its `groupContext` state are removed entirely.
  `cli/generate-client.php`'s `loadRoutesFromPlatform()` no longer supports
  this style either — `routes.php` must return an array. Zero call sites
  anywhere, including inside the framework's own `Application.php`.
  (`Router::scope()` — a *different* mechanism for attaching middleware to a
  named scope, not route registration — is unaffected.)

### Fixed

- **`cli/generate-route.php` was actively broken, not just inconsistent.**
  It read a `'class'` array key that no real platform's `routes.php` ever
  used (they use `'handler'`) and would corrupt any real platform's
  `routes.php` if run — every existing route would lose its `service`/
  `group`/`action`/`is_public` metadata and become invalid PHP
  (`'$path' => ::class,`). Rewritten to read/write the same flat array
  format every real platform uses, with `--service=`/`--group=`/`--action=`/
  `--public` flags, and to scaffold a route class implementing
  `IRouteHandler` directly (matching real fleet convention) instead of the
  5-file `BaseRoute`/Service/Contract/DTO split. Verified end-to-end against
  a realistic multi-route fixture: pre-existing routes' metadata now
  survives a regenerate byte-for-byte; before this fix it would have been
  silently destroyed.
- **`cli/init.php`'s scaffolded `public/index.php` and example route were
  both fatally broken.** The entry-point template called `new Router();
  $router->handleRequest();` — neither the bare-constructor `Router` nor
  `handleRequest()` ever existed, on the legacy or current router. The
  example route used `#[Route]`/`#[GET]` PHP attributes from
  `StoneScriptPHP\Attributes\Route`/`GET` — classes that were never
  implemented anywhere in the framework. Both would have fatally errored on
  first use. Fixed to emit the same working `Application::run()` +
  `IRouteHandler` pattern the skeleton actually uses; `init.php` also now
  creates a `routes.php` wiring the example route (it previously created
  none at all, despite its own `public/index.php` needing one).

### Documentation

- `SPEC.md` §3 Routing Conventions rewritten to describe the one supported
  format (previously described a fictional `'scope'`/`'scopes'` key
  convention that was never implemented — the real, only-ever-implemented
  key is `service`).
- `SPEC.md` §10 Gap 5 (Router doesn't support PUT/PATCH/DELETE) marked
  resolved — it was exclusively about the now-deleted legacy router; the
  current router was never affected.
- `SPEC.md` §10 Gap 2 and Gap 8 citations updated to point at current code
  instead of the deleted legacy `Router.php`/`BaseRoute`.
- New `ROUTING-CONSOLIDATION-PLAN.md` — full evidence trail, phased rollout
  plan (framework → skeleton → sandbox platform → live deploy), and
  environment map for this work.

## [5.12.0] - 2026-07-08

### Added

- **`Database::fake()` / `Database::isFaked()` / `Database::clearFakeMode()`**
  — a business-logic test can now stub `Database::fn()`'s responses without a
  live gateway/Postgres. Previously `Database` was a private-constructor
  singleton with zero injection seam; the framework's own
  `tests/Unit/DatabaseTest.php::test_fn_accepts_array_parameters()` proved
  this by skipping itself unless a live gateway was configured. See
  `TESTABILITY-SPEC.md` requirement T2-1.
  - `Database::fake(array $responses)` — `function_name => array $rows |
    \Closure(array $params): array`. Calls merge (re-registering the same
    function name overwrites just that key); a `\Closure` can maintain its
    own counter for sequential/varying responses, or `throw` a
    `GatewayException` to simulate a gateway error — it receives the exact
    same translation `_fn()` already applies to real errors
    (`connection_failed` → `TenantDatabaseUnavailableException`, everything
    else → wrapped `Exception`), no parallel error-handling path to keep in
    sync.
  - Calling `Database::fn()` for a function not registered while faked
    throws immediately with a clear message, rather than silently falling
    through to a real gateway call or returning an empty result.
  - `Database::isConnected()` returns `true` while faked;
    `Database::getGatewayClient()` throws a clear, distinct error while
    faked (it has no fake-mode equivalent — it's for tenant routing against
    a live connection).
  - `Database::clearFakeMode()` clears the fake registry only — it does not
    touch the real singleton/`GatewayClient`, because fake mode never
    populates it in the first place (the fake branch in `_fn()` short-circuits
    before the real client is ever constructed). Must be called in
    `tearDown()`, same discipline as `Auth\AuthContext::clear()`.
  - Zero behavior change for existing code: `Database::fn()` with no
    `Database::fake()` call behaves exactly as before (confirmed by a
    regression test asserting the real `DB_GATEWAY_URL`-missing config error
    still surfaces, not a fake-mode error).

## [5.11.0] - 2026-07-08

### Added

- **`Routing\Router::dispatch()` accepts an optional `IncomingRequest`**
  (`StoneScriptPHP\Routing\IncomingRequest`), letting a test drive the full
  middleware pipeline + handler dispatch (method matching, header-driven
  middleware, request body shape) without touching PHP superglobals or
  `php://input`, and without a live HTTP server. `dispatch(null)` — every
  current production call site (`Application::run()`) — is unaffected;
  behavior is identical to before this change. See `TESTABILITY-SPEC.md`
  requirement T1-1.
  - New `Routing\IncomingRequest` value object: `method`, `path`, `headers`,
    `query`, `body`, `cookies`.
  - The request context array threaded through the middleware pipeline gained
    a `'cookies'` key (previously absent), sourced from the injected request
    or `$_COOKIE` by default.
  - `Auth\CookieHelper::getRefreshToken()` / `getCsrfToken()` now accept an
    optional cookie map parameter (default `null` → `$_COOKIE`, fully
    backward compatible with all existing call sites), so cookie-based auth
    flows (refresh-token cookie, CSRF double-submit) can be unit tested
    without mutating the real superglobal.
  - Not included in this change: automatically wiring the request context's
    `'cookies'` key into route handlers that call `CookieHelper` internally
    (`RefreshRoute`, `LogoutRoute`, `CsrfHelper`) — those still default to
    `$_COOKIE` today. Tracked as follow-up work under T1-1/T1-2.

## [5.10.1] - 2026-07-08

### Fixed

- **Router::loadRoutes() Format 2 (flat format) had no way to mark an
  individual route public.** `loadRoutes()` hardcoded `isPublic=false` for
  every flat-format route regardless of intent, so routes that must work
  without a pre-existing valid access token (e.g. a token-refresh endpoint
  validated by its own body-supplied refresh token) were incorrectly gated
  by `JwtAuthMiddleware` requiring one first — an unsatisfiable requirement
  for their actual purpose. Found live: progalaxy's `POST /user/refresh-access`
  returned 401 "Missing authentication token" instead of ever reaching the
  handler's own refresh-token validation.
  - `RouteEntry` gained an `isPublic` field (default `false`, backward
    compatible). Flat-format route config arrays can now set
    `'is_public' => true` per route, same effect as Format 1's `public`
    section. `normalizeRouteConfig()` reads it; `loadRoutes()`'s flat-format
    branch now passes it through instead of a hardcoded `false`.

## [5.10.0] - 2026-07-07

### Added

- **Builtin (standalone) Google OAuth popup flow — no central auth service
  required.** Until now, `AUTH_MODE=builtin` platforms had no first-class way
  to offer "Sign in with Google" via `ngx-stonescriptphp-client`'s stock
  `StoneScriptPHPAuth.loginWithProvider('google')`, which expects a real
  `GET {host}/oauth/google` redirect-to-Google-and-back popup flow — that
  contract previously existed only as a proxy to a central auth service
  (`Auth\ExternalAuth\OAuthInitiateRoute`/`OAuthCallbackRoute`, gated to
  `external`/`hybrid` mode), forcing standalone platforms to hand-roll a
  client-side GSI-credential workaround instead.
  - New `Auth\BuiltinOAuth\GoogleOAuthRoutes::register()` registers
    `GET {prefix}/oauth/google` (redirects to Google's consent screen, with a
    stateless signed-JWT CSRF `state` — no Redis/session store needed) and
    `GET {prefix}/oauth/google/callback` (exchanges the code, verifies the ID
    token via the same checks as the existing `auth:google` scaffold
    template, resolves the local user via an app-supplied
    `GoogleOAuthUserResolver`, mints this platform's own JWT, and renders the
    postMessage-bridge HTML page the frontend library already listens for).
  - Wired into `Application::run()`: set `AUTH_MODE=builtin` and pass
    `auth.oauth.google.enabled = true` plus `client_id`/`client_secret`/
    `redirect_uri`/`user_resolver` under `auth.oauth.google`.
  - New `RedirectResponse`/`HtmlResponse` (both `ApiResponse` subclasses, so
    `IRouteHandler`/`Router` are unaffected — zero breaking changes) let a
    route handler emit a 302 redirect or raw HTML instead of the usual JSON
    body; `Application::run()`'s output step special-cases both.
  - `google/apiclient` moved from `suggest`-only to also being a
    `require-dev` dependency (for this feature's own test coverage) — still
    optional for consuming apps that don't enable Google OAuth.

## [5.8.0] - 2026-07-05

### Fixed

- **`RequireCardMiddleware` had zero path awareness — real fleet incident
  (2026-07-05).** Wired globally (the previously-documented usage),
  it 403'd ANY authenticated-but-tenant-less request, with no way to distinguish
  a genuinely tenant-scoped business route from `ExternalAuthRoutes`' own tier-2
  routes (`provision-tenant`, `select-tenant`, `change-password`,
  `invite-member`, `memberships`, `me`) — routes that intentionally accept a
  passport and were never supposed to require a card. This was latent since the
  card model shipped; it was masked by the pre-5.7.0 `JwtAuthMiddleware` bug
  (see 5.7.0 entry below) until that bug was fixed, at which point every new
  user's first "create organization" call started 403-ing fleet-wide on any
  platform with a tier-2 route enabled.
  - **Fix:** `RequireCardMiddleware`'s constructor now accepts an optional
    `$tenantAgnosticPaths` list, checked against the matched route pattern
    before rejecting a tenant-less token. Backward compatible — default is an
    empty list, reproducing the old strict (buggy) behavior exactly for any
    existing bare `new RequireCardMiddleware()` call site.
  - **New recommended usage:** `Application::run(['require_card' => ['enabled'
    => true]])` auto-derives the exemption list from
    `ExternalAuthRoutes::protectedPaths($authRouteOptions)` — the same config
    that defines those routes, so the list can never hand-drift out of sync.
    Mirrors the existing `tenant_url_match` config key's self-skip pattern.

- **`ExternalAuthRoutes::protectedPaths()` ignored `legacy_compat`.** Since
  `legacy_compat` defaults to `true`, a platform on the default config would
  get the new `require_card` exemption correctly applied to
  `/api/auth/provision-tenant` but NOT to the legacy `/auth/provision-tenant`
  duplicate — silently 403-ing any client still calling the legacy prefix. Now
  mirrors `publicPaths()`'s existing (correct) legacy-prefix handling.

### Added

- `framework-spec.md §5.5` compliance test: `POST /api/auth/exchange` was already
  correct (no shared state, no revoke-on-exchange), but untested — a client may
  hold multiple concurrently-valid cards across different tenants (one per
  browser tab) with no server-side coordination required. Locked in with a
  regression test simulating two tabs exchanging into different tenants
  sequentially.

## [5.7.1] - 2026-07-04

### Changed

- Documentation hygiene: scrubbed internal references from the v5.6.1/v5.7.0
  change notes and code comments. No functional change.

## [5.7.0] - 2026-07-04

### Fixed

- **CRITICAL — guard middlewares were inert no-ops fleet-wide.**
  `JwtAuthMiddleware` (`src/Routing/Middleware/JwtAuthMiddleware.php`) — the
  middleware `Application::run()` wires globally on EVERY platform — stored the
  authenticated user only via `AuthContext::setUser()` and never populated
  `$request['jwt_claims']`. But the entire `Auth\Middleware\*` guard family
  (`RequireCardMiddleware`, `RequireTenantMiddleware`, `RequireRoleMiddleware`,
  `RequireIssuerMiddleware`, `TenantUrlMatchMiddleware`) — plus
  `RequestContextTrait` used by controllers — reads authorization state
  EXCLUSIVELY from `$request['jwt_claims']`. Because that key was always empty,
  every guard treated every request (authenticated or not) as an unauthenticated
  public route and silently passed it through — card/role/tenant/issuer
  requirements were never enforced. Confirmed live in an integration session
  (2026-07-04): a passport-only (tenant-less) token reached a tenant-scoped
  route handler instead of getting 403 `tenant_context_required`.
  - **Root cause / two parallel families:** the codebase had two middleware
    families for the same concern — `Routing\Middleware\JwtAuthMiddleware`
    (writes to `AuthContext`, actually wired by `Application::run()`) and
    `Auth\Middleware\*` guards + `ValidateJwtMiddleware` (read/write
    `$request['jwt_claims']`, backed by framework-spec.md §6 and covered by
    existing unit tests). They never talked to each other.
  - **Fix:** `JwtAuthMiddleware::handle()` now sets
    `$request['jwt_claims'] = $payload` from the SAME validated payload used to
    build `AuthContext`'s `AuthenticatedUser`, immediately before calling
    `$next()`. `AuthContext` (used by `auth()`/`GatewayTenantMiddleware`/request
    logging) and `$request['jwt_claims']` (used by every guard) can no longer
    drift apart — one payload, two read paths, single source of truth.
  - **Mitigant that held throughout:** data isolation was never at risk —
    `GatewayTenantMiddleware` reads `auth()->tenant_id` (from `AuthContext`,
    which WAS correctly populated) and unconditionally scopes every
    `Database::fn()` call to it. This was a request-level AUTHORIZATION gap
    (should this request even reach the handler?), not a data-leak-through-the-
    DB bug.

- **`TenantUrlMatchMiddleware` can now be wired globally — scope-aware gap
  closed (framework-spec.md §5.2).** Previously this middleware
  fail-closed with HTTP 500 `middleware_misconfigured` on ANY route that didn't
  resolve its URL param, which meant it could only ever be wired per-route —
  wiring it globally would 500 every flat/non-tenant route (health, webhooks,
  admin/auth, infra). It is now **self-skipping**: it inspects the matched
  route's PATTERN (`$request['route']['pattern']`, set by `Router::dispatch()`)
  and passes through untouched when the pattern doesn't declare the configured
  tenant param (e.g. `{tenantId}`) as a path segment — mirroring how
  `StoreAccessMiddleware` already self-skips non-tenant-scoped routes by
  pattern. A route whose pattern DOES declare the param but fails to resolve it
  into `$request['params']` still fails closed with 500 (genuine
  router/param-name-drift bug, unchanged).
  - New `Application::run()` config key: `'tenant_url_match' => ['enabled' =>
    true, 'param' => 'tenantId']` — opt-in (off by default for backward
    compat), wires `TenantUrlMatchMiddleware` as global middleware. Safe on
    every route because of the self-skip behaviour above.

### Added

- 27 new/updated framework unit tests locking down the JwtAuthMiddleware ↔
  Require* guard contract:
  - `tests/Unit/JwtAuthMiddlewareTest.php` (new) — proves `jwt_claims` is
    populated from the same payload as `AuthContext`, for card tokens,
    passport tokens, public routes, missing/invalid tokens.
  - `tests/Unit/AuthMiddlewarePipelineIntegrationTest.php` (new) — end-to-end
    pipeline tests composing the REAL `JwtAuthMiddleware` with
    `RequireCardMiddleware` / `RequireTenantMiddleware` / `RequireRoleMiddleware`
    / `TenantUrlMatchMiddleware` exactly as `Application::run()` wires them,
    including the exact live-repro scenario (passport → 403, not
    handler) and the flat-route-unaffected-by-global-middleware case.
  - `tests/Unit/CardBoundaryMiddlewareTest.php` (updated) — existing
    `TenantUrlMatchMiddleware` tests updated for the new pattern-based self-skip
    check; added dedicated self-skip tests (no `{tenantId}` in pattern, absent
    `route` key, card claims present but route still non-tenant-scoped).

### Live verification (local dev environment, 2026-07-04)

Reproduced the exact wiring (`JwtAuthMiddleware` + `RequireCardMiddleware`
only, real RSA-signed JWTs via `RsaJwtHandler`, served over real HTTP via PHP's
built-in server in a `php:8.3-cli-bookworm` container) and confirmed:
- **Pre-fix code:** passport-only token → tenant-scoped route → `HTTP 200`,
  handler reached (the bug, reproduced live).
- **Post-fix code, same wiring:** passport-only token → tenant-scoped route →
  `HTTP 403 tenant_context_required`, handler never reached.
- Valid card on its own tenant route → `HTTP 200`.
- Valid card on a foreign tenant route (`TenantUrlMatchMiddleware` wired) →
  `HTTP 403 tenant_mismatch`.
- `/health` (no `{tenantId}` in pattern) → `HTTP 200`, unaffected by
  `TenantUrlMatchMiddleware` wired globally in the same pipeline.
- No token at all → `HTTP 401` (existing behaviour, unchanged).

## [5.6.1] - 2026-07-04

### Fixed

- **Killed the silent localhost auth-URL fallback** — the framework
  no longer has ANY code path that silently defaults `AUTH_SERVICE_URL` or the
  gateway URL to `http://localhost:3139` / `http://localhost:9000`. Every
  resolution site now either reads a real, explicitly-configured value or fails
  loud at the point of use with a `RuntimeException` naming exactly what's
  missing — matching the loud-failure posture the framework already used for
  `AUTH_ISSUER` (`ExternalAuthConfig`).
  - `Env::$AUTH_SERVICE_URL` no longer defaults to `'http://localhost:3139'`;
    it now defaults to `''` (same pattern as `AUTH_ISSUER`), so an unconfigured
    platform is visibly unconfigured instead of silently pointed at loopback.
  - `Application::buildJwtHandler()` and `Application::buildAuthRouteOptions()`
    (used for `external`/`hybrid` `AUTH_MODE` and for `store_access`) share a new
    `Application::resolveAuthServiceUrl()` helper: explicit `auth.server.url` >
    non-empty `AUTH_SERVICE_URL` env var > loud `RuntimeException`. No default.
  - `ExternalAuthConfig::__construct()` applies the same check directly
    (defense-in-depth for callers that bypass `Application::run()`).
  - `bootstrap.php`'s `TokenValidator` service factory (`gateway_url`) now
    resolves from `Env::$DB_GATEWAY_URL` — a framework-required secret that
    `Env::__construct()` already fails loud on when missing — instead of
    defaulting to `'http://localhost:9000'`. Extracted into the new, directly
    unit-testable `stonescript_resolve_gateway_url()` (`src/helpers.php`). Also
    fixed the factory to construct the real
    `StoneScriptDB\Auth\TokenValidator(string $gatewayBaseUrl)` (single-arg
    constructor) instead of a nonexistent
    `StoneScriptDB\GatewayClient\Auth\TokenValidator` with a 3-arg signature —
    this service had no callers anywhere in the fleet, so the mismatch was
    latent, but it's fixed now rather than left broken for the next platform
    that wires it up.
  - `AuthService.php`'s legacy `CentralizedAuth` class (dead code — zero
    callers found across the framework or any downstream platform) had the
    same `'http://localhost:3139'` default in its constructor; replaced with
    an empty default and loud failures at first actual use (`getJWKS()` /
    `proxyAuthRequest()`) rather than eagerly in the constructor, so
    multi-auth-mode callers that never touch the single-issuer path are
    unaffected.
  - `AuthServiceClient::getDefaultAuthServiceUrl()` (the third code path named
    in the original bug evidence) previously had already been fixed to check
    `AUTH_SERVICE_URL` env first (env-first, prior release), but still fell
    back to a hardcoded `'http://auth:3139'` when neither the env var nor a
    legacy `ROOT_PATH/config/auth.php` resolved anything. That step-4 default
    is now removed — it throws a `RuntimeException` instead. The framework's
    primary path (`ExternalAuthRoutes` → `ExternalAuthConfig` →
    `Application::resolveAuthServiceUrl()`) already fails loud and always
    passes an explicit URL into the constructor, so this fallback was only
    reachable by direct/manual instantiation of `AuthServiceClient` subclasses
    (`MembershipClient`, `InvitationClient`) that bypass that resolution —
    exactly the callers a silent default could strand.
  - Root cause: only a couple of downstream platforms had ever added a
    `ROOT_PATH/config/auth.php` shim working around
    `AuthServiceClient::getDefaultAuthServiceUrl()`'s old localhost-only
    fallback — one such shim quotes the confirmed production error
    `Failed to connect to localhost port 3139`. The other platforms had no
    such shim and were relying entirely on their docker-compose files
    explicitly setting `AUTH_SERVICE_URL`/`DB_GATEWAY_URL` — which, as of this
    release, all downstream platforms in fact do (a fleet audit of every
    platform's `docker/docker-compose.yaml` + `docker/docker-compose.swarm.yaml`
    found zero platforms currently resolving to localhost in practice), but
    nothing in the framework *enforced* that until now. This closes the gap so
    a future platform (or a CLI/cron context that doesn't inherit
    docker-compose env) can never silently repeat that incident.
  - Backward-compatible: `bootstrap.php` still honors a present
    `ROOT_PATH/config/auth.php` file's `gateway_url` key as an override, so
    existing root shims are not required to be removed in this release —
    they are now provably redundant (env vars already present fleet-wide) and
    should be removed in a follow-up per-platform cleanup **once each platform's
    committed `composer.lock` pins `progalaxyelabs/stonescriptphp >= 5.6.1`**
    (some platforms are still pinned to 5.6.0 as of this release — do not
    remove any shim until each platform adopts this version, or the still-old
    framework in production would silently fall back to localhost with
    nothing left to catch it).
  - Tests: `tests/Unit/AuthServiceUrlResolutionTest.php` (new),
    `tests/Unit/GatewayUrlResolutionTest.php` (new),
    `tests/Unit/AuthServiceClientDefaultUrlTest.php` (new), plus additions to
    `tests/Unit/ExternalAuthConfigTest.php`. Full suite: 461/461 passing
    (0 failures) — verified locally (PHP 8.4.11) AND in a local dev environment
    inside `php:8.3-cli-bookworm` (matches the production PHP-FPM base image),
    both clean runs. Additionally proved end-to-end in a local dev environment
    with a script simulating a downstream platform's real production env vars
    (from its `docker-compose.swarm.yaml`, no root shim): resolves
    `gateway_url=http://<internal-gateway>:9000` and
    `auth_service_url=http://<auth-service>:3139` from Env — never
    localhost — and, with `AUTH_SERVICE_URL` unset, throws a `RuntimeException`
    naming exactly what's missing instead of silently defaulting.

## [5.6.0] - 2026-07-04

### Added

- **Opt-in framework schema now discoverable, never silently unadopted**
  (`cli/sync-vendor-schema.php`, `cli/helpers/vendor-schema-sync.php`,
  `cli/gateway-migrate-vendor-main.php`). Framework features that ship their
  own SQL under `src/<Feature>/Schema/{tables,functions,...}/` (e.g.
  `RequestLogging`) previously had zero discovery mechanism beyond a
  changelog line saying "copy these files in" — fleet audit on 2026-07-04
  found not one platform had ever done so, despite several being on a
  framework version that includes the feature (see
  `stonescriptphp-server`'s `IMPROVEMENT-SUGGESTIONS-2026-07.md`).
  - `cli/sync-vendor-schema.php` stages (copies, never applies) every
    `vendor/progalaxyelabs/stonescriptphp/src/*/Schema/*` folder it finds
    into the platform's own `src/postgresql/vendor/postgresql/` — a build
    artifact regenerated fresh on every run (never hand-edited, never
    committed), so newly-available opt-in schema shows up as real files in
    the working tree the moment the framework version changes. Intended to
    be wired into a platform's own `composer.json`
    `post-install-cmd`/`post-update-cmd`, alongside the existing `stone` CLI
    copy step (`stonescriptphp-server` ships this wiring by default).
  - `php stone gateway:migrate-vendor-main` is a new, **deliberately
    separate** command that activates whatever's staged in
    `src/postgresql/vendor/`. It is **never** invoked automatically by
    `gateway:register-main`, `gateway:migrate-main`, or any deploy-manager
    hook — StoneScriptPHP is opensource, so unreviewed contributor-authored
    schema must never auto-execute against a real database as a side effect
    of the routine, already-automatic main-database migration path.
    Activating an opt-in feature stays a deliberate, reviewable act a
    maintainer runs once. It merges the staged vendor files into the SAME
    archive/upload as the platform's own `main` schema (never a separate
    schema name) — the gateway resolves the target database as
    `{platform}_{schema_name}` with no tenant uuid
    (`stonescriptdb-gateway`'s `router.rs::database_name()`), so a distinct
    name would target a different, nonexistent database rather than the
    platform's actual main database that features like `RequestLogging`
    expect to write to.
  - `cli/helpers/schema-archive-builder.php`'s `buildSchemaArchive()` gains
    an optional `$mergeTargets` parameter (default `[]`, no behavior change
    for existing callers) that merges additional `{target}/postgresql/`
    directories into the same archive as the primary target.

## [5.5.8] - 2026-07-03

### Changed

- De-brand: the v5.5.7 T3 tenant-prefix hard-error guard (`cli/generate-client.php`,
  `tests/Unit/ClientGeneratorV4Test.php`) re-introduced a private downstream
  platform's name into doc comments, an error message, and a test regression
  comment/assertion message — a regression of the v5.5.6 de-brand pass.
  Genericized every occurrence to neutral phrasing ("a downstream JWT-tenancy
  (T2) platform", "a consuming platform's production portal", "the production
  incident this guard was added for") while keeping the technical narrative
  (root cause, fix, regression-test intent) fully intact. No generator logic
  or test assertions changed — only comments and message strings. Verified
  via full-tree grep for each known private platform name (zero hits outside
  `progalaxyelabs/` vendor identifiers) and a full test run (435 tests, 0
  failures/errors).

## [5.5.6] - 2026-07-03

### Changed

- De-brand: swept the whole source tree for leaked private-platform names
  that had crept back into CHANGELOG entries and doc comments in v5.5.2
  through v5.5.5 (a regression of the v5.5.1 de-brand pass). Genericized
  references in `CHANGELOG.md`, `cli/generate-client.php`,
  `src/Auth/BearerToken.php`, `src/Auth/Client/AuthServiceClient.php`, and
  `tests/Unit/AuthServiceClientBuildAuthHeaderTest.php` — narrative
  substance (what broke, which class/method, root cause) is preserved;
  private platform names and internal triage dates are replaced with
  neutral phrasing ("a downstream platform", "several platforms'
  tenants_resolver closures") or the existing `exampleapp-api` example
  convention. No code behavior changed. Verified via full-tree grep for
  each known private platform name (zero hits outside `progalaxyelabs/`
  vendor identifiers) and a full test run (432 tests, 0 failures/errors).

## [5.5.5] - 2026-07-03

### Changed

- **Consolidated Bearer-prefix stripping into one canonical, public utility:
  `StoneScriptPHP\Auth\BearerToken::strip()`.** The double-`"Bearer "` fix
  shipped in v5.5.3 (`AuthServiceClient::buildAuthHeader()` normalizing
  before re-prepending) already made the bug structurally impossible for any
  caller of `getMemberships()`/similar SDK methods. But the strip logic
  itself still existed as two independently-maintained copies of the same
  regex — one inside `AuthServiceClient::buildAuthHeader()` (outbound) and
  one inside `BaseExternalAuthRoute::getBearerToken()` (inbound) — and
  anything running OUTSIDE a route class (e.g. a platform's
  `config/auth.php` `tenants_resolver` closure, which reads
  `$_SERVER['HTTP_AUTHORIZATION']` directly and has no access to
  `BaseExternalAuthRoute`) had no framework utility to call. At least one
  downstream platform hit exactly that gap and hand-rolled a local
  `App\Lib\BearerToken::strip()` as a same-day fast fix. Both existing call
  sites now delegate to the new `StoneScriptPHP\Auth\BearerToken::strip()`
  (public, static, idempotent, null/empty-safe, case-insensitive,
  whitespace-tolerant) — single source of truth, and platform code (route
  classes, config closures, anywhere) can call it directly instead of
  duplicating the regex. That platform-local copy has since been deleted;
  its `tenants_resolver` now calls the framework utility. Added
  `BearerTokenTest` (8 tests). Full
  suite: 432 tests, 0 failures/errors (same skip/incomplete/deprecation
  baseline as v5.5.4), zero regressions.

## [5.5.4] - 2026-07-02

### Fixed

- **Test-suite hygiene: resolved all 5 pre-existing `Unit` test failures/errors/warnings
  instead of deferring them again.** Three agents earlier today waved these off as
  "pre-existing baseline, no regression from my change." Investigated and fixed each:

  1. **`DatabaseTest::test_copy_from_returns_boolean` / `test_query_returns_string`
     (errors)**: `Database::query()`, `Database::copy_from()`, and `Database::getConnection()`
     called a private `getDirectConnection()`/`getConnectionInstance()` factory method
     that was deleted from `Database` in the Jan 2026 v2.4.2 gateway-only-mode migration
     (commit `62f4128`) — that migration correctly converted `internal_query()` to
     always-throw but missed these three siblings, leaving them permanently broken
     (`Call to undefined method`). Confirmed dead via: (a) `phpstan-baseline.neon` already
     had these exact "undefined method" errors baselined/suppressed rather than fixed,
     (b) zero production callers anywhere in the codebase, (c) the one caller,
     `cli/dba.php`, is itself an orphaned script never wired into the `stone` CLI
     dispatcher. Deleted the three dead `Database` methods, the now-fully-orphaned
     `src/Database/DirectConnection.php` + `src/Database/ConnectionInterface.php`
     (implementations of the v2 direct-connection mode with zero remaining callers),
     `cli/dba.php`, the two corresponding dead tests, and the matching stale
     `phpstan-baseline.neon` entries.

     **Also fixed the underlying root cause**, not just the two named tests: a sibling
     error, `DatabaseTest::test_fn_accepts_array_parameters`, was intermittently failing
     because five other test classes (`ExternalAuthConfigTest`, `ExchangeRouteTest`,
     `JwtHandlerFlatClaimsTest`, `ApplicationResolverThreadingTest`,
     `HybridCardJwtHandlerTest`) `putenv('DB_GATEWAY_URL=...')` in `setUp()` without ever
     unsetting it in `tearDown()` — since PHPUnit runs the whole suite in one process,
     this leaked `DB_GATEWAY_URL` forward into every later test, silently flipping
     `if (!getenv('DB_GATEWAY_URL'))` skip-guards regardless of what the invoking shell's
     env actually had. `MigrationsTest.php` had already documented this leak as a known
     workaround (gating on `DATABASE_HOST` instead) rather than fixing it. Added
     tracked, symmetric `setEnvIfEmpty()`/`tearDown()` cleanup to all five leaking test
     classes so `putenv()` calls are always undone. Verified deterministic behavior with
     `DB_GATEWAY_URL` both unset (test skips) and explicitly set (test attempts a real
     gateway call and fails on connection-refused, as a genuine opt-in integration test
     should — not on leaked state).

  2. **`GatewayAuthHeaderTest::test_migrate_step_signatures_accept_admin_token` (failure)**:
     investigated as a potential security regression per the task's explicit instruction
     to determine privilege-downgrade vs. legitimate rename before touching anything.
     Traced `stepMigrateDatabase`/`stepMigrateAllDatabases` (`cli/helpers/gateway-common.php`)
     and their `resolveGatewayPlatformToken()`/`stepProvisionPlatformToken()` call chain:
     this is a **legitimate, intentional rename**, not a downgrade. Commit `567050c`
     ("v5.0.1 — platform token support for gateway v4.1.0+") shows the *admin* token was
     already returning HTTP 403 against gateway v4.1.0+ for `POST /v2/migrate` and
     `/v2/migrate-all` — the gateway's own contract changed to require a per-platform
     bearer token for these specific endpoints. Critically, the platform token is itself
     only provisionable via `POST /admin/platform-token`, which requires the admin token
     — so the admin credential remains the trust root; `platformToken` is a properly
     least-privilege-scoped credential for this operation, not a bypass. Renamed the test
     to `test_migrate_step_signatures_accept_platform_token`, updated its assertions and
     comments to check for `'platformToken'`, and corrected the class-level docblock
     (which still incorrectly described these endpoints as behind `admin_auth_middleware`).
     No production code change for this one — the code was already correct; only the
     stale test/docs needed to catch up.

  3. **`GenerateEnvSchemaTest::test_matches_real_env_required_vars` (failure)**: confirmed
     `DB_GATEWAY_SCHEMA_NAME` (declared `public string $DB_GATEWAY_SCHEMA_NAME;` — no
     default, non-nullable, in `src/Env.php`) is genuinely required — `Database::initConnection()`
     throws `'DB_GATEWAY_SCHEMA_NAME is required'` when empty, and it's exactly the
     3rd no-default/non-nullable string property alongside `DB_GATEWAY_URL` and
     `DB_GATEWAY_PLATFORM` (verified by enumerating every `public` property in `Env.php`).
     This is real drift from the v5.2.0 gateway-v4-routing change (commit `4edadad`) that
     the test's expected array was never updated for. Updated the expectation.

  4. **`RouterTest::test_router_returns_500_when_route_handler_throws_exception` (warnings)**:
     `res_error()` (`src/helpers.php`) read `$_SERVER['REQUEST_METHOD']`/`['REQUEST_URI']`
     unconditionally. `res_error()` is called from global exception/error handlers
     (`error_handler.php`) and route handlers alike, not exclusively within a populated
     HTTP request — a CLI-invoked code path (migrations, queue workers, `php stone`
     commands, or a fatal error firing before the SAPI populates `$_SERVER`) legitimately
     has neither key set. `src/bootstrap.php` already established the correct defensive
     pattern for this exact log-line shape (`($_SERVER['REQUEST_METHOD'] ?? 'CLI') . ' ' .
     ($_SERVER['REQUEST_URI'] ?? '')`); `res_error()` now matches it. This is a genuine
     production robustness fix, not just a test-warning silencer.

  Full `Unit` suite: 420 tests (3 errors, 2 failures, 2 warnings) → 418 tests (2 dead
  tests deleted), 0 errors, 0 failures, 0 warnings, same 4 pre-existing PHPUnit
  deprecations as before (verified unrelated via `git stash` A/B comparison). Full suite
  (`Unit` + `Feature`): 424 tests, 0 errors/failures/warnings. `phpstan analyse` unaffected
  aside from the now-accurate baseline shrink (7 pre-existing unrelated errors confirmed
  present before this change too, via the same `git stash` comparison — not introduced
  here, out of scope for this fix).

## [5.5.3] - 2026-07-02

### Fixed

- **`AuthServiceClient::buildAuthHeader()` double-"Bearer " prefix bug**: this
  protected method (used by every `AuthServiceClient`/`ExternalAuthServiceClient`
  method that forwards a bearer token, e.g. `getMemberships()`) always
  unconditionally prepended `"Bearer "` to the token it was given, assuming
  callers only ever pass a *bare* token. In practice, several downstream
  platforms' `tenants_resolver` closures read `$_SERVER['HTTP_AUTHORIZATION']`
  directly — which already carries the `"Bearer "` prefix as sent by the
  client — and forwarded that raw header value straight into
  `getMemberships()`. The result was `Authorization: Bearer Bearer <token>` on
  the outbound call to the auth service; the auth service's bearer-parsing
  only strips a single `"Bearer "` prefix, so the "token" it tries to verify
  is itself `"Bearer <token>"` — an invalid JWT shape — verification fails,
  and the auth service returns 401. Every affected `tenants_resolver` caught
  that as a generic error and returned an empty tenants list, which
  `ExchangeRoute` then reported as a misleading `403 tenant_access_denied` —
  even for an identity with a verifiably correct, active tenant membership
  row in the database (caught during live production bug triage).
  `buildAuthHeader()` now strips any pre-existing `"Bearer "` prefix
  (case-insensitive, tolerant of extra whitespace) before adding it back, so
  it produces exactly one correct header regardless of whether the caller
  passes a bare token or a raw header value that already has the prefix. This
  is a single framework-level fix — every affected platform is corrected on
  the next `composer update`, no platform-code changes needed. Added
  `AuthServiceClientBuildAuthHeaderTest` (7 tests) covering the bare-token,
  raw-header, case-insensitivity, whitespace-tolerance, null/empty, and
  non-prefix-substring cases.

## [5.5.2] - 2026-07-02

### Fixed

- **Generated client double-slash URL bug**: `MinimalHttp` in the generated `http.ts`
  (`php stone generate client`) built request URLs via raw string concatenation
  (`this.baseUrl + path`). Fleet convention writes `environment.apiServer.host` with
  a trailing slash, and call-site paths (typed generated methods, and hand-written
  `ApiService.get('/products', …)` escape-hatch wrappers) are written with a leading
  slash — the concat produced `https://api.example.com//products`. PHP's
  `RouteMatcher`/`Router::matchRoute()` do an exact string match on the path and do
  not normalize repeated slashes, so the request 404s ("Not found") instead of
  reaching the intended route (CorsMiddleware is global and still runs on the 404
  path, so this is a pure routing miss, not a CORS-specific failure — a separate
  downstream investigation into a similar-looking CORS-missing-header symptom
  found an unrelated `ALLOWED_ORIGINS` misconfiguration as the actual cause on
  that request). Added `MinimalHttp.joinUrl(base, path)`, a private
  static helper that strips trailing slashes from the base and leading slashes from
  the path before joining with exactly one `/` — correct for every combination of
  trailing/leading slash on either side. Applied to both the main request URL and
  the default same-origin refresh-token URL. Mirrors the trim-then-join pattern
  already used in `ngx-stonescriptphp-client` (`environment.apiServer.host.replace(/\/$/, '')`).
  Added `ClientGeneratorV4Test::test_generated_http_ts_normalizes_url_join_for_all_slash_combinations`,
  which extracts the emitted `joinUrl` method body and executes it under Node for
  4 slash-combination cases, plus asserts the raw concat is gone from emitted source.

## [5.5.1] - 2026-06-29

### Changed

- De-brand: replaced all private `TENANCY-IDENTITY-MODEL` doc citations with public
  `framework-spec.md §6` references throughout src/ and tests/.
- De-brand: genericized private platform names used as examples/fixtures in src/ and
  tests/ (private platform domains → `exampleapp.in`, platform codes → `exampleapp`/`sampleapp`).
- De-brand: removed internal task numbers from code comments and test doc blocks.
- De-brand: genericized private auth-server name in `StoreAccessMiddleware` comment.
- Spec: genericized private platform examples in `generate-api-client-spec.md` and
  `navigation-spec.md`; removed private doc reference from `auth-server-spec.md`;
  genericized `deploy-manager` product name in `gateway-spec.md`.

## [5.5.0] - 2026-06-29

### Added

- **Platform-level request logging** (`src/RequestLogging/`) — Every HTTP request is now persisted to
  `{platform}_main.request_logs` in a self-sufficient row, regardless of whether the request succeeded,
  threw an uncaught exception, or died on a fatal error. Implemented per the approved request-logging spec.

  Key design points:
  - `RequestLogger::arm()` is called as the FIRST action in `Application::run()`, registering a
    `register_shutdown_function` before any middleware/router wiring — the only hook that survives
    success, uncaught exceptions, AND PHP fatal errors.
  - Duration measured from `INDEX_START_TIME` constant (standardized in the skeleton `public/index.php`);
    falls back to the time captured at the top of `run()` for older platforms.
  - `RequestContext` static class holds `error_class`/`error_message` for the current request;
    stamped by `ExceptionHandler` on both uncaught exceptions and fatal errors.
  - `RequestLogger::resolveClientIp()` is proxy-aware: `trust_proxy=true` uses `X-Real-IP` /
    rightmost XFF entry; `trust_proxy=false` uses `REMOTE_ADDR` only (no XFF spoofing).
  - `X-Request-Id` header captured if present (Traefik join key); else UUIDv4 generated.
  - `fastcgi_finish_request()` called before the DB write — logging is off the critical path.
  - **Fail-open on all errors**: gateway down, table missing, any exception → swallowed to STDERR,
    response unaffected. The framework ships independent of the migration being applied.
  - Config keys: `request_logging.enabled` (default `true`) and `trust_proxy` in the
    `request_logging` config section; also reads `TRUST_PROXY` env var.
  - Migration file `src/RequestLogging/Schema/tables/req_001_request_logs.pgsql` and insert
    function `src/RequestLogging/Schema/functions/rl_insert_request_log.pgsql` shipped with
    the framework. Platforms copy/symlink and run `php stone migrate up` to activate.
  - 35 unit tests covering all §10 scenarios: success, exception, fatal, null identity,
    fail-open (gateway down + table missing), client_ip (trust_proxy on/off), request_id
    (generated vs captured).

## [5.4.0] - 2026-06-29

### Added

- **`HybridCardJwtHandler`** (`src/Auth/HybridCardJwtHandler.php`) — New `JwtHandlerInterface`
  implementation that validates BOTH platform-minted cards (platform RSA key, fast, no network) AND
  auth-service passports (JWKS fallback). This is the load-bearing fix for the passport/card model
  (framework-spec.md §6): `Application::run()` in `external`/`hybrid` mode now defaults to
  `HybridCardJwtHandler` instead of the previous JWKS-only `MultiAuthJwtAdapter`. Without this fix,
  platform-minted cards were rejected with "Unknown issuer" because only the auth service's JWKS key
  was known to the validator. Validation order: platform RSA → JWKS fallback (passports on non-excluded
  routes). Expose a custom handler via `$config['jwt']['handler']` if needed.

- **`RequireCardMiddleware`** (`src/Auth/Middleware/RequireCardMiddleware.php`) — Global enforcement
  middleware for the card model with public-route pass-through. Differs from `RequireTenantMiddleware`
  in one critical way: when `jwt_claims` is absent (public route excluded by `JwtAuthMiddleware`), it
  passes through to the route handler instead of returning 401. This allows the exchange endpoint
  (`POST /api/auth/exchange`) — which validates its own inbound passport — to be wired globally without
  self-blocking. Wire on multi-tenant platforms via `$config['middleware'] => [new RequireCardMiddleware()]`
  in `Application::run()`. T1 platforms (no card concept) must NOT wire it.

- **`jwt.handler` injection key** — `Application::run()` now accepts `$config['jwt']['handler']` as a
  pre-built `JwtHandlerInterface` instance. When supplied it takes precedence over the default handler
  selection (builtin → RsaJwtHandler, external/hybrid → HybridCardJwtHandler). Use for custom JWKS
  sources, multi-issuer setups, or unit-test doubles.

### Fixed

- **Defect 1 — `Application::run()` rejected platform-minted cards** (`src/Application.php`).
  `buildJwtHandler()` in external/hybrid mode created `MultiAuthJwtAdapter` (JWKS-only). Cards signed
  by the platform's own RSA key carried `iss=JWT_ISSUER` (not the auth service issuer) and were rejected.
  Fixed: external/hybrid mode now defaults to `HybridCardJwtHandler` (RSA + JWKS chain). The old
  `MultiAuthJwtAdapter` path is removed from the default; platforms can still inject it via `jwt.handler`.

- **Defect 3 — `buildAuthRouteOptions()` did not thread resolver closures** (`src/Application.php`).
  `Application::run()` accepted `tenants_resolver` and `roles_resolver` in `$config['auth']` but
  `buildAuthRouteOptions()` only flat-merged `features` and `hooks` — the resolver closures were
  silently dropped. This forced the canary platform to bypass `Application::run()` entirely and call
  `ExternalAuthRoutes::register()` directly with a manual bootstrap. Fixed: both closures are now
  forwarded to the options array passed to `ExternalAuthRoutes::register()`, which already accepts them.
  Platforms can now wire the card model via the standard `Application::run()` config without any manual
  bootstrap replacement.

- **Defect 4 — `JWT_ISSUER` defaulted silently to `'example.com'`** (`src/Auth/RsaJwtHandler.php`).
  `generateToken()` used `$env->JWT_ISSUER ?? 'example.com'` — minting cards with `iss=example.com`
  when `JWT_ISSUER` was unset. These cards passed local validation (both sides using the placeholder)
  but broke the moment `JWT_ISSUER` was set to a real value. Fixed: `generateToken()` now throws
  `RuntimeException('JWT_ISSUER is not set or empty...')` if `JWT_ISSUER` is absent. Additionally,
  `verifyToken()` now skips the issuer check when `JWT_ISSUER` is unset rather than comparing against
  `'example.com'` — this allows `HybridCardJwtHandler`'s RSA-then-JWKS chain to return `false` cleanly
  and attempt the JWKS fallback without a false positive on the placeholder issuer.

- **Defect 5 — memberships guidance clarified** (framework-spec.md §6).
  The main-DB SQL function `auth_get_memberships()` returns empty (`WHERE false` stub). The correct
  approach is `ExternalAuthServiceClient::getMemberships(authHeader)` in the `tenants_resolver`.
  This is now documented in framework-spec.md §6.

### Changed

- `Application::buildAuthRouteOptions()` — now passes `tenants_resolver` and `roles_resolver` through
  to `ExternalAuthRoutes::register()`. Fully backward-compatible: platforms that do not supply these
  keys see no change in behaviour.

- `Application::buildJwtHandler()` — signature extended with `array $jwtConfig = []` to accept the
  `jwt.handler` injection key. Backward-compatible.

- `RsaJwtHandler::verifyToken()` — issuer check now skips (rather than failing against `'example.com'`)
  when `JWT_ISSUER` is unset. This improves `HybridCardJwtHandler` chain behaviour: the RSA path returns
  `false` cleanly, letting JWKS attempt the token.

- `framework-spec.md §6` — expanded cross-platform implementation guidance: role source-of-truth,
  memberships via HTTP client, identity bridge via email, gateway tenant restore, JWT_ISSUER enforcement,
  HybridCardJwtHandler default, TenantUrlMatchMiddleware guidance, RequireCardMiddleware guidance, and a
  reference `auth.php` + `index.php` config snippet for multi-tenant platforms.

### Tests

- Added `HybridCardJwtHandlerTest` (7 tests) — validates platform card via RSA path, rejects wrong-key
  token, generation round-trip, JWT_ISSUER fail-loud on generate, JWT_ISSUER-unset skip on verify,
  invalid JWT returns false.
- Added `RequireCardMiddlewareTest` (7 tests) — no claims pass-through, empty claims pass-through, card
  with tenant_id passes, passport on business route 403, null tenant_id 403, contrast with
  RequireTenantMiddleware (no 401 on absent claims), request not mutated.
- Added `ApplicationResolverThreadingTest` (4 tests) — tenants_resolver exposed via ExternalAuthConfig,
  null default, ExchangeRoute end-to-end with threaded resolvers, pre-fix 501 contrast test.

## [4.6.0] - 2026-06-21

### Fixed

- **Mid-path `{id}` parameter undeclared in sibling method signatures — TS2304 under strict tsc (`cli/generate-client.php` `buildGroupMethods()` / `buildMethodTs()`).** When a resource group has multiple methods sharing a path parameter in a non-tail position — e.g. `GET /routes/{id}` alongside `POST /routes/{id}/start` and `POST /routes/{id}/assign-driver` — only the first method (which happened to have `{id}` as its tail) was declaring `id: string | number` in its TypeScript signature. The sibling methods had `${id}` interpolated in their URL template (because `buildUrlTemplate()` replaces ALL `{param}` segments with `${id}`) but their method signatures were emitted as `(data?) =>` without the `id` parameter — producing `TS2304: Cannot find name 'id'` under strict `tsc`. Detected in production Docker builds on multiple platforms on 2026-06-21; dev builds passed because dev mounts a pre-built dist and never runs `tsc` on the generated client.

  Root cause: `buildMethodTs()` received a `$tailId` flag from `hasTailId($path)`, which only checked whether the LAST path segment is a `{param}`. Routes with `{id}` in a non-tail position (followed by an action segment like `/start`, `/suspend`, `/assign-driver`, `/update`, `/delete`) returned `hasTailId=false` and therefore received the no-id method signature even though their URL template required `id`.

  Fix: replaced `hasTailId()` with a new `templateNeedsIdParam(string $path, string $serviceName, bool $isTenantScoped): bool` helper that strips the tenant prefix (`/{service}/tenant/{tenantId}` for T3, `/tenant/{param}` for T2/admin) and then checks whether any `{param}` placeholder remains in the path — which is exactly the condition under which `buildUrlTemplate()` will emit `${id}` in the template. The `$tailId` parameter to `buildMethodTs()` is renamed `$needsIdParam` to reflect its corrected semantics.

  Affected route shapes: any resource group with `POST /resource/{id}/action` or `GET /resource/{id}/sub-resource` siblings alongside a plain `GET /resource` or `POST /resource/create`. This is an extremely common REST + RPC pattern (inventory update/delete, route start/assign, tenant suspend, etc.) — all affected platforms had it.

- **Systemic gap: generator test suite never compiled its emitted TypeScript.** All prior generator tests checked string patterns in `client.ts` but never ran `tsc` on the output. This allowed broken TypeScript to ship green through the test suite and only fail at prod Docker build time — the fourth generator defect found this way in a single day. Added four `tsc --noEmit` compile-gate tests (see Tests section below) that prevent this class of defect from shipping again.

### Added

- `templateNeedsIdParam(string $path, string $serviceName, bool $isTenantScoped): bool` — helper that correctly determines whether the emitted URL template will contain `${id}` by scanning all non-tenant path segments. Replaces `hasTailId()` as the method-signature decision gate in `buildGroupMethods()`.
- `hasAnyPathParam(string $path): bool` — utility that detects any `{param}` or `:param` placeholder anywhere in a path (not yet wired into the main flow, available for future use).

### Tests

- Added `test_mid_path_id_param_declared_in_sibling_post_methods_t3` — T3 portal: `POST /routes/{id}/start` and `/assign-driver` must declare `id` in signature; `list` and routes without id must not. Verifies URL template interpolation position.
- Added `test_mid_path_id_param_declared_in_admin_sibling_post_methods` — admin: `POST /tenants/{id}/suspend` must declare `id` in signature.
- Added `test_mid_path_id_param_declared_in_update_delete_action_methods` — portal: `POST /items/{id}/update` and `/items/{id}/delete` must declare `id` in signature.
- Added `test_generated_portal_client_compiles_under_strict_tsc` — **compile gate**: generates portal package from the mid-path fixture and runs `tsc --project tsconfig.json --noEmit` (strict mode ON, as in prod). Fails with the full `tsc` error output if compilation fails.
- Added `test_generated_admin_client_compiles_under_strict_tsc` — same compile gate for the admin (non-tenant-scoped) package.
- Added `test_generated_t2_client_compiles_under_strict_tsc` — compile gate for T2 (no URL tenant segment) client.
- Added `test_full_fixture_compiles_under_strict_tsc` — compile gate on the full A1–A6 fixture (streaming, infra exclusion, explicit action overrides, RPC verbs, portal + admin) — the test that would have caught every prior generator emission defect.
- `findTscBinary(): ?string` — locates `tsc` from sibling npm packages in the repo tree (`stonescriptphp-client-core`, `stonescriptphp-auth-client`, etc.); tests are skipped gracefully if none is found.
- Total: 58 tests in `ClientGeneratorV4Test` (was 51); full suite 322 tests all passing.

## [4.5.0] - 2026-06-21

### Fixed

- **Multi-scope clobber — every multi-service platform affected (`cli/generate-client.php` main generation loop).** When a `routes.php` declares multiple backend services (e.g. `portal` + `admin`), running `php stone generate client portal` then `php stone generate client admin` caused the second run to overwrite the first run's `portal/package.json` name with the admin scope — leaving both packages named `...-admin-client`. Root cause: `derivePackageName()` was called with `$scopeArg` (the CLI argument) instead of `$serviceName` (the service currently being generated). Every multi-service platform with multiple Angular service directories was affected. Fixed by passing `$serviceName` to `derivePackageName()` so each service package always gets its own correct name regardless of which scope arg was passed on the CLI.

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
- **`php stone generate client <scope>` — scope-derived package naming.** The `<scope>` positional argument (the Angular service directory name: `portal`, `admin`, `www`, `business`, …) is now **required**. The generator derives the npm package `name` deterministically as `{composer.json name}-{scope}-client` (e.g. `exampleapp-api` + `portal` → `exampleapp-api-portal-client`). This replaces the prior `@stonescript/api-client-{service}` convention. The `--service=` filter remains for single-package generation. Omitting `<scope>` is a hard error with a usage message.

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
