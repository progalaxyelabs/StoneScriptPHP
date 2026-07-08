# Routing Consolidation — Execution Plan & Environment Map

**Status:** Phase 1 complete (v6.0.0, not yet published — user publishes to Packagist). Phase 2 not started.
**Why this file exists:** this is a large, multi-repo, multi-phase task
authorized directly by the user (not derived from `TESTABILITY-SPEC.md`, though
related to the same routing investigation). Written to disk because the task
explicitly anticipated a context compaction mid-session — if you're reading
this after a compaction, **this file plus git log/status in each repo below is
the source of truth for where things stand,** not conversational memory.

---

## 0. The decision this executes

Earlier investigation (this session, same conversation) found the framework
had accumulated **four coexisting routing philosophies** since a Jan 2026
architecture pivot: two router engines (one dead), three `routes.php` config
formats, two Route-Handler authoring patterns, and a CLI generator
(`generate-route.php`) that is actively incompatible with both the official
skeleton's format AND the real 11-platform fleet's format. Full evidence trail
is in the conversation history and summarized in `TESTABILITY-SPEC.md`'s
Non-Goals section (routing fragmentation is called out there as "related,
tracked separately" — this file is that separate tracking).

**User's explicit decision:** consolidate onto exactly ONE routing
implementation — the one that's actually load-bearing in production across all
11 real platforms. **No deprecation window. Hard break.** Two repos must NOT
be developed interleaved — finish and validate one fully before starting the
next.

**The one true way (confirmed, not assumed, by grepping all 11 platforms):**
- Engine: `Routing\Router` (the current, middleware-pipeline one)
- Config format: flat array, `'GET' => ['/path' => ['handler' => X::class, 'service' => 'portal', 'group' => 'billing', 'action' => ..., 'is_public' => ...]]`
- Route handler: `class XRoute implements IRouteHandler` **directly** — no `BaseRoute`, no separate Service/Contract/DTO split.

**Confirmed zero real-world usage (safe to delete):**
- Legacy `StoneScriptPHP\Router` + `RequestParser`/`GetRequestParser`/`PostRequestParser`/`OptionsRequestParser`/`NullRequestParser` (`src/Router.php`) — zero PHP call sites anywhere across framework/skeleton/all 11 platforms (only stale doc mentions + one unrelated npm package false-positive).
- `BaseRoute` — zero `extends BaseRoute` anywhere in any of the 11 platforms' route directories. Only the generator ever produced it.
- Format 1 (`'public'`/`'protected'` sections) — used ONLY by `StoneScriptPHP-Server`'s static skeleton `routes.php`. No real platform uses it.
- Format 3 (programmatic `$router->group()` route registration) — zero call sites anywhere, including inside the framework's own `Application.php`.
- `cli/new.php`'s OWN inline `routes.php` template disagrees with both — a third, different shape (flat, but no `service`/`group`/`handler` keys at all).
- `cli/generate-route.php` is not just inconsistent but **actively broken**: it reads `$routeData['class']` (doesn't exist in real routes, which use `'handler'`) and would corrupt any real platform's `routes.php` if run today.

---

## 1. Phased execution plan (this order, no skipping/interleaving)

### Phase 1 — `StoneScriptPHP` core framework (this repo). DONE (v6.0.0).
1. [x] Deleted `src/Router.php` (legacy `Router` + `RequestParser` family),
   `tests/Unit/RouterTest.php` (all 11 tests were either permanently-skipped
   references to the deleted class, or unrelated to it), and the orphaned
   `tests/Fixtures/ExceptionThrowingRoute.php` fixture. Removed the dead
   `use StoneScriptPHP\Router;` import from `ContentTypeTest.php`.
2. [x] Deleted `src/BaseRoute.php`.
3. [x] Simplified `Routing\Router::loadRoutes()` to the flat format only —
   the `'public'`/`'protected'` shape now throws a clear migration exception
   instead of silently registering unreachable routes.
4. [x] Removed `Router::group()` + its `groupContext` state entirely
   (confirmed dead — zero call sites anywhere). `Router::scope()`
   (middleware-only, a different concern) left untouched.
5. [x] Rewrote `cli/generate-route.php`: reads/writes the flat format with
   `handler`/`service`/`group`/`action`/`is_public` keys (added
   `--service=`/`--group=`/`--action=`/`--public` flags); scaffolds a single
   `IRouteHandler`-implementing class, not the Service/Contract/DTO/BaseRoute
   split. Verified end-to-end with a manual harness: pre-existing routes'
   metadata now survives regeneration byte-for-byte (previously would have
   been destroyed — confirmed both before and after with a real fixture).
   Also fixed `cli/generate-client.php`'s `loadRoutesFromPlatform()`, which
   had its own separate `$router->group()`-injection code path — routes.php
   must return an array now, matching the runtime's own requirement.
6. [x] `cli/new.php`'s inline `routes.php`/`HomeRoute.php` templates were
   **already correct** (flat format, direct `IRouteHandler`, no metadata
   keys but that's valid per `normalizeRouteConfig()`) — verified, no change
   needed. `cli/init.php`'s templates were the broken ones instead (see #7).
7. [x] Fixed `cli/init.php`'s scaffolded `public/index.php` (called
   `new Router(); $router->handleRequest()` — neither ever existed) and
   example route (used `#[Route]`/`#[GET]` attributes that were never
   implemented anywhere in the framework — `StoneScriptPHP\Attributes\Route`/
   `GET` don't exist). Both would have fatally errored on first use. Also
   added routes.php creation to `init.php` (it previously created none at
   all). Fixed a stale doc example in `src/Auth/MULTI-AUTH.md` with the same
   class of fictional API (`$router->group()` 1-arg form + a `->middleware()`
   chain method that never existed).
8. [x] Updated `SPEC.md` §3 Routing Conventions to describe the one
   supported format (also fixed a fictional `'scope'`/`'scopes'` key
   convention that was never implemented — real key is `service`). Updated
   §10 Gap 5 (resolved — was exclusively about the deleted legacy router),
   Gap 2 and Gap 8 (citations pointed at deleted code, updated to current).
9. [x] Updated/fixed tests referencing removed classes/formats:
   `tests/Unit/RouteScopeTest.php` (replaced the Format-1 test with one
   asserting the new loud-failure behavior), `tests/Unit/ClientGeneratorV4Test.php`
   (16 `$router->group()` usages across 9 test methods + 4 heredoc-embedded
   routes.php fixtures, converted to direct `get()`/`post()` calls / array
   returns — all 62 tests in this file pass).
10. [x] Major version bump `5.12.0` → `6.0.0` (breaking removal) +
    `CHANGELOG.md` entry documenting every removal explicitly.
11. [x] Full test suite green (517/517, zero regressions vs. pre-Phase-1
    baseline) and PHPStan back to its pre-existing baseline (17 errors,
    same as before Phase 1 — fixed 2 new ones my own `Database::fake()`
    docblock introduced along the way, unrelated to routing but caught
    during verification). Committed + pushed to `swarm`.
12. **Next: stop here. User publishes `progalaxyelabs/stonescriptphp` v6.0.0
    to Packagist. Do not attempt to publish the PHP package from this
    agent.**
11. **Stop. Report to user. User publishes to Packagist themselves — do not
    attempt to publish the PHP package.**

### Phase 2 — `StoneScriptPHP-Server` skeleton repo. NOT STARTED.
Blocked on Phase 1 being published (user does this) so `composer update` can
actually pull the fixed framework.
1. `composer update progalaxyelabs/stonescriptphp` to pull the new framework version.
2. Rewrite the skeleton's default `src/config/routes.php` from Format 1 to
   the flat format with `handler`/`service`/`group`/`is_public` keys.
3. Update any skeleton docs (`HLD.md`, `SPEC.md`, `README.md`,
   `docs/CLIENT-SDK-SPEC.md`) that reference the old format or `BaseRoute`.
4. Full test suite green. Commit + push to `swarm`.
5. **Stop. User publishes `stonescriptphp-server` to Packagist themselves.**

### Phase 3 — Local integration test via `progalaxy-platform`. NOT STARTED.
`progalaxy-platform` is the designated sandbox for this work — explicitly
authorized to break repeatedly, no customer risk ("unknown to the world").
1. Pull latest framework + skeleton changes into `progalaxy-platform`
   (`docker/api`) via composer update.
2. Test on `devvmlocal` AND via local dev Docker containers (both must work).
3. If the Angular client generation contract (`CLIENT-SDK-SPEC.md`) needs to
   change as a result of the routing format consolidation, or if
   `ngx-stonescriptphp-client`/`stonescriptphp-client-core` need fixes — make
   those changes too, publish to **Verdaccio** (not public npm), reinstall in
   `progalaxy-platform`. Explicit permission granted to modify/republish
   these npm packages as needed.
4. Iterate until fully working locally. No shortcuts, no "good enough."

### Phase 4 — Live deploy test on `vm-progalaxy-platform`. NOT STARTED.
1. Deploy latest `progalaxy-platform` (with all changes) to
   `vm-progalaxy-platform` via the normal swarm-deploy workflow (§12/§6a of
   the app-dev role instructions).
2. Test the live site end-to-end.
3. Only once this is clean is the routing consolidation considered done.

---

## 2. Environment map (for reference across a long session)

| Name | What it is | How to reach it |
|---|---|---|
| **This workstation** | Where this agent runs. Has direct filesystem access to all repos under `/ssd2/projects/progalaxy-elabs/divisions/`. | Local `Bash`/`Read`/`Edit` tools. |
| `StoneScriptPHP` | Core framework repo. Phase 1 target. | `/ssd2/projects/progalaxy-elabs/divisions/opensource/stonescriptphp/StoneScriptPHP` |
| `StoneScriptPHP-Server` | Skeleton repo. Phase 2 target. | `/ssd2/projects/progalaxy-elabs/divisions/opensource/stonescriptphp/StoneScriptPHP-Server` |
| `progalaxy-platform` | Sandbox test platform, Phase 3/4. Explicitly disposable/breakable — no real customers. | `/ssd2/projects/progalaxy-elabs/divisions/student-platforms/progalaxy/progalaxy-platform` |
| `devvmlocal` | Local dev VM for DB/gateway access during Phase 3. | `ssh devvmlocal` (start via `virsh start devvmlocal` if unreachable, per role instructions §7) |
| `vm-progalaxy-platform` | Live deploy target for Phase 4. | via `swarm-manager` per standard swarm-deploy workflow |
| `swarm-manager` | Docker Swarm manager — service logs, deploy-manager CLI. | `ssh swarm-manager` |
| Verdaccio (private npm registry) | Where `@progalaxyelabs/*` npm packages (ngx-stonescriptphp-client, stonescriptphp-client-core, etc.) publish. Explicit permission to modify + republish here if the routing work requires it. | `https://progalaxyelabs-test-packages.azurewebsites.net`, creds at `/ssd2/projects/progalaxy-elabs/verdaccio-registry-creds.txt` |
| Packagist | Public PHP package registry for `progalaxyelabs/stonescriptphp` and `progalaxyelabs/stonescriptphp-server`. **User publishes here themselves — this agent does not.** | N/A — not this agent's action |
| `swarm` git remote | Every repo's actual push target. **Never push to `origin`.** | Already configured per-repo (confirmed on `StoneScriptPHP` this session; `StoneScriptPHP-Server` had none as of last check — verify before Phase 2 push) |

---

## 3. Related work already completed this session (do not redo)

From `TESTABILITY-SPEC.md` (a separate but related effort, same conversation):
- **T1-1** (`Routing\IncomingRequest`, injectable request for `dispatch()`) — Done, `StoneScriptPHP` commit `5910db0`.
- **T2-1** (`Database::fake()`/`isFaked()`/`clearFakeMode()`) — Done, `StoneScriptPHP` commit `123c023`.
- Both already pushed to `swarm`. `composer.json` is at `5.12.0` as of those commits — Phase 1's major bump starts from there.

---

## 4. Status log (update this as phases complete)

- [x] Phase 1 — StoneScriptPHP core framework (v6.0.0, commit pending push — see git log in this repo). Awaiting user's Packagist publish before Phase 2 can `composer update`.
- [ ] Phase 2 — StoneScriptPHP-Server skeleton
- [ ] Phase 3 — progalaxy-platform local integration test
- [ ] Phase 4 — vm-progalaxy-platform live test
