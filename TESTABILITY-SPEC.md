# StoneScriptPHP Testability Spec

**Status:** Draft — requirements defined, implementation not started.
**Purpose:** Define what "testable" means for a StoneScriptPHP application at each
layer of the stack, assess the current framework against that bar with concrete
evidence, and enumerate a prioritized, checkable requirements list so gaps can be
closed one at a time.

**This is not a proposal for a new test framework or test runner.** PHPUnit (already
the framework's dependency) is sufficient. What's missing is *seams* in the
framework's own code — places where a test needs to substitute a fake collaborator
(a request, a database, a response writer) and currently cannot.

---

## Table of Contents

1. [Testing Taxonomy](#1-testing-taxonomy)
2. [Current State Assessment](#2-current-state-assessment)
3. [Requirements](#3-requirements)
4. [Non-Goals](#4-non-goals)
5. [Sequencing](#5-sequencing)
6. [Open Questions](#6-open-questions)
7. [Cross-References](#7-cross-references)

---

## 1. Testing Taxonomy

Three tiers, each with a different collaborator that must be fake/absent for the
test to count as belonging to that tier:

### Tier 1 — Route-level (HTTP contract) testing
Tests the wiring: does this route enforce the right HTTP method, required headers,
required middleware, request body shape, response body shape, response headers,
status code, and cookies — **without executing real business logic or hitting a
real database.** The handler can be a stub; what's under test is the router +
middleware pipeline + response-writing layer.

### Tier 2 — Business logic level testing
Tests the handler's actual behavior: input validation (including validation rules
that depend on other fields in the same request), business logic against a **fake
database** (no live Postgres/gateway), and response body structure compliance for
real (non-stub) handlers.

### Tier 3 — Database level testing
Tests SQL functions and migrations against a **real** PostgreSQL instance (via the
StoneScriptDB Gateway, per the framework's gateway-only architecture — see
`SPEC.md` §5). This tier is intentionally *not* mockable — SQL semantics
(constraints, triggers, RAISE EXCEPTION, transaction behavior) cannot be faithfully
faked, and shouldn't be.

---

## 2. Current State Assessment

Findings below are cited to exact files/lines as of `v5.10.1` (commit `fb85b64`).

### Tier 1 — currently ~0% testable

- **`Routing\Router::dispatch()` takes no parameters.** It reads
  `$_SERVER['REQUEST_METHOD']`, `$_SERVER['REQUEST_URI']`, `$_GET`/`$_POST`,
  `file_get_contents('php://input')`, and `getallheaders()` directly
  (`src/Routing/Router.php:448-471`, `:711-754`). There is no injectable request
  value. Testing method/header/body enforcement means mutating PHP superglobals
  in-process — and `php://input` specifically cannot be rewritten in a normal
  PHPUnit run without a stream-wrapper hack. No test in the current suite does
  this; `tests/Unit/RouterValidationTest.php` bypasses `dispatch()` entirely and
  calls `->process()` directly on stub handlers with properties pre-set.
- **Method-not-allowed semantics are gone, not just untested.**
  `matchRoute()` (`src/Routing/Router.php:532-569`) returns `null` for any
  method+path miss — there is no distinction between "path doesn't exist" (404)
  and "path exists under a different method" (405). The framework's own `e405()`
  helper still exists (`src/error_handler.php:11-14`) but its only caller is the
  deprecated legacy `Router` (`src/Router.php`), which is dead code. A test
  asserting correct 405 behavior would fail today, correctly, because the
  behavior doesn't exist.
- **Response headers/status/cookies are unobservable.** They're set via bare
  `header()`, `http_response_code()`, `setcookie()` calls scattered across
  `Application.php` (lines 258-306), `ExceptionHandler.php` (132-153),
  `Auth/CookieHelper.php` (53, 87, 117, 151 — refresh-token cookie, CSRF cookie),
  and multiple middleware classes. None of these are behind an interface. Without
  Xdebug (not guaranteed present, not installed in `Dockerfile.prod`), there is no
  way to assert "this route set a Secure, HttpOnly cookie" except a real HTTP
  round-trip against a running server — an E2E test, not a unit test.

### Tier 2 — partially testable

- **Response body assertions: fine.** `process()` returns a real `ApiResponse`;
  call it directly and assert on `status`/`message`/`data`/`errors`. No gap.
- **Single-field validation: fine.** `Validator` (`src/Validator.php`) is a plain,
  framework-decoupled class. Test it standalone.
- **Dependent/cross-field validation: not expressible, so not testable.**
  `Validator::applyRule()` (`src/Validator.php:71-93`) dispatches to built-in
  single-field rules or a custom validator callback of signature
  `($value, $parameter)` — the callback never receives sibling field values.
  There is no `required_if`/`required_with`/`same:` rule family. A handler that
  needs "shipping_address required only if delivery_method=ship" must hand-write
  that check inside `process()`, which makes it business logic, not validation —
  collapsing the Tier 1/Tier 2 boundary this spec is trying to keep clean.
- **Business logic against a fake DB: not possible today.** `Database`
  (`src/Database.php`) is a private-constructor singleton
  (`private function __construct()` line 22; `private static ?Database $_instance`
  line 18) with zero injection seam. Confirmed by the framework's own test suite:
  `tests/Unit/DatabaseTest.php::test_fn_accepts_array_parameters()` skips itself
  unless `DB_GATEWAY_URL` is set — *"Integration test: Database::fn() calls the
  live gateway (v3 gateway-only)"* — rather than faking the gateway. Every other
  test in that file only exercises the pure row-mapping helpers
  (`array_to_class_object`, `result_as_table`), which don't touch the network.
  No handler that calls `Database::fn()` — i.e. essentially every handler that
  does real work — can be business-logic-unit-tested today without a live
  gateway+Postgres.
- **`AuthContext` is already a solved example of what Tier 2 needs.**
  `Auth/AuthContext.php` is a static singleton with `setUser()`/`clear()` (the
  latter's docblock literally says "for testing"). This is the pattern `Database`
  should follow — cited here as the positive precedent, not a gap.

### Tier 3 — mostly healthy, needs consolidation not redesign

- Correct DB-testing infrastructure already exists: `php stone migrate verify`
  (schema drift check), `validate sqlintegrity` (static undefined
  table/function reference checker), gateway migration commands with explicit
  dataloss-safety flags (`--allow-drop-table`, etc. — see `cli/gateway-migrate-*.php`).
  A real `tests/Integration/` folder exists in the framework repo
  (multi-tenancy, connection-pool, JWT config) proving the "hit a live
  environment" pattern is established practice, not a gap to invent.
- **Gap:** no reusable, shared SQL-function test harness. Each of the 11
  platforms hand-writes its own ad hoc integration test calling `Database::fn()`
  against a live gateway, with no shared base class for tenant provisioning,
  transaction rollback/isolation between tests, or teardown.

---

## 3. Requirements

Each requirement has an ID, a one-line acceptance criterion, priority, and rough
effort. Status column is for tracking as these get implemented one at a time.

### Tier 1

| ID | Requirement | Acceptance Criteria | Priority | Effort | Status |
|----|---|---|---|---|---|
| **T1-1** | `Routing\Router::dispatch()` accepts an optional injected request (method, path, headers, query params, parsed body), falling back to current superglobal-reading behavior when omitted (backward compatible). | A test can call `$router->dispatch($fakeRequest)` and get a real `ApiResponse` back without touching `$_SERVER`/`$_GET`/`php://input`. Existing production call sites (`Application::run()`) keep working unmodified. | P0 | M | **Done (v5.11.0)** — `Routing\IncomingRequest` value object + `dispatch(?IncomingRequest $incoming = null)`. `CookieHelper::getRefreshToken()`/`getCsrfToken()` also gained an optional cookie-map param as part of this (request context now carries a `'cookies'` key). Not done: auto-wiring `$request['cookies']` into handlers that call `CookieHelper` internally (`RefreshRoute`/`LogoutRoute`/`CsrfHelper`) — tracked as follow-up. Tests: `tests/Unit/RouterIncomingRequestTest.php`, `tests/Unit/CookieHelperInjectionTest.php`. |
| **T1-2** | Introduce a `ResponseWriter` interface (`setStatus(int)`, `setHeader(string,string)`, `setCookie(...)`) that `Application`, `ExceptionHandler`, `CookieHelper`, and all middleware call through instead of bare `header()`/`http_response_code()`/`setcookie()`. Ship a real implementation (current behavior) and a `RecordingResponseWriter` test double. | A test can assert `$writer->getHeader('Set-Cookie')` contains the refresh-token cookie with `HttpOnly`/`Secure` flags, without a live HTTP server. | P0 | M | Not started |
| **T1-3** | Restore correct 405 semantics: when a path matches under a different HTTP method, return 405 with an `Allow` header listing valid methods, instead of a blanket 404. | A test hitting `POST /health` (GET-only route) gets HTTP 405 with `Allow: GET`, not 404. | P1 | S | Not started |
| **T1-4** | Ship a `dispatchTestRequest($method, $path, $headers = [], $body = null)` test helper built on T1-1/T1-2, as part of the framework's own test-support code (not per-platform copy-paste). | A route-level test can be written in ≤10 lines covering method, headers, body, response status, response headers, and cookies, with no live server. | P1 | S | Not started (depends on T1-1, T1-2) |
| **T1-5** | Document (and if needed, extend `getRouteMeta()`) the sanctioned way to assert "this route requires middleware X" — either via route metadata or via direct middleware unit tests. | A written example exists showing how to assert a route is behind `JwtAuthMiddleware`/`RequireApiTokenMiddleware` without a full dispatch. | P2 | S | Not started |

### Tier 2

| ID | Requirement | Acceptance Criteria | Priority | Effort | Status |
|----|---|---|---|---|---|
| **T2-1** | Give `Database` a test seam: `Database::setGatewayClient(GatewayClient $client)` (or `Database::fake(array $responses)`) plus `Database::reset()` for `tearDown()`. Mirrors the existing `AuthContext::setUser()`/`clear()` pattern. | A test can stub `Database::fn('create_project', [...])` to return a canned row, call a real handler's `process()`, and assert on the resulting `ApiResponse` — no live gateway, no network call. | **P0** | S–M | **Done (v5.12.0)** — `Database::fake(array $responses)` / `isFaked()` / `clearFakeMode()`. Response values are `array` (canned rows) or `\Closure(array $params): array` (dynamic/sequential/error-simulating — see resolved Open Question below). Intercepted inside `_fn()`'s existing try/catch, so a fake that throws `GatewayException` gets the same `connection_failed`→`TenantDatabaseUnavailableException` translation as real errors, no parallel path. Unregistered-function-while-faked throws immediately rather than falling through to a real call. `isConnected()`/`getGatewayClient()` made fake-mode-aware. Tests: `tests/Unit/DatabaseFakeModeTest.php` (14 tests, including an end-to-end fake→`result_as_table()` mapping test and a regression guard proving unfaked `Database::fn()` is completely unaffected). |
| **T2-2** | Extend `Validator` to support cross-field/dependent rules (`required_if:field,value`, `required_with:field`, `same:field`, at minimum). Requires custom-validator callbacks to optionally receive the full data array, not just the single field's value. | A rule like `'shipping_address' => 'required_if:delivery_method,ship'` is enforced and covered by a passing/failing test pair. | P1 | M | Not started |
| **T2-3** | Add a canonical example + a `HandlerTestCase` base class combining T2-1 (fake DB) + `AuthContext::setUser()` (already exists) + direct handler instantiation, shipped from the framework (not re-invented per platform). | A new platform can write "test create-project business logic" by extending one base class, with no boilerplate beyond stubbing DB responses and setting the fake user. | P1 | S | Not started (depends on T2-1) |
| **T2-4** | Ship an `assertApiResponseOk()`/`assertApiResponseError()` PHPUnit assertion trait for response-body-structure compliance. | Response-shape assertions read as one-liners; no platform hand-rolls its own `ApiResponse` shape checks. | P2 | S | Not started |

### Tier 3

| ID | Requirement | Acceptance Criteria | Priority | Effort | Status |
|----|---|---|---|---|---|
| **T3-1** | Ship a shared `SqlFunctionTestCase` base class: provisions/tears down a disposable tenant DB (or wraps each test in a transaction rollback) against `DB_GATEWAY_URL`, so platforms stop hand-rolling this per repo. | A platform can test one `.pgsql` function in ≤15 lines against a real, isolated DB state, with automatic cleanup. | P2 | M | Not started |
| **T3-2** | Formalize `php stone migrate verify` as a CI gate, with a documented recipe: create disposable tenant → migrate → verify → drop, reusing existing `gateway:register-tenant`/`gateway:migrate-tenant` commands. | A documented, copy-pasteable CI step exists; at least one platform's pipeline runs it. | P2 | S | Not started |
| **T3-3** | Explicit guard: T2-1's fake-DB seam must be clearly separated from Tier 3 (no accidental "fake DB" use inside what's meant to be a real-DB integration test). | Test naming/directory convention (`tests/Unit/` vs `tests/Integration/`) enforces this; document it here and in the contributor guide. | P2 | S | Not started (depends on T2-1) |

---

## 4. Non-Goals

- **Not** replacing PHPUnit or introducing a new test runner/framework.
- **Not** mandating 100% test coverage — this spec defines what must be
  *possible* to test cleanly, not a coverage target.
- **Not** resolving the multiple-routing-format fragmentation (flat vs.
  public/protected vs. programmatic `group()`/`scope()`, or the `BaseRoute` vs.
  direct-`IRouteHandler` split) — that's a separate, already-identified problem.
  T1-1/T1-4 should work regardless of which route-registration format a platform
  uses, but consolidating the formats themselves is out of scope here.
- **Not** proposing to mock SQL/Postgres behavior for Tier 3. Real DB, always.

---

## 5. Sequencing

Recommended order, ranked by leverage-per-effort (highest first):

1. **T2-1** (Database fake seam) — smallest change, unlocks the single biggest
   testability hole in the framework. Every handler that does real work is
   currently blocked on this.
2. **T1-1 + T1-2** (injectable request + response writer) — unlocks all of
   Tier 1, which is currently at zero, not partial.
3. **T1-3** (405 semantics) — small, pairs naturally with T1-1, and is a
   correctness fix independent of testing.
4. **T2-2** (dependent validation rules) — medium effort, closes a real
   feature gap that Tier 2 testing exposed.
5. **T1-4, T1-5, T2-3, T2-4** — developer-experience polish once the
   underlying seams exist; makes adoption easy across all 11 platforms.
6. **T3-1, T3-2, T3-3** — Tier 3 is already the healthiest tier; these are
   consolidation/documentation work, lower urgency.

---

## 6. Open Questions

- ~~**T1-1 shape:**~~ **Resolved (v5.11.0):** a small typed `IncomingRequest`
  value object at the `dispatch()` boundary only — internal pipeline array
  shape (`MiddlewareInterface::handle(array $request, ...)`) deliberately left
  untouched, to avoid rippling into every middleware class across the 11-platform
  fleet. `dispatch()` destructures the object into the same array immediately;
  everything downstream is unaware anything changed. See T1-1's row above and
  `src/Routing/IncomingRequest.php`.
- ~~**T2-1 fake shape:**~~ **Resolved (v5.12.0):** a flat map,
  `function_name => array $rows | \Closure(array $params): array`. Explicitly
  restricted to `array`/`\Closure` rather than general `is_callable()` (which
  would also match ambiguous `['ClassName', 'method']`-shaped canned rows).
  Per-call-sequence stubbing (the same function called twice in one test,
  different responses) is handled by a `\Closure` maintaining its own counter
  via `use (&$counter)` — deliberately *not* a queue-array convenience, since
  "one response containing multiple rows" vs. "a queue of N single responses"
  is ambiguous without inventing a wrapper type, and closures already solve
  this idiomatically. See T2-1's row above and `src/Database.php::fake()`.
- **T2-2 custom validator backward compatibility:** changing the custom-validator
  callback signature to `($value, $parameter, $allData)` — do we break existing
  registered custom validators with 2-arg callables, or detect arity via
  reflection and support both signatures?
- **Where does `ResponseWriter` (T1-2) live** — core `StoneScriptPHP` package, or
  should platforms be able to override it (e.g. a platform that needs a
  non-standard cookie policy)? Likely needs to be swappable via the same config
  mechanism as `JwtHandlerInterface` (`Application::run(['response_writer' => ...])`).

---

## 7. Cross-References

- `SPEC.md` §10 **Gap 4** (Gateway Exception Mapping Not Complete) — affects the
  reliability of Tier 2 error-path assertions (a test can't distinguish "conflict"
  from "validation failure" from "internal error" if the framework itself
  collapses them all to 500).
- `SPEC.md` §10 **Gap 5** (Router Doesn't Support PUT/PATCH/DELETE) — relevant to
  T1-1; the injectable request abstraction should be designed with these methods
  in mind even if the legacy router never supported them.
- `SPEC.md` §10 **Gap 8** (`IRequest`/`IResponse` Empty Interfaces) — relevant to
  T2-2; dependent validation rules interact with DTO shape, and these marker
  interfaces currently carry no contract to hang that on.
- Routing-format fragmentation (flat vs. public/protected vs. programmatic
  `group()`, and `BaseRoute` vs. direct-`IRouteHandler`) — related architectural
  finding, tracked separately, out of scope for this spec (see Non-Goals).
