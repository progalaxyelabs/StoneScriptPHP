# Design: Platform-Owned Invitation System (auth stays out of it)

**Status:** DESIGN ONLY — no code written, no migrations run, no routes changed.
**Date:** 2026-07-21
**Author:** app-dev (investigation + design task)
**Scope:** `progalaxyelabs/stonescriptphp` framework (this repo). Cross-references
`progalaxyelabs-auth` and `aasaanwork-platform` as read-only context — neither was
modified to produce this document.

---

## 0. The non-negotiable constraint (verbatim, restated so it can't be forgotten)

> "why auth db has anything to do with invitations? roles belong in api/platform.
> invitations shall only affect the api/platform. creating identity at auth shall not
> be gated by memberships. no invitation needed for someone to create account at auth.
> which platform they use that auth identity would be notified by api in the
> background not from the browser/client. invitation processing shall be handled by
> api/platform which might need a pre-condition that an identity shall be created
> first at the auth."

Concretely, for every design decision below:

- **`progalaxyelabs-auth` owns zero invitation data.** No `invitations` table, no
  invite/accept-invite endpoints, no role storage tied to an invite, no gate on
  identity creation keyed off invitation state.
- **Auth's only remaining job:** (a) create/verify identities via its existing,
  already-ungated OTP/OAuth flows, and (b) receive a server-to-server "identity X is
  now a member of tenant Y on platform Z" signal from the platform's own API, via the
  existing `POST /api/internal/create-membership`, secured by `X-Platform-Secret`.
  Nothing else.
- **The browser never tells auth about a membership.** The signal in (b) is
  API-to-API, "in the background," never issued from client-side JS.

If any part of this document routes invitation data, role assignment, or
invite-authorization logic through auth, that part is wrong and should be treated as
a bug in this document, not a precedent to build against.

---

## 1. Current-state summary

### 1.1 What existed in auth (being removed by a parallel task right now)

`progalaxyelabs-auth`'s repo, read live during this investigation
(`/ssd2/projects/progalaxy-elabs/divisions/administrative/progalaxyelabs/progalaxyelabs-auth`),
shows a removal already in progress (uncommitted `git diff`, touching
`handlers/{auth,identity,membership,otp}.rs`, ~900 lines net deleted). Still present on
disk as of this investigation (not yet deleted — the removal task hadn't reached the
schema/route layer yet):

- Table: `docker/auth/src/postgresql/main/postgresql/tables/006_create_invitations.pgsql`
- Functions: `auth_create_invitation`, `auth_check_pending_invitation`,
  `auth_claim_pending_invitation_by_email`, `auth_accept_invite_by_otp`,
  `auth_accept_invite_by_password`, `auth_accept_invitation`
- Endpoints: `POST /api/memberships/invite`, `POST /api/memberships/accept-invite`
  (+ `/api/auth/accept-invite` alias), `POST /api/internal/invite-member`
- Mechanics worth reusing conceptually (not the ownership, just the shape):
  - Token = 32 random bytes, stored as a **SHA-256 hash**, never the raw token
  - Expiry: 7 days
  - Error taxonomy: `invite_expired` / `invite_already_used` / `invite_not_found`
    (all documented in `AUTH-SPEC.md` §10, still live at the time of reading)
  - `invitation_email` template, with Mailpit routing for test-domain emails and
    ZeptoMail/SMTP otherwise
- The `invitation_pending` gate that blocked OTP register-send / OAuth signup when a
  pending invite existed for that email — this is precisely the "creating identity at
  auth shall not be gated by memberships" violation called out in the constraint, and
  it is being removed.

**`POST /api/internal/create-membership` is explicitly staying** (confirmed by
reading `docker/auth/src/handlers/internal.rs`, which is unmodified in the current
diff). Its current contract (`CreateMembershipRequest`/`CreateMembershipResponse` in
that file):

- Auth (X-Platform-Secret only, no role/permission check inside this handler)
- Input: `identity_id`, `tenant_id`, `platform_code`, `tenant_name?`,
  `tenant_slug?`, `tenant_db_schema`, `role?` (defaults `"owner"` if omitted),
  `roles?` (defaults `[role]`), `email?`, `client_ip?`, `user_agent?`,
  `idempotency_key?`
- Effect: calls `auth_register_account` (creates the row in auth's
  `tenant_memberships`), fires a **non-fatal, fire-and-forget signup notification
  email to `info@progalaxyelabs.com`** when `is_new_tenant=true`, and returns
  `membership_id`, `tenant_id`, `role`, `roles`, `is_new_tenant`, plus a directly
  usable `access_token`/`refresh_token` pair with `role` stamped into the JWT claims.
- Does **not** create identities or provision databases — purely a membership record
  + convenience token pair.

