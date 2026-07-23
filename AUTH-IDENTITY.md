# AUTH-IDENTITY.md — Identity, Membership & Role-Ownership Contract (task #3204)

**Status:** SPEC. Auth-side (`progalaxyelabs-auth`) core removal is already implemented
as uncommitted local changes (verified by reading the working-tree diff directly,
2026-07-23) — this document formalizes that work as the contract, and adds the one
piece not yet built (`is_tenant_owner` + the membership-status endpoint). Framework-side
(`progalaxyelabs/stonescriptphp`, this repo, plus `ngx-stonescriptphp-client` /
`stonescriptphp-client-core`) is NOT yet implemented — this spec is what that
implementation is built against.
**Date:** 2026-07-23
**Author:** app-dev (spec authored after a multi-turn investigation; see task #3204 and
`docs/ROLE-COLUMN-REMOVAL-DESIGN-3204.md` in `progalaxyelabs-auth` for the earlier,
broader design doc this supersedes on all points where they disagree — this document
is authoritative).
**Scope:** The contract between `progalaxyelabs-auth` (identity/membership service) and
`progalaxyelabs/stonescriptphp` (this repo, including the TypeScript clients
`ngx-stonescriptphp-client` and `stonescriptphp-client-core`). `aasaanwork-platform` is
cross-referenced as the first platform expected to adopt it, but is out of scope for
this document's own implementation — it happens in a later, separate integration step.

**Division of labor (so both implementers build against the same target without
drifting):**
- `progalaxyelabs-auth` (Rust + SQL) — implemented by the auth-side team/agent.
- `progalaxyelabs/stonescriptphp` (PHP framework) + its TS clients — implemented by
  the framework-side agent (app-dev), against this same document.
- Neither side should improvise a field name, endpoint shape, or business rule not in
  this document. If something is missing, it gets added here first, not invented
  independently on one side.

---

## 0. The non-negotiable constraint (verbatim, restated so it can't be forgotten)

> "roles should not be part of progalaxyelabs-auth. they belong in the platform."

Concretely, for every decision below:

- **`progalaxyelabs-auth` owns zero role/RBAC data.** No `role` column, no `roles`
  array, no role-editing endpoint, no role-based authorization decision made inside
  auth about what an identity may *do* within a tenant.
- **Auth's remaining job, precisely:** identity (who you are), and a minimal
  membership ledger (which tenants, on which platforms, you currently belong to, and
  whether that membership is active) — nothing about what you're allowed to do there.
- **The one narrow exception, and why it is not a role:** `is_tenant_owner` (§3). It
  exists solely to stop a tenant from ending up with zero owners — a referential-
  integrity invariant, not an authorization decision. It is never returned to a
  platform, never feeds a card, never gates what an identity can do. See §3.4 for why
  this is different in kind from the `role` column that was removed.

If any change on either side routes an application-role decision (cashier vs manager,
consultant vs client, admin vs staff — anything platform-specific) through auth, that
change is wrong regardless of how it's justified, and should be treated as a bug in
this spec's implementation, not a precedent.

---

## 1. Current-state summary (verified against the actual code, not assumed)

### 1.1 Already implemented in `progalaxyelabs-auth` (uncommitted, local, 2026-07-23)

Read directly from the working-tree diff. Not yet committed, not yet deployed.

- `tenant_memberships.role` (VARCHAR) and `.roles` (VARCHAR[]) — **dropped**
  (migration `036_drop_role_columns_from_memberships.pgsql`, via the gateway's
  `_stonescriptdb_gateway_drop_column` exemption; declarative table file
  `tables/004_create_tenant_memberships.pgsql` updated in the same change so the
  post-migration schema verifier matches).
- `auth_update_membership.pgsql`, `auth_switch_role.pgsql`,
  `auth_set_membership_roles.pgsql` — **deleted** (SQL functions and their Rust
  handlers/routes).
