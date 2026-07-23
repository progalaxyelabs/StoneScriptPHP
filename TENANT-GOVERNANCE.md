# TENANT-GOVERNANCE.md — Platform-Owned Tenant Membership & Governance Model

**Status:** SPEC. No implementation yet — this document is the contract the framework
feature is built against. Written after AUTH-IDENTITY.md shipped the principle "roles
belong to the platform, not auth" but left a gap: nothing replaced the removed data on
the platform side. This spec is that replacement, generalized as a reusable framework
primitive instead of each platform hand-rolling its own.
**Date:** 2026-07-23
**Author:** Framework maintainer.
**Scope:** `progalaxyelabs/stonescriptphp` (this repo) — a new generator + SQL/PHP
primitive every platform can adopt. Does NOT change the auth service, the passport/card
JWT pipeline, or `AUTH-IDENTITY.md`'s existing contract — this is additive, platform-side
only.

---

## 0. Relationship to auth — what stays, what moves, and why

Two auth-side pieces from AUTH-IDENTITY.md are **kept exactly as specced, unrelated to
this document**:

- **`tenant_memberships.is_tenant_owner`** (auth's own, narrow flag, AUTH-IDENTITY.md §3) —
  stays. Confirmed with the product owner: it's for auth's own internal bookkeeping/
  analytics, never read by platforms, not a role, not touched by this spec.
- **`PUT /api/internal/membership-status`** (active/suspended, AUTH-IDENTITY.md §3.3) —
  stays. Its purpose is a coarse, fast, at-login gate: when an identity tries to log
  into platform X, auth already knows whether that identity's membership on platform X
  has been switched off, and can refuse before a passport is even issued — before the
  platform's own request pipeline runs at all. This is a *platform-code-wide* switch,
  not the finer-grained, per-tenant governance state this spec introduces.

**What was actually missing:** a durable, platform-owned, cross-tenant-queryable record
of "who belongs to which tenant, with what standing." AUTH-IDENTITY.md correctly took
role data OUT of auth (auth must own zero role/RBAC data), but nothing replaced it on
the platform side. Auditing the fleet found two divergent, incomplete answers already
in production — one platform has no local membership ledger at all (entirely dependent
on auth's now-removed field), another has a flat `role VARCHAR` string with no
governance semantics (single role name, no creator/owner/admin distinction, no
multi-owner support). Neither is what's needed. This spec defines the real primitive,
once, in the framework, so no platform hand-rolls it again.

---

## 1. Governance model

### 1.1 Governance tiers (per identity × tenant — one row per membership)

A ladder — each tier is a superset of the privileges below it:

| Flag | Grants | Mutability |
|---|---|---|
| `is_tenant_creator` | Nothing by itself — a permanent provenance marker only, not a permission. | Set exactly once, at tenant creation. Never editable afterward, by anyone, including the platform's own admin tooling. The membership row carrying it can never be hard-deleted (see §3). |
| `is_tenant_owner` | Close/delete the tenant. Promote any admin to owner. Demote any (other) owner back to admin. | Transferable, many-to-many over time. Multiple simultaneous owners are allowed (e.g. business partners) — this is NOT a single-holder flag. |
| `is_tenant_admin` | Add/edit/remove admin and non-admin members. Invite new members. Promote a member to admin. Demote an admin to member. | Transferable. **Any admin may demote any other admin** — this is a peer action, not owner-gated. A demoted admin's only recourse is asking an owner to re-promote them; the system itself does not protect one admin from another. |
| *(baseline — not a stored flag)* | Login/logout only. No management surface. | A row with `is_tenant_owner = false AND is_tenant_admin = false` is a plain member by definition. |

**Promotion path is strictly one tier at a time, bottom-up:** member → admin (by an
admin or owner) → owner (by an owner). No function skips a tier — a plain member
cannot be promoted directly to owner.

### 1.2 Hard invariants

- **A tenant must always have ≥1 active owner** ("someone has to be accountable for
  the bill"). Demoting or removing the last remaining owner is refused with a typed
  error (`cannot_demote_last_owner` / `cannot_remove_last_owner`) — never a bare 500,
  and never silently allowed. The caller must promote a successor first.
- **`is_tenant_creator` is permanent.** It survives every governance change to the
  same row (owner status can be freely transferred away from the creator — "owners
  swap on edit" — while the creator flag itself never changes). The row itself can be
  **suspended** (`set_membership_status()`, §4) same as anyone, but can never be
  **removed** (`remove_member()` refuses outright, §4) and never hard-deleted (§3.1's
  trigger is the final backstop) — regardless of its current
  `is_tenant_owner`/`is_tenant_admin` state.
- **Governance and job role are independent dimensions.** A tenant's plan for "who can
  manage members" (§1.1) and its plan for "what is this person's job title" (§1.3) do
  not read from or gate each other. A platform must never derive a permission decision
  from `job_role`.

### 1.3 Functional dimension (orthogonal to governance, no permission meaning)

- **`job_role`** — a freeform per-tenant business title ("junior engineer",
  "accountant", ...). Display/org-chart purposes only. The framework stores it as
  free text (`VARCHAR`, nullable); a platform MAY layer its own fixed list or
  validation on top in its own application code, but the framework does not constrain
  it.
- **"Is this identity a consultant / does it belong to more than its own personal
  tenant"** — this is explicitly NOT a stored column on this table. It's a derived
  property of an *identity* (not a tenant×identity row): does this identity hold any
  membership in a tenant other than its own auto-provisioned personal one. Platforms
  that have a personal-tenant concept (a boolean on their own `tenants` table, e.g.
  `is_personal`) can compute this with:
  ```sql
  SELECT EXISTS (
      SELECT 1
      FROM tenant_memberships m
      JOIN tenants t ON t.uuid = m.tenant_id       -- adjust to the platform's own tenants PK
      WHERE m.identity_id = $1
        AND m.status = 'active'
        AND t.is_personal = false                   -- only if the platform has this column
  );
  ```
  Nothing new is stored for this — it's a query pattern, not a schema addition. A
  platform without a personal-tenant concept simply has no use for it.

---

## 2. Schema — `tenant_memberships` (in the platform's OWN main DB)

Lives in the platform's main database (the same DB that already holds `tenants`) —
**not** the auth database, and **not** a per-tenant database. This is required, not
just convenient: `tenants_resolver`/`roles_resolver` must answer "which tenants does
this identity belong to" *before* any specific tenant's database is even selected —
a per-tenant-DB location could never answer that without iterating every tenant DB.

```sql
CREATE TABLE IF NOT EXISTS tenant_memberships (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),

    -- Cross-DB reference to progalaxyelabs-auth's identities table. NOT a foreign
    -- key — auth is a separate database/service; referential integrity here is
    -- app-enforced (an identity_id only ever arrives via a verified JWT claim).
    identity_id         UUID NOT NULL,

    tenant_id           UUID NOT NULL REFERENCES tenants(uuid) ON DELETE CASCADE,

    -- Governance tier (§1.1) — booleans, not an enum: independent bits so multiple
    -- owners/admins can co-exist, and so a demoted owner cleanly falls back to
    -- whatever admin/member state they held, rather than needing a single-value
    -- role column to encode a ladder.
    is_tenant_creator   BOOLEAN NOT NULL DEFAULT false,
    is_tenant_owner     BOOLEAN NOT NULL DEFAULT false,
    is_tenant_admin     BOOLEAN NOT NULL DEFAULT false,

    -- Functional (§1.3) — no permission meaning.
    job_role            VARCHAR(100),

    -- Per-tenant status. Deliberately separate from auth's own platform-wide
    -- membership-status (§0) — a tenant can suspend one of its own members without
    -- auth being involved at all ("roles belong to the platform" extends to "so
    -- does the decision to suspend someone from one"). A platform MAY additionally
    -- call auth's PUT /api/internal/membership-status when it suspends someone here,
    -- if it also wants that identity blocked at login time platform-wide — that's a
    -- platform policy choice, not something this table decides for it.
    status              VARCHAR(20) NOT NULL DEFAULT 'active'
                            CHECK (status IN ('active', 'suspended', 'removed')),

    invited_by          UUID,           -- identity_id of the inviter; NULL for the creator row
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT tenant_memberships_unique_identity_tenant UNIQUE (identity_id, tenant_id)
);

CREATE INDEX IF NOT EXISTS idx_tenant_memberships_identity ON tenant_memberships(identity_id);
CREATE INDEX IF NOT EXISTS idx_tenant_memberships_tenant   ON tenant_memberships(tenant_id);
CREATE INDEX IF NOT EXISTS idx_tenant_memberships_status   ON tenant_memberships(status);
```

The founding row for a brand-new tenant is created with **both** `is_tenant_creator =
true` AND `is_tenant_owner = true` (the creator is always the first owner — see
`create_tenant_membership()` in §4). Only `is_tenant_owner` may later change on that
row; `is_tenant_creator` never does.

---

## 3. Invariant enforcement

### 3.1 Creator immutability — DB trigger (defense in depth, not just app discipline)

```sql
CREATE OR REPLACE FUNCTION _tenant_memberships_protect_creator()
RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' AND OLD.is_tenant_creator THEN
        RAISE EXCEPTION 'tenant_creator_row_undeletable'
            USING HINT = 'Suspend (status=suspended) the creator''s membership instead of deleting '
                         'the row — remove_member() itself already refuses on is_tenant_creator (see '
                         '§4); this trigger is the backstop against any OTHER code path issuing a raw '
                         'hard DELETE.';
    END IF;

    IF TG_OP = 'UPDATE' AND OLD.is_tenant_creator IS DISTINCT FROM NEW.is_tenant_creator THEN
        RAISE EXCEPTION 'tenant_creator_flag_immutable'
            USING HINT = 'is_tenant_creator can only be set at row creation, never edited.';
    END IF;

    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_protect_tenant_creator
    BEFORE UPDATE OR DELETE ON tenant_memberships
    FOR EACH ROW EXECUTE FUNCTION _tenant_memberships_protect_creator();
```

This is the last-resort backstop. Every function in §4 that could plausibly violate
it MUST also check explicitly and return a typed error (see below) — the trigger
exists to catch anything a function author missed, not as the primary UX.

### 3.2 "At least one owner" — enforced in the functions, not a row-level trigger

A single-row trigger can't see the rest of the tenant's rows cheaply. Enforce this
inside every function that can take an owner out of active standing —
`demote_owner()`, `remove_member()`, and `set_membership_status()` when suspending or
removing an owner (§4) — with an explicit, **locked** count inside the same
transaction, to close the race where two concurrent actions each observe ">1 owner"
and both proceed:

```sql
-- Inside demote_owner()/remove_member()/set_membership_status(), before making the change:
PERFORM 1 FROM tenant_memberships
 WHERE tenant_id = p_tenant_id AND is_tenant_owner AND status = 'active'
 FOR UPDATE;   -- locks all current active-owner rows for this tenant for the duration of this transaction

IF (SELECT count(*) FROM tenant_memberships
     WHERE tenant_id = p_tenant_id AND is_tenant_owner AND status = 'active') <= 1
   AND <target is that one owner>
THEN
    RETURN json_build_object('error', 'cannot_demote_last_owner');  -- or cannot_remove_last_owner /
                                                                     -- cannot_suspend_last_owner, per caller
END IF;
```

"Active owner" always means `is_tenant_owner = true AND status = 'active'` — a
suspended or removed owner does not count towards the invariant, which is exactly why
suspending or removing the last remaining owner must be blocked the same as demoting
them.

Every SQL function that can remove an owner's standing MUST use this pattern —
callers get a clean typed JSON error, never a 5xx, and never an actual zero-owner
tenant.

---

## 4. Functions (per-platform, generated — see §6)

| Function | Behavior |
|---|---|
| `create_tenant_membership(p_identity_id, p_tenant_id, p_job_role DEFAULT NULL)` | Called once, at tenant provisioning. Inserts the founding row with `is_tenant_creator = true, is_tenant_owner = true, invited_by = NULL`. Idempotent on `(identity_id, tenant_id)` — a retried provisioning call is a no-op, not a duplicate. |
| `add_member(p_tenant_id, p_identity_id, p_invited_by, p_is_tenant_admin DEFAULT false, p_job_role DEFAULT NULL)` | Adds a new, non-creator member — the primitive an invite-accept flow calls. Always `is_tenant_creator = false`; `is_tenant_owner` is never settable here (owner status only ever arrives via `promote_to_owner` — no function skips a tier). **Upserts**: if `(identity_id, tenant_id)` already exists with `status = 'removed'` (a previously-offboarded member re-invited later), reactivates that row (`status = 'active'`, flags/job_role reset to the values passed in) instead of violating the unique constraint or creating a second row for the same pair. |
| `promote_to_admin(p_tenant_id, p_target_identity_id, p_acting_identity_id)` | Acting identity must currently be owner OR admin for this tenant. Target must not already be owner (owner-tier changes only go through `promote_to_owner`/`demote_owner`). Sets `is_tenant_admin = true`. |
| `demote_admin(p_tenant_id, p_target_identity_id, p_acting_identity_id)` | Acting identity must be owner OR admin (peer demotion allowed — §1.1). Target must not be `is_tenant_owner` (demote via `demote_owner` first). `is_tenant_creator` is untouched regardless of outcome. Sets `is_tenant_admin = false`. |
| `promote_to_owner(p_tenant_id, p_target_identity_id, p_acting_identity_id)` | Acting identity must currently be owner. Target must currently be admin (bottom-up only). Sets `is_tenant_owner = true`. |
| `demote_owner(p_tenant_id, p_target_identity_id, p_acting_identity_id)` | Acting identity must currently be owner. Refuses (`cannot_demote_last_owner`, §3.2) if target is the sole remaining active owner. Sets `is_tenant_owner = false` — target falls back to whatever `is_tenant_admin` state they already hold. |
| `set_job_role(p_tenant_id, p_target_identity_id, p_job_role, p_acting_identity_id)` | Acting identity must be owner or admin. Purely functional (§1.3) — never gates governance. |
| `set_membership_status(p_tenant_id, p_target_identity_id, p_status, p_acting_identity_id)` | General active ↔ suspended toggle (mirrors auth's own `setMembershipStatus` naming for a familiar shape — entirely local/platform-side, no auth call). Acting identity must be owner or admin. Applies to **anyone including the creator** — the creator can be suspended, same as any member (§1.1's "provenance marker, not a permission" — suspension is not a governance edit). Refuses (§3.2) if the target is the sole remaining active owner and the new status is `suspended`. Does not accept `p_status = 'removed'` — that transition only happens through `remove_member()`, which has its own, stricter rules (below). |
| `remove_member(p_tenant_id, p_target_identity_id, p_acting_identity_id)` | Acting identity must be owner or admin. **Soft delete**: sets `status = 'removed'` — never a hard `DELETE` (the trigger in §3.1 is the backstop for any other code path, not the enforcement mechanism for this function; this function enforces its own rules directly). Refuses outright (`cannot_remove_tenant_creator`) if target `is_tenant_creator` — the creator can be suspended via `set_membership_status()` but never removed, which keeps "record non-deletable" true in spirit, not just at the hard-DELETE level. Refuses (`cannot_remove_last_owner`, §3.2) if target is the sole remaining active owner. On success, also clears `is_tenant_owner = false, is_tenant_admin = false` — a removed member holds no standing to silently regain on a later `add_member()` reactivation. |
| `get_tenant_memberships(p_tenant_id)` | Lists every membership row + governance flags + `job_role` for one tenant — the data source for a settings/members-management page. Callers filter `status` themselves (e.g. hide `removed` from the default view). |
| `get_identity_tenant_memberships(p_identity_id)` | Cross-tenant list of everywhere one identity holds an `active` membership — the data source for `tenants_resolver` (§5). |
| `resolve_role_id(p_identity_id, p_tenant_id)` | The `roles_resolver` replacement (§5): returns a single derived role string from the governance flags — `is_tenant_owner ? 'owner' : (is_tenant_admin ? 'admin' : 'member')` for an `active` row; empty for `suspended`/`removed` (exchange refuses with `no_roles_in_tenant`, matching the existing framework contract — see AUTH-IDENTITY.md's `ExchangeRoute` behavior). This is what actually lands on the card's `role_id` claim. |

All acting-identity checks return a typed `{"error": "..."}` JSON body on failure —
never a bare exception, matching the framework's existing business-rule-error
convention (see AUTH-IDENTITY.md §3.3 for the precedent this follows).

---

## 5. Framework integration — replaces the hand-rolled `config/auth.php` resolver

Today, every platform hand-writes its own `tenants_resolver`/`roles_resolver` closures
in `config/auth.php` (see `StoneScriptPHP\Auth\ExternalAuth\Routes\ExchangeRoute` for
the contract these closures must satisfy — unchanged by this spec). This feature adds
a ready-made implementation platforms can wire in directly instead of hand-rolling it:

```php
use StoneScriptPHP\Auth\TenantGovernance\TenantGovernanceResolver;

$governance = new TenantGovernanceResolver();

return [
    // ...
    'tenants_resolver' => $governance->tenantsResolver(),
    'roles_resolver'   => $governance->rolesResolver(),
    // ...
];
```

`TenantGovernanceResolver` calls `get_identity_tenant_memberships()` /
`resolve_role_id()` (§4) via `Database::fn()` against the platform's own main DB —
zero dependency on auth's membership response beyond the identity id itself (already
on the passport claims). A platform with unusual requirements can still hand-roll its
own resolver as before — this is a default, not a hard requirement.

---

## 6. Generator — `php stone generate tenant-governance`

Mirrors the existing `php stone generate invitations` precedent (scaffolds into the
CONSUMING platform's own repo, not shared framework state). Scaffolds:

- Migration + declarative table file for `tenant_memberships` (§2), including the
  trigger (§3.1).
- All twelve SQL functions (§4), plus their `php stone generate model` PHP wrappers.
- `TenantGovernanceResolver` (§5) — framework-shipped class, not scaffolded per
  platform (ships in `progalaxyelabs/stonescriptphp` itself, since its logic never
  varies platform to platform — only the schema it queries does, and that schema
  is fixed by this spec).

Route handlers (invite/promote/demote HTTP endpoints) and any admin UI are
deliberately **not** part of this generator — those are platform-specific business
routes (an admin page's exact promote/demote UX varies per platform), built on top of
the functions in §4 the same way any other feature route is built.

---

## 7. Rollout (later, separate step, per platform)

Out of scope for this spec — each platform's adoption is its own task:

- A platform with **no existing local membership ledger** adopts this fresh; its
  provisioning route wires `create_tenant_membership()` in at tenant-creation time
  (replacing whatever ad hoc "hardcode the creator's role" logic it has today).
- A platform with an **existing, differently-shaped local ledger** (e.g. a flat
  `role` string with no governance tiers) needs its own data migration mapping old
  role values onto the new governance flags — not designed here, since the mapping
  is specific to whatever roles that platform already has in production.
- Every platform should audit whether it even has a "tenant" concept shaped like this
  before assuming it applies — some are single-tenant or have a fundamentally
  different membership shape.

---

## 8. Explicitly out of scope (noted so it isn't lost, not designed here)

- **Public per-tenant profile/promotion pages** (a platform surfacing each of its
  tenants at a public, slug-based URL to market them) — a real, separate product
  feature, noted here only so it isn't lost. Needs its own task (a `slug` column
  on that platform's `tenants` table + a public route/page) — unrelated to the data
  model in this spec beyond potentially reading `tenant_memberships` to show a
  tenant's public team listing.
- Promote/demote/invite HTTP routes and any admin-facing UI (§6).
- Any change to auth, the passport/card JWT pipeline, or AUTH-IDENTITY.md's existing
  contract (§0) — this spec is purely additive on the platform side.

---

## 9. Acceptance checklist

**Framework side (`progalaxyelabs/stonescriptphp`):** — done in 7.4.0.
- [x] `TenantGovernanceResolver` class (§5) shipped in the framework itself
      (`src/Auth/TenantGovernance/TenantGovernanceResolver.php`).
- [x] `php stone generate tenant-governance` command (§6) scaffolds migration + table
      + all fourteen functions (§4 — twelve public + two internal helpers) + trigger
      (§3.1) + PHP model wrappers (public functions only) into the consuming platform's
      repo. Detects nested `main/postgresql/` layout vs flat; registers no HTTP routes.
- [x] PHPUnit coverage: `TenantGovernanceResolverTest` (11 tests — both resolvers, the
      enricher, suspended→empty-roles, missing-identity/tenant short-circuits, o_-prefix
      + bare-key handling) and `GenerateTenantGovernanceCommandTest` (3 tests — nested +
      flat layout scaffolding, model-wrapper set = public only, idempotency). The SQL
      functions' own authorization gates, last-owner-standing race (`FOR UPDATE`), and
      creator immutability (UPDATE + DELETE) were verified directly against a real
      Postgres 16 during development (a live-DB integration harness for these is the one
      remaining coverage gap — the pure-SQL behavior is proven, but not yet pinned in an
      automated CI test; noted as follow-up).
- [x] Version bumped (`7.4.0`) + tagged (`v7.4.0`), per this repo's convention.

**Integration (later, separate step, per adopting platform):**
- [ ] Provisioning route wires `create_tenant_membership()` at tenant creation.
- [ ] `config/auth.php` wired to `TenantGovernanceResolver` (or an equivalent
      platform-specific implementation of the same contract).
- [ ] Existing local role data (if any) migrated onto the new governance flags.
- [ ] Live-tested end-to-end (OTP + Mailpit recipe): fresh signup, existing-member
      re-login, promote/demote, last-owner-removal refusal.