A same-repo, same-week design doc — `docs/ROLE-COLUMN-REMOVAL-DESIGN-3204.md`,
2026-07-20, read in full during this investigation — is directly relevant and is
treated as authoritative evidence for §3.4 below: it independently concludes that
`tenant_memberships.role` in auth is doing four different jobs, only one of which
("platform RBAC") is in scope for "roles belong in api/platform" — and that job is
**already** fully migrated out of auth (see §2 of that doc: `ExchangeRoute.php`'s
`roles_resolver` is the sole source of a card's role, never auth's DB). The remaining
three jobs (auth's own invite/update-membership permission gate, an unread
token-state discriminator, and medstoreapp's legacy bootstrap-seed read) are being
decomposed separately and are out of scope here except where they intersect
create-membership's request schema (§3.4).

### 1.2 What exists per-platform today (the pattern being retired)

Read live: `aasaanwork-platform`'s
`docker/api/src/App/Routes/Customers/PostInviteCustomerRoute.php` and its
`Vendors/PostInviteVendorRoute.php` sibling. Both are thin forwarders:

```php
$payload = json_encode([
    'email'      => $email,
    'role'       => 'customer',   // or 'vendor'
    'tenant_id'  => $tenantId,
    'invited_by' => $identityId,
]);
$ch = curl_init("$authUrl/api/memberships/invite");
curl_setopt_array($ch, [ ..., CURLOPT_HTTPHEADER => [
    'Content-Type: application/json',
    "X-Platform-Secret: $platformSecret",
], ... ]);
```

Two things worth carrying forward unchanged, because they are already correctly
platform-owned:

- **The authorization gate is already 100% platform-owned.** Both routes use
  `App\Lib\AdminScope::requireConsultant()` (a trait, not framework code) which reads
  the caller's *own platform card's* `role` claim — itself resolved by the platform's
  own `roles_resolver`, never by anything in auth — and 403s non-privileged callers.
  This pattern (fail-closed, platform-local role check) is exactly what should keep
  gating "who can invite" and "what role can be granted" once invitations move
  in-platform. Nothing about this needs to change.
- **The DTO shape** (`id` param, resolve target row → email/name, then decide role by
  business rule) is a reasonable model for the new platform-owned invite-create route.

What's wrong with this pattern, and the reason it's being retired: the `curl_init`
call target — `/api/memberships/invite` — creates and stores the invitation **in
auth's database**, which is exactly the coupling the constraint forbids. It also
duplicates this exact curl block per platform per invite-type (medstoreapp has its
own copy, per `DESIGN-multi-tenant-identity-onboarding.md` cross-references), with no
shared framework layer — a second reason to fix this centrally rather than
per-platform.

`DESIGN-multi-tenant-identity-onboarding.md` §2.3 (aasaanwork-platform) documents the
product-level ask this whole area exists to satisfy: a "Communications: invitations
you received" UI, sourced from `tenant_memberships WHERE status = 'invited'` — i.e.
**identity-scoped, not tenant-scoped**, a query that must work without touching any
tenant DB. §4.2 documents the desired role granularity (`owner` / `employee` /
`customer` / `vendor`, populated at invite-accept time) and confirms role resolution
already flows through `ExchangeRoute.php`'s resolvers, never a live SQL read of
auth's `tenant_memberships.role`. Both of these are still achievable under the new
platform-owned design — see §6 (deferred items) for what changes about the pending-
invitations UI specifically, since that UI's current design assumed auth held the
data.

### 1.3 What's now dead or dangling (a direct, real consequence of auth's removal)

**Framework code that no longer has anywhere to proxy to, effective the moment auth's
removal ships (git diff already touches the handlers; the endpoints themselves are
still on disk as of this reading but are explicitly being deleted per the removal
task's scope):**

- `src/Auth/ExternalAuth/Routes/InviteMemberRoute.php` — thin proxy to
  `ExternalAuthServiceClient::inviteMember()` → `POST /api/auth/invite` on the auth
  service. That auth-side endpoint is being removed.
- `src/Auth/ExternalAuth/Routes/AcceptInviteRoute.php` — thin proxy to
  `ExternalAuthServiceClient::acceptInvite()` → `POST /api/auth/accept-invite` on the
  auth service. Same fate.
- Both are wired into `DefaultTenantRouteProvider` (registers
  `POST {prefix}/invite-member` and `POST {prefix}/accept-invite` whenever
  `invite`/`accept_invite` feature toggles are on — true by default in
  `ExternalAuthConfig`), so **every platform still on the framework defaults has these
  two routes live and auto-registered today**, pointing at endpoints that are about
  to 404/error at the auth service. This is real, live-breaking work — see §5.
- `ExternalAuthServiceClient::inviteMember()` and `::acceptInvite()` (in
  `ExternalAuthServiceClient.php`) become dead methods — nothing in this design calls
  them, and nothing should, once auth's endpoints are gone.

**Frontend: a killed, uncommitted background task** in `ngx-stonescriptphp-client`
(confirmed via `git status`/`git diff` in that repo — `src/accept-invite-logic.ts`,
`src/lib/ui/lib/components/accept-invite/` untracked; `src/auth.service.ts`,
`src/auth-routes.ts`, `src/index.ts` modified) added:

- `AuthService.acceptInvite(token, displayName)` — POSTs to
  `this.buildApiUrl('/api/auth/accept-invite')`.
- `AcceptInviteComponent` — a standalone route (`/auth/accept-invite`), deliberately
  registered as a **sibling** of the `loginGuard`-protected shell (not nested under
  it), specifically so an already-authenticated user hitting an invite link for a
  *second* organization isn't bounced to `/post-login` before the invite token is
  ever submitted. This routing decision is correct and independent of where
  accept-invite processing lives — keep it.
- `extractAcceptInviteErrorCode()` / `describeAcceptInviteError()` in
  `accept-invite-logic.ts` — maps the `invite_expired` / `invite_already_used` /
  `invite_not_found` codes (same taxonomy as §1.1) to user-facing copy, framework-free
  and unit-testable.

**Important correction to the task brief's framing:** `buildApiUrl()`
(`joinApiUrl(this.environment.apiServer.host, path)`) resolves to **the platform's
own configured API host**, not auth's Rust service directly — every platform's
Angular `environment.ts` sets `apiServer.host` to that platform's own PHP API base
URL. So `AuthService.acceptInvite()` was never calling auth directly; it was already
calling the **platform's own API** at `{platform}/api/auth/accept-invite` — which
today happens to be `AcceptInviteRoute.php`'s dumb proxy, itself calling auth. The
shared client library already answers "how does a shared method know which platform
API to call" — it always did, via injected `environment.apiServer.host`, the same
mechanism every other `AuthService` method already relies on (login, register, OTP,
etc. all go through `buildApiUrl()` today). See §4 for what changes on the platform
side of that same URL.

---

## 2. Proposed architecture — where invitation data lives

**Invitation data lives in the inviting platform's own database, in the SAME schema
scope as the tenant it invites into** (i.e., the tenant DB for T2/card-model
platforms whose tenant data is already per-tenant-DB, or the platform's tenant-scoped
schema for T3 platforms like medstoreapp — see §4.2 for the T2/T3 distinction and why
this table follows tenant-scoped tables, not the platform's shared `_main` DB).

Rationale for tenant-scoped (not platform-`_main`-scoped): an invitation is
intrinsically tied to one tenant's membership decision — who gets to join *this*
organization, at *what* role, decided by *this* organization's own admin. It has no
cross-tenant meaning. Keeping it in the tenant DB means the gateway's existing
per-tenant connection isolation (the same mechanism that makes cross-tenant queries
structurally impossible today) protects invitation rows for free, with zero new
isolation code. It also means a platform that deletes/exports a tenant's data takes
its pending invitations with it, which is the right lifecycle.

Table shape (proposed — see §5 for the concrete scaffold):

```sql
CREATE TABLE IF NOT EXISTS platform_invitations (
    id               UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    email            VARCHAR(255) NOT NULL,
    role             VARCHAR(50)  NOT NULL,        -- fully platform-defined vocabulary
    invited_by       UUID         NOT NULL,        -- identity_id of the inviter
    token_hash       VARCHAR(64)  NOT NULL UNIQUE, -- sha256 hex of the raw token (never store raw)
    status           VARCHAR(20)  NOT NULL DEFAULT 'pending', -- pending|accepted|expired|revoked
    expires_at       TIMESTAMPTZ  NOT NULL,
    accepted_at      TIMESTAMPTZ,
    accepted_by_identity_id UUID,                  -- filled in on accept, from the auth passport
    created_at       TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at       TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_platform_invitations_email  ON platform_invitations(email);
CREATE INDEX IF NOT EXISTS idx_platform_invitations_status ON platform_invitations(status);
```

`role` has no CHECK constraint at the framework-scaffold level — same reasoning
`PostInviteCustomerRoute.php`'s own docblock already gives for auth's (now dead)
`tenant_memberships.role` column: it's a free-text platform vocabulary
(`owner`/`employee`/`customer`/`vendor`/whatever a given platform needs), and the
framework must not hardcode one platform's role taxonomy into shared scaffolding.

### 2.1 What crosses the platform↔auth boundary, and in which direction

| Direction | What | When |
|---|---|---|
| Platform → auth | `POST /api/internal/create-membership` (server-to-server, `X-Platform-Secret`) | After the platform has validated the invite, confirmed identity, and committed its own membership row |
| Platform → auth | Nothing else. No invite creation call, no accept call, no role-authorization call. | — |
| Auth → platform | Nothing invitation-specific. Only its existing, generic identity responses (OTP verify, OAuth callback, JWKS) — none of which know or care that an invitation exists. | — |
| Browser → auth | Normal OTP/OAuth register or login only — never told about the invitation's existence beyond an email pre-fill (a UI convenience, not a data dependency) | Sub-case (a) in §3 |
| Browser → platform | Everything invitation-specific: fetch invite details, submit accept, resume after auth | Always |

This table is the concrete answer to "exactly what crosses the boundary" — one arrow
in (platform → auth, membership signal only), nothing invitation-shaped crossing in
the other direction at all.

---

## 3. The accept-sequence — the core design question

### 3.1 Why this is now a two-system sequence, not one atomic call

Today, `auth_accept_invite_by_otp` did two things atomically in one Postgres
transaction, because auth owned both the `invitations` row and the `identities`
table: (1) create-identity-if-new by email, (2) create the tenant membership. Once
invitations move to the platform and identity creation stays exclusively in auth
(and, per the constraint, is never gated by invitation state), there is no shared
transaction boundary available anymore — identity creation happens in auth's
database, membership creation happens in the platform's database, and they are two
different services reachable only over HTTP. Atomicity in the old sense is gone by
construction; the design's job is to sequence the two steps so the failure and retry
behavior is still sound.

### 3.2 The two sub-cases

**(a) Invitee has no existing account anywhere.** They must go through auth's normal,
already-ungated OTP/OAuth register flow first — pre-filled with the invited email —
then return to the platform to complete acceptance.

**(b) Invitee already has an account** (possibly already logged in, in this same
browser or a different one). They should be able to accept without re-registering —
ideally in one action if already authenticated, or via a normal login if not.

Both sub-cases must land on the same platform-owned completion step; only the path
*to* holding a valid identity token differs.

### 3.3 Mechanism for "return to complete acceptance" — two options evaluated

**Option A — invite token threaded through auth's login/register flow via
`return_url`/redirect param, browser-driven.**

Flow: platform's accept-invite landing page reads `?token=...` from the URL → checks
(via a **public**, unauthenticated platform endpoint, see §3.5) whether the browser
already holds a valid platform session; if not, redirects to auth's register/login UI
with the invited email pre-filled and a `return_url` back to the platform's
accept-invite page carrying the same invite `token` as a query param → user
authenticates at auth → auth redirects/hands back an identity token → browser lands
back on the platform's accept-invite page, now holding a valid identity token *and*
the original invite token → platform validates both and completes acceptance.

- Security: the invite token is a bearer credential sitting in a URL for the
  duration of the auth hop (visible in browser history, referrer headers, any proxy
  logs on the auth-service leg). It is single-use and short-window already (per §1.1's
  7-day expiry, unchanged), so leakage exposure is bounded to "someone else completes
  this one invite before the intended recipient" — annoying, not catastrophic, and
  no worse than the token's normal exposure via the emailed link itself. No new replay
  risk beyond what emailing a token already carries.
- UX: one redirect hop out to auth, one redirect hop back. Matches the existing OAuth
  callback pattern the framework and every platform already implement
  (`OAuthInitiateRoute`/`OAuthCallbackRoute`), so there is no new UX pattern to design
  or test — it's the same shape users already go through for Google sign-in.
  Pre-filling the email at auth's register/login screen (auth's own UI already
  supports an email query param for this — same mechanism OAuth pre-fill uses) means
  the invitee never has to retype it.
- Implementation cost: low. No new server-side state beyond the invitation row that
  already exists. The `return_url` mechanism is not new — it already exists in the
  framework's OAuth flow (`redirect_uri` parameter on
  `ExternalAuthServiceClient::initiateOAuth()`), so this reuses infrastructure rather
  than inventing a second one.

**Option B — platform holds a "pending acceptance" record, browser polls or re-visits
until a valid identity token shows up.**

Flow: platform records "this invite token is being worked on" in some session-scoped
store; user is sent to auth's register/login in a **new tab or popup** (not a
redirect that leaves the accept-invite page); platform's page polls a
"has this identity now authenticated" endpoint, or auth posts back via
`postMessage` (the same bridge pattern `oauthCallback` already uses for its popup
flow, per `e361645` in the auth repo's own log: "relay callback errors via popup
postMessage bridge").

- Security: avoids putting the invite token in a URL that survives a full-page
  navigation through a third service, marginally reducing the "leaked via
  intermediate proxy log" surface already judged low-risk under Option A.
- UX: a popup/new-tab flow is measurably worse UX on mobile browsers (popup blockers,
  no visible "you're now in a second tab" affordance for less technical invitees —
  exactly the class of first-time users invitations target) and requires a
  polling loop or a `postMessage` bridge to be built and hardened against the same
  cross-origin edge cases the existing OAuth popup bridge had to be hardened against
  (see the auth repo's own OAuth popup bridge fix, `e361645` — evidence that this
  exact mechanism has already cost real debugging time once).
- Implementation cost: meaningfully higher — new client-side polling/messaging logic,
  a new "pending acceptance" server-side record with its own expiry/cleanup, and a
  second state machine (invite status × acceptance-session status) to reason about
  instead of one.

**Recommendation: Option A (redirect + `return_url`, single navigation flow).** It
reuses infrastructure the framework already has (OAuth's `redirect_uri` pattern), has
materially lower implementation cost, and its security delta over Option B is small
and already priced into the existing 7-day-expiry, single-use, hashed-token design
inherited from §1.1. Option B's popup/postMessage complexity is a real, demonstrated
cost center in this codebase (the OAuth bridge needed a dedicated fix) and buys
security margin against a threat (URL-token leakage) that the emailed link itself
already carries at the same order of magnitude.

### 3.4 The completion step — once a valid identity token is held

This is the platform-owned completion handler, run when the browser returns holding
(1) an auth-issued identity token (passport) in `Authorization: Bearer`, and (2) the
original invite `token` from the URL/query param:

1. Look up the invitation row by `sha256(token)` (never store or compare the raw
   token — same as §1.1's inherited mechanic). 404 → `invite_not_found`.
2. Check `status = 'pending'` and `expires_at > now()`. Otherwise →
   `invite_already_used` / `invite_expired` (same taxonomy as §1.1, reused
   deliberately so the already-salvaged frontend error-mapping in
   `accept-invite-logic.ts`, §1.3, needs zero changes).
3. Validate the passport (JWKS, same `TokenExchangeService::validateIdentityToken()`
   every T2 exchange already uses).
4. Confirm the authenticated identity's email matches the invitation's `email`
   (case-insensitively). Mismatch → reject with a distinct error (`invite_email_mismatch`,
   new — not in the inherited taxonomy, but necessary: this exact failure mode did
   not exist when auth owned both sides of the check, because it created the identity
   itself from the invited email; now that identity creation is fully independent and
   ungated, per the constraint, the platform must explicitly guard against "any
   logged-in identity clicking someone else's invite link").
5. Decide role from the invitation row (`platform_invitations.role`) — **never** from
   any auth claim, per the constraint. This is the platform's sole authority.
6. Write the platform's own membership record (tenant-scoped table — whatever shape
   a given platform already uses: aasaanwork's `clients`/`vendors` tables with
   `auth_identity_id`, medstoreapp's own membership model, etc. — this is
   intentionally NOT prescribed by the framework scaffold; see §5).
7. Mark the invitation `status = 'accepted'`, `accepted_at = now()`,
   `accepted_by_identity_id = <passport identity_id>`.
8. Call auth's `POST /api/internal/create-membership`, server-to-server, from the API
   process — never from the browser (this is the literal "notified by api in the
   background not from the browser/client" requirement). See §3.4.1 for the `role`
   field question.
9. Mint the platform's own card locally (T2:
   `TokenExchangeService::exchangeCard($passportClaims, $role, $signingConfig)`,
   exactly the mechanism `ProvisionTenantRoute::mintProvisionCard()` and
   `AasaanworkProvisionTenantRoute::mintProvisionCard()` already use — see §3.4.2
   for why this, not auth's returned token, is what the client should walk away
   with) or return the T3 tenant-less identity token + explicit `tenant_id` for the
   client to navigate with (matching the existing T2/T3 response split documented in
   `AUTH-SPEC.md` §6b, reused here for continuity with the client-side contract the
   killed ngx-client work already assumes — see §1.3).

Steps 1-2 and 5-7 never touch auth. Steps 3-4 are read-only JWKS validation (no auth
DB write, no auth DB read even). Step 8 is the only outbound call to auth, and it
carries no invitation data — only the resulting membership fact.

#### 3.4.1 Should `role` be sent to `create-membership`?

Investigated directly (§1.1): `CreateMembershipRequest.role` is optional, defaults to
`"owner"` if omitted, is stored in auth's own `tenant_memberships.role` column, and is
stamped into the JWT `create-membership` returns directly. `docs/ROLE-COLUMN-REMOVAL-
DESIGN-3204.md` (same-repo, 2026-07-20, read in full) is the most current and
authoritative analysis of exactly this field and independently concludes: platform
RBAC ("job 1") is **already** fully resolved outside auth (via `roles_resolver` in
`ExchangeRoute.php`, never auth's DB) — that migration is done, not proposed. What
remains living in the `role` column is auth's own internal permission gate on its
*own* (now-removed) invite/update-membership functions, an unread token-state
discriminator, and a legacy bootstrap-seed read (medstoreapp's
`config/auth.php` reading `claimsWithTenant['role']` to seed a first local role
assignment) that doc explicitly recommends replacing with "an explicit invite-role
handoff so medstoreapp no longer needs the claim" — which is exactly what this
design's own invitation row already is.

**Recommendation: omit `role` on invite-driven `create-membership` calls** (let it
default to `"owner"` in auth's storage). Reasoning:

- Nothing in this design, or in any platform following it, will ever read `role` back
  from auth's response or from any auth-issued claim for an authorization decision —
  role authority lives 100% in `platform_invitations.role` and (for T2) the
  `roles_resolver` used at exchange time. Sending it would be pure write-without-read.
  invitation-derived role should not enter auth's storage at all, matching the letter
  of "no role storage tied to invites."
- This directly satisfies the #3204 doc's own recommended fix for its "job 4" finding
  — the invite-role handoff that doc calls for already exists as
  `platform_invitations.role`, so no platform following this design needs auth's
  claim, ever, for this purpose.
- It costs nothing: `create-membership`'s schema already treats `role` as optional
  with a harmless default, so omitting it requires no coordination with the
  auth-repo's own team (who own that schema and are mid-flight on a separate,
  larger `role` decomposition per #3204 — this design does not need to wait on or
  block that work).
- Known rough edge, explicitly flagged: auth's own `tenant_memberships.role` column
  will show `"owner"` for every invite-created membership regardless of the real
  granted role, which is cosmetically wrong if anyone reads auth's admin SPA
  membership list expecting an accurate role. Given #3204's own finding that this
  column is already being decomposed away from "authoritative role" duty fleet-wide,
  this is judged acceptable — see §6 for the explicit open-question writeup.

Provision-tenant's existing `role: 'owner'` (unconditional, not invite-derived) is
different and unaffected: it is a structural constant ("the tenant creator is always
owner"), not invitation data, so it does not trip the same constraint.

#### 3.4.2 Why mint a local card instead of using `create-membership`'s returned token

`create-membership` returns a directly-usable `access_token`/`refresh_token` pair.
Both `ProvisionTenantRoute` (framework default) and
`AasaanworkProvisionTenantRoute` (platform override) already establish the pattern of
treating that returned token as a **fallback only** — the platform mints its own card
locally via `TokenExchangeService::exchangeCard()`, signed with the platform's own
key, so `JwtAuthMiddleware`'s fast local-RSA validation path is used instead of a
JWKS round-trip, and so the role stamped on the card is the platform's own decision,
not auth's default. The accept-invite completion step should follow the exact same
pattern for consistency: for T2 platforms, mint locally and only fall back to auth's
returned token if local signing isn't configured (mirrors both existing
`mintProvisionCard()` implementations verbatim — same config keys, same fallback
condition). For T3 platforms (medstoreapp-style, tenant-less identity JWT +
`tenant_id` returned separately, per `AUTH-SPEC.md` §6b's T3 response shape), there is
no card to mint — the existing T3 contract (return the identity token +
explicit `tenant_id`, client navigates to `/portal/tenant/{tenantId}/dashboard`)
already matches this design without change.

### 3.5 Sub-case (a) walkthrough — no existing account

1. Browser hits the emailed link → platform's accept-invite page,
   `?token=<invite_token>`.
2. Page calls a **public** platform endpoint, `GET {prefix}/invitations/{token}`, to
   fetch minimal, non-sensitive display info (inviter/tenant display name, invited
   email, expiry) — needed to render "You've been invited to join Acme Corp as an
   employee" before the user commits to anything. 404/expired/used states render
   immediately using the same error taxonomy as §3.4 step 2.
3. Page checks for an existing valid platform session (T2 card / T3 identity token)
   in this browser tab. None found.
4. Page redirects to auth's register (or login, if the invitee says "I already have
   an account" — a plain link switch, no server involvement) with the invited email
   pre-filled and `return_url` = the platform's own accept-invite page URL with the
   same `?token=` still attached (§3.3 Option A).
5. User completes OTP or OAuth registration at auth — entirely auth's existing,
   already-ungated flow. Auth does not know an invitation exists.
6. Auth redirects back to `return_url`. Browser now holds a fresh identity token and
   the original invite token.
7. Platform's accept-invite page detects the identity token, calls the platform's
   own `POST {prefix}/invitations/{token}/accept` (§3.4's completion step) with the
   token in `Authorization: Bearer`.
8. On success, platform returns a card (T2) or identity token + `tenant_id` (T3); the
   client stores it and navigates into the tenant, same terminal shape the killed
   ngx-client `acceptInvite()` method already expects (§1.3) — `storeAuthResult()` +
   `identity`/`membership` objects in the response body.

### 3.6 Sub-case (b) walkthrough — existing account, possibly already logged in

1. Browser hits the emailed link → platform's accept-invite page, same as step 1-2
   above.
2. Page checks for an existing valid session in this tab.
   - **Already logged in (this tab):** skip straight to calling
     `POST {prefix}/invitations/{token}/accept` with the current token. If the
     authenticated email doesn't match the invitation's email (§3.4 step 4), surface
     `invite_email_mismatch` with a "log out and sign in as the invited email"
     affordance — do not silently attach the invite to the wrong identity.
   - **Not logged in, but has an account:** same redirect as §3.5 step 4, except the
     user clicks "log in" instead of "register" at auth (auth's existing login UI,
     pre-filled email); everything downstream is identical.
3. Same completion step (§3.4) either way — sub-case (b) never needs a second,
   different code path on the platform side. The only branch is "does the browser
   already hold a token," which is a client-side check, not a server-side one.

This symmetry (one completion endpoint, two ways of arriving at it) is deliberate —
it is the same reason `AcceptInviteComponent`'s route registration (§1.3) was
correctly written to be reachable regardless of auth state.

---

## 4. Framework scaffolding mechanism

### 4.1 The decision: `php stone generate invitations`, not a shipped default

Two options, matching the task brief's framing:

**(a) New `php stone generate invitations` command** — copies boilerplate (table
migration, SQL functions, route handlers, a `config/invitations.php` hook file) into
the *consuming platform's own repo*, following the exact precedent already
established by `php stone generate auth:*` (`cli/generate-auth.php` +
`src/Templates/Auth/{provider}/` + `src/Templates/Migrations/{provider}/`, confirmed
by reading both live). The platform then owns and freely edits every generated file.

**(b) Framework-shipped default** — an always-registered
`InviteMemberRoute`/`AcceptInviteRoute`-style pair (i.e., today's exact pattern, just
repointed at platform-owned storage instead of auth), overridable per-platform the
same way `ProvisionTenantRoute` is overridden by `AasaanworkProvisionTenantRoute`
(last-registered-wins in `routes.php`, per that class's own docblock).

**Recommendation: (a), the generate command.** Reasoning, grounded in what was
actually read this session:

- **Every platform's role vocabulary is genuinely different**, and that's the crux of
  the whole constraint ("roles belong in api/platform"). aasaanwork's
  `AdminScope::PRIVILEGED_ROLES = ['owner', 'employee', 'consultant', 'admin',
  'staff']` and its invite routes hardcode `role: 'customer'` / `role: 'vendor'` as
  business-specific concepts (a customer being invited to self-service login is not
  the same shape of decision as an employee being invited to run the business).
  medstoreapp has its own, different role model (per #3204's consumer inventory,
  §4). A single shared default route (`InviteMemberRoute`'s current
  `'role' => 'required|string'`, no platform-specific validation, no per-platform
  "who can invite at what role" business rule) cannot express "a consultant can
  invite a customer or vendor but not an owner" or any of the other real,
  platform-specific rules already encoded in `AdminScope`/`requireConsultant()`. A
  shared default forces every platform to *override* it immediately anyway (exactly
  what happened with `ProvisionTenantRoute` → `AasaanworkProvisionTenantRoute`, whose
  own docblock is a multi-paragraph account of the framework default getting it wrong
  for this platform's needs) — at that point the "shared default" bought nothing
  except an extra indirection layer (`TenantRouteProviderInterface`) to reason about.
- **The existing precedent (`auth:*`) already validates this exact call for a
  structurally similar problem.** Auth providers (email-password, Google, LinkedIn,
  Apple) also differ per platform in exactly the same way invitations do — different
  DB columns, different validation, different UI — and the framework's own answer was
  "generate it into the platform, let them own it," not "ship one generic
  `AuthRoute` and hope every platform's override converges cleanly." Following the
  same pattern for invitations is consistency, not a new philosophy.
- **The two pieces that genuinely are shared and shouldn't be re-invented per
  platform — the accept-sequence orchestration (§3.4's numbered steps) and the
  `create-membership` call shape — are NOT route-level concerns; they belong in a
  framework-level PHP class the generated routes call into**, not duplicated as
  boilerplate text in every platform's generated files. Concretely: ship an
  `InvitationService` class in the framework (`src/Auth/Invitations/`, not
  `src/Templates/`) that implements steps 1-4 and 7-9 of §3.4 generically (token
  lookup by hash, expiry/status check, passport validation, mint-card/return-T3, the
  `create-membership` call itself) parameterized by a small
  `InvitationRepositoryInterface` (find-by-token-hash, mark-accepted — backed by
  whatever table the generated migration created) and a `role`-resolution callback
  the generated route supplies (mirroring the existing `roles_resolver` closure
  pattern from `ExternalAuthConfig`). This gets platforms the orchestration
  correctness (token hashing, expiry, the auth hand-off) for free, without forcing a
  one-size-fits-all route/table/role model on them. This is the same split the
  framework already uses successfully for provisioning:
  `TenantProvisioner`/`ProvisionTenantRoute` is framework-owned orchestration,
  `AasaanworkProvisioner` is the platform's own data layer plugged into it.

### 4.2 Concrete scaffold proposal

**Framework-side (this repo, shipped in the package, not generated):**

- `src/Auth/Invitations/InvitationRepositoryInterface.php` — `findByTokenHash(string
  $hash): ?InvitationRecord`, `markAccepted(string $id, string $identityId): void`,
  plus whatever minimal read shape the "fetch invite details before accepting" public
  endpoint needs.
- `src/Auth/Invitations/InvitationRecord.php` — plain value object (`id`, `email`,
  `role`, `tenantId`, `status`, `expiresAt`, ...).
- `src/Auth/Invitations/InvitationCompletionService.php` — implements §3.4 steps
  1-4 and 7-9: token-hash lookup via the injected repository, expiry/status check
  (raises typed exceptions matching the existing `invite_expired` /
  `invite_already_used` / `invite_not_found` / new `invite_email_mismatch` taxonomy,
  §1.1/§3.4), passport validation via the existing `TokenExchangeService`, the
  `create-membership` call via the existing `ExternalAuthServiceClient` (extended
  with a thin `createMembershipForInvite()` wrapper that omits `role`, §3.4.1), and
  card-minting via the existing `TokenExchangeService::exchangeCard()` for T2 / T3
  passthrough per §3.4.2. Takes the resolved `role` and the tenant-side membership
  write as caller-supplied closures — it never decides role or writes tenant data
  itself, keeping "roles belong in api/platform" intact even inside shared framework
  code.
- `ExternalAuthServiceClient::createMembershipForInvite(array $data, string
  $platformSecret): array` — thin wrapper over the existing `createMembership()`,
  documented as the invite-specific entry point that intentionally never receives a
  `role` key (§3.4.1), so a future reviewer sees the omission is deliberate, not a
  bug. `createMembership()` itself is unchanged (still used, with `role: 'owner'`,
  by provision-tenant).

**Generated into the consuming platform (via `php stone generate invitations`,
templates in `src/Templates/Invitations/`, mirroring
`src/Templates/{Auth,Migrations}/`'s existing layout):**

- `migrations/{next_number}_create_platform_invitations.pgsql` — the table from §2,
  as a template the platform can freely rename/extend (e.g. add a `metadata JSONB`
  column, or a stricter `role` domain).
- `src/postgresql/functions/create_platform_invitation.pgsql`,
  `get_platform_invitation_by_token_hash.pgsql`,
  `mark_platform_invitation_accepted.pgsql` — thin SQL wrappers the generated PHP
  repository calls via `Database::fn()` (framework convention — never handcoded
  `Database::fn()` calls per this repo's own standard; a `php stone generate model`
  pass over these three `.pgsql` files, run automatically by the generator, produces
  the model wrappers).
- `src/App/Routes/Invitations/PostCreateInvitationRoute.php` — protected route,
  `{tenant-prefix}/invitations` — validates `email` + `role`, requires the caller
  supply/import their own authorization check (a commented-out
  `use App\Lib\AdminScope;` + `requireConsultant()`-shaped call, following
  `PostInviteCustomerRoute.php`'s exact pattern, §1.2 — deliberately left for the
  platform to wire to its own role model, never auto-included, since the framework
  cannot know a given platform's privileged-role list).
- `src/App/Routes/Invitations/GetInvitationRoute.php` — public,
  `{prefix}/invitations/{token}` — the display-info fetch from §3.5 step 2.
- `src/App/Routes/Invitations/PostAcceptInvitationRoute.php` — public (token +
  passport carry their own auth, no platform JWT needed to call this), authenticated
  step, `{prefix}/invitations/{token}/accept` — thin route that constructs a
  `PlatformInvitationRepository implements InvitationRepositoryInterface` (generated,
  backed by the three SQL functions above) and calls
  `InvitationCompletionService::complete()`, supplying the platform's own
  tenant-membership-write closure (e.g., for aasaanwork: insert/update the matching
  `customers`/`vendors` row's `auth_identity_id`; for a fresh platform: whatever its
  own membership table is).
- `config/invitations.php` — hook file (mirrors `config/auth.php`'s existing
  pattern) where the platform wires its `role`-decision closure and its
  tenant-membership-write closure into the generated routes, and sets the invite
  link base URL (mirrors `portal_base_url` handling already present in auth's
  removed `invite_member_internal` — that responsibility moves here, since the
  platform now owns invite-link construction entirely, e.g.
  `https://app.medstoreapp.in/#/auth/accept-invite?token=...`, matching the T2/T3
  link-format precedent already documented in `AUTH-SPEC.md` §6a).
- Route registration lines appended to `src/config/routes.php` (same mechanic
  `generate-auth.php` already uses — confirmed reading its help text: "Updates
  src/config/routes.php").

This gives a concrete starting point: table name (`platform_invitations`), migration
numbering (`{next_number}_create_platform_invitations.pgsql`, following this repo's
existing sequential-numbered-migration convention), function names
(`create_platform_invitation`, `get_platform_invitation_by_token_hash`,
`mark_platform_invitation_accepted`), route paths
(`POST {prefix}/invitations`, `GET {prefix}/invitations/{token}`,
`POST {prefix}/invitations/{token}/accept`), and PHP class names (all listed above)
— not just prose.

### 4.3 What happens to the two dead framework routes

`InviteMemberRoute.php` and `AcceptInviteRoute.php` (§1.3) should be **deleted** from
`DefaultTenantRouteProvider`'s registration (and the `invite`/`accept_invite` feature
toggles in `ExternalAuthConfig` defaulted to `false`, or removed entirely) once the
generate-command scaffold above exists and platforms have migrated — not before,
since removing them first with no replacement live would 500 every platform's
existing "invite" button with zero recourse. This ordering is spelled out as an
explicit rollout step in §5. `ExternalAuthServiceClient::inviteMember()` and
`::acceptInvite()` should be marked `@deprecated` in the same change that adds
`createMembershipForInvite()`, and removed once no platform's `composer.lock`
pins a version older than the deprecation.

**Update (2026-07-21, same day):** the two routes were removed in `v7.2.0`
(see `CHANGELOG.md`), and `progalaxyelabs-auth` v4.0.0 removed the endpoints
they proxied to, same day — so this ordering concern is now moot; there is no
platform left calling either.

**A related, easy-to-rediscover trap:** `ngx-stonescriptphp-client` (the
shared Angular client) had an in-progress `AcceptInviteComponent` drafted
earlier the SAME day, built against the old central
`POST {prefix}/accept-invite` contract this section removes — a form +
error-state UI shell backed by `AuthService.acceptInvite()`. It was never
merged and has since been discarded (not preserved on a branch — the code
targeted a dead contract and wasn't reusable as-is; this note is the
durable record, not the diff). **Don't re-add `AuthService.acceptInvite()`
to resurrect it** — a shared component posting to one fixed central path
cannot work under this doc's platform-owned model at all (the accept-invite
URL is now platform-specific,
e.g. aasaanwork's `POST /portal/tenant/{tenantId}/invitations/{token}/accept`
per §4.2). If a shared "accept invite" UI helper is ever built, it needs to
accept a platform-supplied URL, not assume one.

---

## 5. Migration / rollout plan (framework-focused; full per-platform plan is out of
scope for this doc)

This is real, live-breaking work for every platform still calling auth's
soon-to-be-removed invite endpoints, in this order:

1. **This repo:** ship `InvitationCompletionService` +
   `InvitationRepositoryInterface` + `createMembershipForInvite()` +
   `php stone generate invitations` + `src/Templates/Invitations/*` (§4.2). Keep
   `InviteMemberRoute`/`AcceptInviteRoute` registered as-is for now — they still work
   today (auth's endpoints aren't gone yet) and removing them before every platform
   has an alternative would be the breaking move, not a fix.
2. **auth removal ships** (parallel task, out of this doc's control) — the moment it
   does, `InviteMemberRoute`/`AcceptInviteRoute` start failing for every platform
   still on framework defaults (`invite`/`accept_invite` toggles true, no override).
   This is the actual breaking event, not step 1.
3. **Per platform** (aasaanwork first, since its two forwarder routes were read
   directly this session and are the clearest concrete example; medstoreapp per
   #3204's consumer inventory is the platform with the most riding on `role`
   semantics and should follow with extra care re: §3.4.1's bootstrap-seed note):
   - Run `php stone generate invitations`.
   - Replace `PostInviteCustomerRoute.php`/`PostInviteVendorRoute.php`'s curl-to-auth
     body with a call into the generated `PostCreateInvitationRoute` shape (or point
     their existing `AdminScope`-gated routes at the new `InvitationCompletionService`
     directly — the authorization trait doesn't change, only the storage target).
   - Wire `config/invitations.php`'s role-decision and membership-write closures to
     the platform's existing role model (aasaanwork: `customer`/`vendor` role
     strings + `customers`/`vendors.auth_identity_id`, already documented in
     `DESIGN-multi-tenant-identity-onboarding.md` §4.2).
   - Repoint the platform's `ExternalAuthRoutes::register()` options to disable the
     framework's default `invite`/`accept_invite` toggles (or leave the generated
     routes to shadow them per last-registered-wins, matching
     `AasaanworkProvisionTenantRoute`'s existing override mechanic).
   - Run the invite → accept round-trip against a test tenant/identity before calling
     the migration done for that platform (Mailpit for the invite email, per §1.1's
     inherited test-domain routing).
4. **Once every platform has migrated:** flip `invite`/`accept_invite` defaults to
   `false` in `ExternalAuthConfig`, then delete `InviteMemberRoute.php`/
   `AcceptInviteRoute.php` and the two now-dead `ExternalAuthServiceClient` methods.
5. **Frontend (`ngx-stonescriptphp-client`):** the killed background task's
   `AcceptInviteComponent` shell, routing decision, and error-taxonomy mapping
   (§1.3) need no structural change — `buildApiUrl()` already targets the right host.
   `AuthService.acceptInvite()`'s request/response shape should be re-verified
   against whichever concrete route path a given platform's generated
   `PostAcceptInvitationRoute` ends up at (§4.2 proposes
   `{prefix}/invitations/{token}/accept`, not `/api/auth/accept-invite` — a path
   change from what the killed work assumed, since accept-invite is no longer a
   framework-default proxy path but a generated, platform-owned one). The already-
   noted "KNOWN GAP" comment in that diff (display_name silently dropped by the old
   proxy) becomes moot — the new generated route reads `display_name` itself, never
   forwards blindly.

---

## 6. Open questions / explicitly deferred items

1. **`role` on `create-membership` for invite-driven memberships (§3.4.1).**
   Recommended: omit it, accept auth's `"owner"` default as a known cosmetic
   inaccuracy in auth's own storage. Alternative not taken: send the real role as
   non-authoritative descriptive metadata (a "courtesy copy," same spirit as
   `tenant_name`). Flagging explicitly because the task brief's phrasing leaned
   toward omission and the evidence (§3204) supports it, but the auth-repo's own
   `role`-column decomposition (#3204) is still mid-flight and owned by a different
   team — if that work later adds a role-agnostic descriptive field to
   `create-membership` (their call, not this doc's), platforms following this design
   should adopt it as a strict improvement, not treated as required now.

2. **Pending-invitations UI (`DESIGN-multi-tenant-identity-onboarding.md` §2.3).**
   That doc's proposed query (`SELECT ... FROM tenant_memberships WHERE identity_id
   = ... AND status = 'invited'`) assumed auth held pending-invitation rows, keyed by
   identity, across ALL the platforms an identity might be invited on — a genuinely
   convenient identity-scoped, cross-platform view auth's central position made
   possible for free. Under this design, `platform_invitations` is per-platform,
   per-tenant-DB, keyed by **email**, not identity_id (since at invite-creation time
   the invitee may not have an identity yet — §3.2 sub-case (a)). This means: (a) a
   "which invitations am I holding, across every platform I've ever touched" view is
   no longer a single query anywhere — it would require fanning out to every
   platform's own API, which no component currently does or is positioned to do
   cheaply; (b) even a single-platform "invitations for me" view requires the
   platform to query `platform_invitations WHERE email = <my email> AND status =
   'pending'` **per tenant DB the identity might be invited into**, which is only a
   single-DB query if the invitee is being invited into one tenant already known from
   context (e.g. a tenant-scoped "pending invites for this org" admin view — cheap,
   normal), but is NOT a single query for "show me every pending invite across every
   organization on this platform that hasn't accepted yet" without either a
   platform-side index table outside any one tenant DB, or an N-tenant fan-out.
   **No confident recommendation given the investigation done here** — this needs a
   follow-up design decision by whoever owns the pending-invitations UI requirement,
   with two honest options: (i) accept "no cross-tenant pending-invites view" as a
   product regression from the original ask and scope the UI down to "you got an
   invite, here's the direct link" (matches what an emailed link already delivers,
   costs nothing extra), or (ii) introduce a lightweight platform-`_main`-scoped
   (not tenant-scoped, not auth-scoped) index of `(email, tenant_id, status)` rows
   purely for this lookup, accepting that this is now a second place invitation
   status is denormalized into and must be kept in sync with the tenant-scoped
   source of truth. Best-guess default if forced to pick today: (i), since it needs
   zero new infrastructure and the emailed link already serves the same job.

3. **`invite_email_mismatch` as a new error code (§3.4 step 4).** This failure mode
   didn't exist under auth's old atomic accept, because auth created the identity
   itself from the invited email, making a mismatch structurally impossible. It's a
   direct emergent consequence of decoupling identity creation from invitation
   acceptance. Recommending it be added to the shared taxonomy now (§4.2's generated
   `InvitationCompletionService` should raise it), but flagging that the killed
   ngx-client's `accept-invite-logic.ts` (§1.3) does not currently know this code —
   whoever picks up the frontend salvage work (§5 step 5) needs to add it to
   `KNOWN_CODES` and `describeAcceptInviteError()`, a small, explicit addition, not
   a redesign.

4. **T1 platforms (single-tenant, no membership concept at all).** This entire
   design assumes a platform has a multi-tenant membership model worth inviting
   people into. Per `AUTH-SPEC.md`'s route applicability matrix (§9, read this
   session), `provision-tenant`/`select-tenant`/`invite`/`accept-invite` are already
   T2/T3-only, never T1 — so `php stone generate invitations` should simply not be
   relevant to/run on a T1 platform. Not treating this as a gap, just confirming the
   scaffold's applicability boundary matches the existing tier model rather than
   silently assuming every platform wants it.

5. **Rate-limiting / abuse on the new public `GET {prefix}/invitations/{token}` and
   `POST {prefix}/invitations/{token}/accept` endpoints.** Auth's original functions
   presumably benefited from whatever rate-limiting existed centrally for
   `/api/memberships/*`. This design doesn't investigate what (if any) rate-limiting
   the framework's `RateLimit` middleware (referenced in `HLD.md`'s architecture
   diagram, §1) already applies to public routes by default, or whether the
   generated invitation routes need an explicit opt-in. Flagging as unverified
   rather than asserting either way — worth a direct check at implementation time
   before shipping the generator.