- `PUT /api/memberships/:id` (role/status edit) — **removed**. This was the actual
  security hole that made the whole `role` column dangerous: it authenticated the
  caller via a bare passport Bearer token (no `X-Platform-Secret`) and made its
  entire authorization decision (`role IN ('admin','owner')`) in SQL. Any identity
  could curl it directly, bypassing whatever authorization the calling platform's own
  API would otherwise have enforced. Removing it — not renaming its gate — closes
  that hole.
- `POST /api/internal/set-roles` — **removed** (nothing left for it to write to).
- `POST /api/internal/create-membership` — `role`/`roles` request and response
  fields **removed**. `auth_register_account.pgsql` lost its `p_role`/`p_roles`
  params entirely.
- Passport/card JWT `role` claim — **field name kept** (not renamed to something like
  `stage`; see §4.2 for why that's an acceptable, low-priority open item, not a
  blocker), but its **value is now always the fixed, neutral sentinel
  `"authenticated"`** at every mint site (`auth.rs`, `otp.rs`, `identity.rs`,
  `internal.rs`) — it never again carries a real application-role-shaped value.
- `auth_get_memberships.pgsql` / `auth_list_memberships.pgsql` — no longer select or
  return `role`/`roles`.

### 1.2 Confirmed unaffected (do not touch, do not "fix")

Verified live in the framework's own code, in this repo, before this spec was
written — these already implement "roles belong to the platform" correctly and
predate this task:

- `ExchangeRoute::process()` (`src/Auth/ExternalAuth/Routes/ExchangeRoute.php`) — the
  card's `role_id` comes exclusively from the platform's own `roles_resolver`
  closure. It never reads a `role` claim off the passport. A missing resolver is a
  hard `501`, not a silent guess.
- `TokenExchangeService::exchangeCard()` — never copies anything role-shaped from
  passport claims onto the card.
- `ProfileRoute::process()` (`GET /api/auth/me`) — same pattern, `roles_resolver`
  only.
- `ngx-stonescriptphp-client`'s `session-context.model.ts` / `auth.service.ts` —
  `active_role` / `available_roles` / `switchRole()` are entirely response-body-
  driven from the platform's own exchange endpoint, never from auth or decoded JWT
  claims.

### 1.3 Real, still-open gaps this spec closes or explicitly declines to close

| Gap | Disposition |
|---|---|
| No way to suspend/reactivate a single membership without deleting the whole identity | **Closed by this spec** — §3, new `PUT /api/internal/membership-status`. |
| Nothing stops a tenant ending up with zero owners | **Partially closed** — the new endpoint refuses to suspend the owner's row (§3.3). `auth_delete_identity` / `auth_request_account_deletion` remain unguarded (§7 — explicit, not silent). |
| `deleted_tenant_memberships` (archive table) still has `role`/`roles` columns | Not touched by this spec — historical snapshot data, auth-side's call whether to backfill-clean it. Noted, not blocking. |
| Framework's `ExternalAuthServiceClient::updateMembership()` / `inviteMember()` still reference removed auth endpoints | **Closed by this spec** — §5, both removed from the framework client. |
| Client-side `User.role` / `TenantSelectedEvent.role` still expect a real role value from auth | **Closed by this spec** — §6. |

---

## 2. Auth-side contract (`progalaxyelabs-auth`) — endpoint by endpoint

This section is the target state. §1.1's items are already done; the new work is
`is_tenant_owner` (§2's `create-membership` row) and the new status endpoint (§3).

| Endpoint | Request | Response | Auth |
|---|---|---|---|
| `GET /api/auth/memberships` | `?platform_code=` | Each row: `id, platform_code, tenant_id, tenant_slug, tenant_name, status, joined_at, local_user_id`. **No `role`, no `roles`, no `is_tenant_owner`.** | Bearer passport |
| `GET /admin/memberships` | pagination + optional `platform_code`/`status` filters | Same shape as above + `identity_email`, `identity_display_name`. `is_tenant_owner` MAY be added here (admin-only support visibility) — optional, not required. **Never add `role`/`roles` back.** | Auth's own admin session |
| `POST /api/internal/create-membership` | `identity_id, tenant_id, platform_code, tenant_name?, tenant_slug?, tenant_db_schema, email?, idempotency_key?, is_tenant_owner?` (bool, default `false`) | `membership_id, tenant_id, tenant_slug?, tenant_db_schema, is_new_tenant, access_token, refresh_token, ...`. **No `role`/`roles`.** | `X-Platform-Secret` |
| `PUT /api/internal/membership-status` **(new — §3)** | `membership_id, status: "active"\|"suspended"` | `{id, status, updated_at}` or `{error: "cannot_suspend_tenant_owner"\|"membership_not_found"\|"invalid_status"}` | `X-Platform-Secret` |
| `DELETE /api/internal/delete-identity` | `identity_id` | unchanged | `X-Platform-Secret` |
| ~~`PUT /api/memberships/:id`~~ | — | — | **Removed. Do not reintroduce.** |
| ~~`POST /api/auth/switch-role`~~ | — | — | **Removed.** Switching active role is a platform-side operation (`POST {prefix}/exchange` with a `role_id` body param) — already implemented, unaffected by this task. |
| ~~`POST /api/internal/set-roles`~~ | — | — | **Removed.** |

---

## 3. New: `is_tenant_owner` + membership-status endpoint

### 3.1 Schema

```sql
-- migration NNN_add_is_tenant_owner_to_memberships.pgsql (non-destructive ADD COLUMN,
-- no gateway exemption needed)
ALTER TABLE tenant_memberships
    ADD COLUMN IF NOT EXISTS is_tenant_owner BOOLEAN NOT NULL DEFAULT false;
```
Mirror the same column into the declarative `tables/004_create_tenant_memberships.pgsql`
definition (so the post-migration verifier matches), and into
`deleted_tenant_memberships` (032) + `auth_request_account_deletion`'s archive INSERT,
for snapshot completeness.

### 3.2 Set once, at creation, never editable afterward

`is_tenant_owner` is written **only** by `auth_register_account` (via
`create-membership`'s new optional `is_tenant_owner` field, default `false`). There is
**no endpoint, anywhere, that changes it after creation** — no ownership-transfer
mechanism exists (§7, deliberate, not an oversight).

### 3.3 The new endpoint

```sql
CREATE OR REPLACE FUNCTION auth_set_membership_status(
    p_membership_id UUID,
    p_status TEXT
)
RETURNS JSON AS $$
DECLARE
    v_is_owner BOOLEAN;
    v_updated_at TIMESTAMPTZ;
BEGIN
    IF p_status NOT IN ('active', 'suspended') THEN
        RETURN json_build_object('error', 'invalid_status');
    END IF;

    SELECT is_tenant_owner INTO v_is_owner
    FROM tenant_memberships WHERE id = p_membership_id;

    IF NOT FOUND THEN
        RETURN json_build_object('error', 'membership_not_found');
    END IF;

    IF p_status = 'suspended' AND v_is_owner THEN
        RETURN json_build_object('error', 'cannot_suspend_tenant_owner');
    END IF;

    UPDATE tenant_memberships
    SET status = p_status, updated_at = NOW()
    WHERE id = p_membership_id
    RETURNING updated_at INTO v_updated_at;

    RETURN json_build_object('id', p_membership_id, 'status', p_status, 'updated_at', v_updated_at);
END;
$$ LANGUAGE plpgsql;
```

`PUT /api/internal/membership-status`, `X-Platform-Secret` gated, same pattern as
`create-membership` (header check, then the gateway call). Rust request/response
structs mirror the JSON shapes above 1:1 — no extra fields.

### 3.4 Why this is not a reintroduction of `role`

This was explicitly debated (see the earlier, more elaborate `is_tenant_admin`
proposal, rejected — §7.3). The distinguishing test: **does the answer depend on who
is asking?** `role` did (an admin vs a member gets different answers to "can I do
X"). `is_tenant_owner`'s only use is "does the tenant have at least one active owner"
— the same answer regardless of caller, checked with zero knowledge of who initiated
the request. The status endpoint takes no `acting_identity_id` parameter and never
will; if a future need requires knowing who's asking, that is a NEW, separate,
explicitly-approved design, not a silent extension of this one.

### 3.5 Explicit, deliberate limitation

The owner's row can **never** be suspended through this endpoint — not "unless
another owner exists," an unconditional block, regardless of caller. This is a known
simplification (see §7.2): supporting "the owner may suspend only their own row" would
require an `acting_identity_id` parameter and a lookup of the caller's own
`is_tenant_owner`, which is exactly the RBAC-shaped mechanism this spec avoids
reintroducing. If a tenant owner genuinely needs to step back, that is a bigger
operation (ownership transfer / tenant deactivation) than a status toggle, and needs
its own future design — not a workaround bolted onto this endpoint.

---

## 4. Identity (passport) JWT claim contract

| Claim | Contract |
|---|---|
| `role` | **Never a real application-role value.** Always the fixed sentinel `"authenticated"` for any live, identity-authenticated token. Do not read this claim programmatically anywhere in the framework or a platform — it carries no information. (Historically this field also carried pre-registration state sentinels like `unregistered`/`oauth_pending`; those write sites are unaffected by this task and continue to exist for their own reasons — the only contract change here is that a *fully authenticated* identity's token now always has the one fixed value, never a role name.) |
| `role_id` | **Card-only.** Present exclusively on platform cards minted by `TokenExchangeService::exchangeCard()`, sourced solely from the platform's own `roles_resolver`. Never present on a passport. This is unchanged by this task — documented here so both implementers can confirm it against their own side. |
| `token_type` / `purpose` | Unaffected by this task. |

**Open, non-blocking item:** the claim key is still literally named `role` even
though it never carries role data anymore. A rename to something like `stage` would
be clearer, but nothing reads it programmatically today, so it is not required for
this spec to be considered complete. Either side may propose the rename separately;
it is not bundled into #3204.

---

## 5. Framework-side (`progalaxyelabs/stonescriptphp`, this repo) changes

| File | Change |
|---|---|
| `src/Auth/ExternalAuth/ExternalAuthServiceClient.php` | **Remove** `inviteMember()` (calls a route auth no longer has — dead code, not merely deprecated). **Remove** `updateMembership()` (calls `PUT /api/memberships/:id`, which no longer exists). **Add** `is_tenant_owner` support to `createMembership()`'s `$data` passthrough (no code change needed beyond documenting the key — it's a plain array passthrough already; just the docblock needs updating to describe the new field and confirm `createMembershipForInvite()` continues to strip it, matching its existing `role`/`roles` stripping). **Add** `setMembershipStatus(string $membershipId, string $status, string $platformSecret): array` wrapping `PUT /api/internal/membership-status`. |
| `src/Auth/AuthenticatedUser.php` | **Remove** the `$payload['role']` fallback branch inside `fromPayload()`'s `user_role` resolution chain (currently `role_id ?? user_role ?? payload['role'] ?? roles[0] ?? null`). A raw passport's `role` claim is now always the neutral sentinel (§4) and must never be treated as an app role, even accidentally via a fallback chain. |
| `src/Auth/Middleware/RequireRoleMiddleware.php` | **Fix** (adjacent defect, unrelated in origin to #3204 but touched while working in this area): currently checks `$claims['role']`; every card actually stamps `role_id`. Update to check `role_id` (with `user_role` as the documented legacy alias), matching `AuthenticatedUser`'s own contract. Every current user of this middleware is silently 403ing today — this is a real bug fix, not a behavior change platforms need to opt into. |
| `src/Auth/ExternalAuth/Routes/ExchangeRoute.php`, `src/Auth/TokenExchangeService.php`, `src/Auth/ExternalAuth/Routes/ProfileRoute.php` | **No change** — already compliant (§1.2). |
| `DESIGN-invitation-system.md` (this repo) | Add a one-line cross-reference to this document, since `create-membership`'s contract (role fields) changed after that doc was written. |

---

## 6. Client-side (`ngx-stonescriptphp-client` / `stonescriptphp-client-core`) changes

**Correction from an earlier draft of this spec:** `User.role` is NOT removed —
it's used by `StoneScriptPHPAuth` (Model A, the framework's own self-contained
JWT auth), which is entirely unrelated to progalaxyelabs-auth and legitimately
populates it from its own backend. Only `ProgalaxyElabsAuth`'s behavior
changes. `TenantMembership.role`, by contrast, IS fully removed — it's
specific to the `GET /api/auth/memberships` wire shape, which no longer has a
role at all, for either plugin.

| File | Change | Status |
|---|---|---|
| `stonescriptphp-client-core/src/auth-plugin.ts` | `User.role?: string` — **kept**, docblock updated to clarify it's backend-specific and `ProgalaxyElabsAuth` never populates it. `TenantMembership.role: string` (required) — **removed** entirely (no backend returns it anymore). | Done |
| `stonescriptphp-client-core/src/auth/plugins/progalaxyelabs-auth.plugin.ts` | `toUser()` — dropped its `role?` param and the `if (role) user.role = role` line; both call sites updated to `this.toUser(data.identity)`. `toMembership()` — dropped the required-field throw on `raw.role` and the `role: raw.role` in the returned object. | Done |
| `ngx-stonescriptphp-client/src/lib/ui/lib/auth-types.ts` | **Removed** `role: string` from `TenantSelectedEvent`. | Done |
| `ngx-stonescriptphp-client/src/lib/ui/lib/components/tenant-select/tenant-select.component.ts` | Removed `role: membership.role` from the emitted `tenantSelected` event. | Done |
| `ngx-stonescriptphp-client/src/lib/ui/lib/components/tenant-login/tenant-login.component.ts` | Removed `role: m.role` from `toEvent()`; updated its docblock's stale field enumeration. | Done |
| `stonescriptphp-e2e-test-helpers/src/auth/types.ts` | Checked — its own `User` interface never had a `role` field. No change needed. | Verified, no-op |
| `ngx-stonescriptphp-client/src/auth.service.ts` | Two docblocks referencing `membership.role` updated for accuracy (`storeAuthResult()`, `setActiveRole()`) — no behavior change, both already read whatever the plugin's `result.user`/`result.membership` actually contains. | Done |
| `ngx-stonescriptphp-client/src/session-context.model.ts`, `auth.service.ts` (`RoleInfo`, `active_role`, `available_roles`, `switchRole()`) | **No change** — already compliant (§1.2). | N/A |

**Tests updated/added to cover this** (all green): `stonescriptphp-client-core/tests/handle-login-response.test.mjs` (removed the role-required case, added a role-absent regression test), `get-tenant-memberships-auth-rejection.test.mjs` (fixture cleanup), `ngx-stonescriptphp-client/tests/tenant-login-toevent-no-fallback.test.ts` (mirrors the component fix).

---

## 7. Explicitly out of scope / known gaps (named, not hidden)

1. **`auth_delete_identity` (hard delete) and `auth_request_account_deletion` (soft
   delete) can still remove a tenant's only owner-membership with no protection.**
   Both predate this task and remain unguarded after it. `auth_delete_identity` is
   already documented "use with caution, testing-only"; account self-deletion has a
   30-day undo window. Treated as a separate follow-up, not bundled here.
2. **No ownership-transfer mechanism.** `is_tenant_owner` is permanent once set. If a
   real product need for "the owner leaves, someone else becomes owner" appears,
   that's new design work, not an extension of this spec.
3. **`is_tenant_admin` (rejected).** An earlier version of this design proposed a
   second flag letting "admins" suspend/reactivate any non-owner member, with the
   owner suspendable only by themselves. Rejected because implementing "who may act
   on whom" requires threading an `acting_identity_id` through the endpoint and
   having auth re-derive a permission decision from it — i.e., auth doing
   authorization again, the exact thing #3204 removes. That decision belongs entirely
   to each platform's own (already-necessary) RBAC check, made *before* it calls
   auth's secret-gated endpoint. See §3.4's "does the answer depend on who is asking"
   test — this is why it failed it and `is_tenant_owner` alone passes.
4. **`/api/internal/*` has no network-layer isolation** — Traefik on
   `auth.progalaxyelabs.com` routes by `Host()` only, no `PathPrefix` filter, so these
   routes are reachable from the public internet; `X-Platform-Secret` is the entire
   boundary. Pre-existing (true before this task too), not introduced by it — noted
   because more traffic now flows through this boundary (the status endpoint is new
   traffic on it) than before.

---

## 8. Acceptance checklist

**Auth side (`progalaxyelabs-auth`):**
- [ ] §1.1 items committed (currently uncommitted working-tree changes).
- [ ] `is_tenant_owner` column added (§3.1), never exposed via `GET /api/auth/memberships`.
- [ ] `PUT /api/internal/membership-status` implemented exactly per §3.3 (no extra fields, no acting-identity parameter).
- [ ] `cargo test` green.
- [ ] Tagged per this repo's existing tag convention.

**Framework side (`progalaxyelabs/stonescriptphp` + TS clients):**
- [x] §5 + §6 changes made (§6 expanded during implementation — see that section's correction note re: `TenantMembership.role` vs `User.role`).
- [x] PHPUnit green (672 tests, 0 failures) — new/updated coverage for every touched file (`ExternalAuthServiceClient`, `AuthenticatedUser`, `RequireRoleMiddleware`).
- [x] TS package test suites green (`ngx-stonescriptphp-client`, `stonescriptphp-client-core`). `stonescriptphp-e2e-test-helpers` checked — no change needed, its `User` type never had `role`.
- [x] Version bumped + tagged: `StoneScriptPHP` composer.json → `7.3.0` (`v7.3.0`), `stonescriptphp-client-core` → `3.3.0` (`v3.3.0`), `ngx-stonescriptphp-client` → `6.5.0` (`v6.5.0`, dependency bumped to `stonescriptphp-client-core@^3.3.0`). Not yet published — publish is a separate, explicit step (owner-only).

**Integration (later, separate step — `aasaanwork-platform`):**
- [ ] Framework version bumped in `composer.json` / TS `package.json`s.
- [ ] aasaanwork's `config/auth.php` `roles_resolver` reverted off auth's (now-removed)
  membership `role` field back onto its own local tenant-DB role source — this is the
  drift flagged earlier in this same investigation (aasaanwork was rewired *onto*
  auth's role column on 2026-07-20, the opposite direction from this task).
- [ ] Live-tested end-to-end (OTP + Mailpit recipe, per `.claude/context.md` §2).

---

## 9. Versioning & rollout order

1. Auth-side commits + tags (§8, auth checklist) — can happen independently, since
   nothing in §1.1 changes any contract a not-yet-updated framework depends on (no
   platform reads `role`/`roles` from `GET /api/auth/memberships` today without
   already being broken by the working-tree changes in `progalaxyelabs-auth` — that's
   a pre-existing, separately-tracked risk, not new here).
2. Framework-side implementation (this repo + TS clients) — built directly against
   this document, independent of auth's implementation timeline.
3. Framework version published (composer/npm), per each package's normal release
   process.
4. `aasaanwork-platform` (or any platform) updates to the new framework version and
   does its own integration (§8's integration checklist) — a separate, later step,
   explicitly not part of this document's scope.
