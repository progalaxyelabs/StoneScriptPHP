# Upgrading from 14.x to 15.0.0

15.0.0 adds tenancy v2 and makes breaking changes (section 1). A platform can upgrade first and switch to the new
provisioning flow later, one platform at a time: with `TENANCY_V2` unset or `false` the 14.x provisioning flow is unchanged.

## 1. Breaking changes: required before you deploy 15.0.0

### 1.1 `PLATFORM_CODE` is required (fail-closed guard, hard boot error)

`PlatformCodeGuard` used to admit every token when `PLATFORM_CODE` was not configured (with a warning). It now refuses
every token, and a missing or blank `PLATFORM_CODE` is a **hard error at boot** when `AUTH_MODE` is `external` or
`hybrid`: `Application::run()` refuses to serve and `php stone env:check` exits 1 with

```
Required configuration 'PLATFORM_CODE' is missing or empty (AUTH_MODE=external). Set PLATFORM_CODE to this platform's slug: ...
```

The exact check, run it as the PHP-FPM user on the deploy target BEFORE you switch traffic:

```
sudo -u <fpm-user> php stone env:check     # must exit 0
```

The code is resolved exactly as the auth routes resolve it: the app's auth config (`auth.platform.code`) first, else Env
`PLATFORM_CODE`; `env:check` reads the project's `config/auth.php` for the first. Only when BOTH are missing or blank is it an
error. `PLATFORM_CODE` must equal the platform slug registered with the auth service and `DB_GATEWAY_PLATFORM` (one value,
three places). Routes answer `403 platform_not_configured` and `InvitationCompletionService` throws a config error when
`$platformConfig['platform_code']` is missing; the generated invitations config now reads it through `Env` (it used
`getenv()`, which does not see values that live in `.env`).

### 1.2 Calls to endpoints the auth service never had are removed

| Removed | Use instead |
|---|---|
| `ExternalAuthServiceClient::checkTenantSlug()`, route `GET {prefix}/check-tenant-slug/{slug}`, feature `check_slug` | nothing: slug is optional and validated by the authority when a tenant is reserved |
| `ExternalAuthServiceClient::acceptInvite()` | `php stone generate invitations` + `InvitationCompletionService` |
| `MembershipClient::updateMembership()`, `suspendMembership()`, `reactivateMembership()`, route `PUT {prefix}/memberships/{id}` | `ExternalAuthServiceClient::setMembershipStatus()` (14.x flow) or `MembershipAuthority::setMembershipStatus()` (tenancy v2) |
| `TenantProvisioner::syncBusinessDetails()` | business details live in the platform's own `tenants` table |

`grep -rn "checkTenantSlug\|acceptInvite\|updateMembership\|syncBusinessDetails\|check_slug"` in your code finds every use.

## 2. Switching a platform to tenancy v2 (one platform at a time)

1. `php stone generate tenancy` (add `--builtin` for a standalone platform with local identities). The target directories
   are parsed for `CREATE TABLE` (file names mean nothing). A table you already define (`tenants`, `tenant_memberships`,
   the tenant `users`, ...) is REFUSED: no second definition is written, and the command prints what to mirror in your own
   declaration. For an existing tenant `users` it writes a forward-only expand migration
   (`users_expand_identity`: adds `identity_id` backfilled from `id`, `status` default `active`, `UNIQUE (identity_id)` after a
   check that raises naming duplicates; edit the backfill if your `users.id` is not the identity). If your `tenant_memberships`
   is the `generate tenant-governance` ledger, move the creator/owner/admin tiers into tenant-database roles first.
   `DB_GATEWAY_PLATFORM` must be at most 19 characters. **Exit status 2** means it refused at least one existing table (the
   guidance is printed first); 0 means everything was written or already present.
2. Platform business columns on `tenants` must be nullable or defaulted: the sequence inserts only the core columns and
   then runs your `after_tenant_row` hook.
3. `php stone gateway:migrate-main`, then migrate every tenant database. The generated migrations are forward-only and
   idempotent. They raise, naming the rows, if `tenants.tenant_db_name`, `(identity_id, tenant_id)` or `users.identity_id`
   already has duplicates, or if `tenant_memberships.tenant_id` is not `uuid`; fix the data (or convert the column with the
   gateway change-column helper) and run again. Nothing is dropped: the old `role` and `db_status` columns stay until the
   gateway drop-column wrapper retires them. `tenants.tenant_provisioning_status` is derived from the old `db_status` on
   EVERY run for rows that have none: creating -> provisioning, active -> provisioned, error -> provisioning_failed,
   suspended -> provisioned (a suspension is not a provisioning state), deleted -> deprovisioned; any other value stays NULL
   (not offered at login) and is reported in a NOTICE.
4. **Privilege seed.** `generate tenancy` writes `config/tenant-privileges.php` (role => privileges). It is written into
   EVERY tenant database at provisioning (`user_role_privileges`), idempotently. **Provisioning fails** (tenant marked
   `provisioning_failed`, no token issued) if the owner role has no privileges in the tenant database after seeding, so keep
   the owner entry filled in (or seed it in your own tenant schema). Add a privilege there and it reaches new tenants; reach
   existing tenants with a tenant migration. Move existing role data into `users` / `user_roles` (a one-off script per platform).
   **MANDATORY pre-flip step, for the tenants that already exist:** after the tenant migrations, run
   `php stone tenancy:seed-privileges --dry-run` (lists, per tenant, the privileges it would add), then
   `php stone tenancy:seed-privileges` (applies `config/tenant-privileges.php` to every provisioned tenant database,
   idempotently, reports per tenant, continues past a failing tenant and exits 1 if any failed; `--tenant=<uuid>` for one).
   Then `php stone tenancy:preflight` MUST pass: it fails (exit 1) if (a) any provisioned tenant has a role that users hold but
   that has zero privileges, (b) any ACTIVE main `tenant_memberships` identity has no active tenant-DB `users` row holding a
   role with at least one privilege (this catches an empty `user_roles` before your one-off role move), or (c) a membership
   status that auth confirmed was never mirrored into your table (drift). Each user would be refused at select-tenant. It
   reports per tenant counts and identity/membership ids only. Do not set `TENANCY_V2=true` until it passes
   (`env:check` reminds you when the flag is on).
5. External auth only: put the operator token in `~/.stone/operator.env` (`STONE_OPERATOR_TOKEN=...`, mode 600) on the
   machine you run it from (https auth URL; plain http only for localhost), then
   `php stone platform:register --name "<name>" --slug <slug>`. It writes the v2 credential to `AUTH_V2_CLIENT_ID` /
   `AUTH_V2_CLIENT_SECRET` (and `PLATFORM_UUID`) in the runtime `.env` (use `--secrets-file` for another file; a symlinked
   file is written through the link) and records the `platform` row. **It never touches `EXTERNAL_AUTH_CLIENT_SECRET`**, the
   live 14.x secret. Run it again if it stops half way. The operator token never goes in the application `.env`.
   `php stone platform:rotate-credential` adds a `next` credential (written to `AUTH_V2_CLIENT_ID_NEXT` /
   `AUTH_V2_CLIENT_SECRET_NEXT`); move those values into `AUTH_V2_CLIENT_ID` / `AUTH_V2_CLIENT_SECRET`, deploy, then the
   operator revokes the old client id at auth.
6. Set `TENANCY_V2=true`. `POST {prefix}/provision-tenant`, `POST {prefix}/select-tenant`, `POST {prefix}/exchange` and
   `GET {prefix}/memberships` now use the tenancy v2 flow (details below). To pass business columns or seed data, override
   `ProvisionTenantRoute::buildTenancySequence()` (or add `after_tenant_row` / `seed_tenant` entries to the route hooks; hooks
   must be idempotent: a resumed tenant runs them again, an already provisioned one runs nothing). The order of the steps is
   not overridable.
7. Resolve roles with `TenantRoleResolver(new DatabaseTenantRoleStore())` instead of any "unknown role means owner" mapping.

## 3. What tenancy v2 does

- **Provisioning order:** auth reserves tenant + owner membership; main `tenants` row; gateway database (its returned name
  must equal the reserved `tenant_db_name`); privilege seed; owner `users` + `user_roles`; main `tenant_memberships`; then
  `provisioned`. A retry with the same `idempotency_key` resumes the same tenant (a resume answers 200, a fresh tenant 201).
- **Google sign-up** (`oauth_state` on provision-tenant): the OAuth promote runs FIRST and provisioning continues with the
  access token and identity auth returns; that token is verified with the JWKS validator before its identity is trusted.
  Only auth's "already used" answer is 409 `oauth_session_used` (an earlier attempt consumed the single-use session: sign in
  again and retry with the same `idempotency_key`); any other 400 is 400 `oauth_promote_failed`. `oauth_state` is refused
  (400 `oauth_not_supported`) on a standalone (builtin) platform.
- **Existing-tenant guard** (as in 14.x, read through the authority): an identity that already OWNS provisioned tenants gets
  back the one whose name matches (200); 409 `tenant_already_exists` only when none matches, unless `allow_additional_tenant`.
- **provision-tenant, select-tenant, exchange** all answer the same way: the token carries ALL the user's known roles in
  `roles[]` and no `role_id` (no primary or active role) plus `platform_code`; the body lists `roles` and `privileges` (the
  union). Roles are read strictly from the tenant database: no known role is 403 `no_tenant_role`, a failed read is 502.
  `exchange` never calls the platform's `roles_resolver` / `tenants_resolver` under v2. select-tenant needs no `selection_token`.
- **Token shapes:** `AuthenticatedUser::$roles` / `hasRole()` / `hasAnyRole()` and `RequireRoleMiddleware` read `roles[]` ONLY
  from a tenancy v2 token (`token_type` `card`, no `role_id`); every other token keeps its legacy meaning.
- **accept-invite** (`InvitationCompletionService`, `$platformConfig['tenancy_v2']` or `TENANCY_V2`): the framework adds the
  member through `TenantStaffInvite` (auth v2 add member, tenant-DB `users` + `user_roles` with the invitation's role, main
  `tenant_memberships`), idempotent on `invite:<invitation id>`; `membership_writer` is not called and the invitation record
  must carry its `tenantId`, or the request must be routed to the tenant (the running tenant scope or the gateway client's
  tenant id); it is refused only when neither exists. An invitation with no inviter is refused with 422
  `invite_missing_inviter`. A role the privilege seed does not know (or an empty role) refuses the invite with 422
  `invite_invalid_role` BEFORE anything is written; the invitation is marked accepted last, so a failed accept is retryable.
  The token carries all the member's known roles (`roles[]`). Platforms whose invitation table has no tenant id must add it.
- **Staff invite and member status:** `TenantStaffInvite` adds the member; a re-invite of a suspended or removed member is
  refused with its status (never silently reactivated). `MembershipAuthority::setMembershipStatus()` keeps auth's rule that a
  tenant never loses its last active owner. In external mode auth is the source of truth: after auth confirms, the status is
  mirrored into the platform's `tenant_memberships` row WITHOUT the local last-owner rule; if that write fails the call still
  succeeds, the drift is logged (ids only) and recorded in `tenancy_mirror_drift`, and `tenancy:preflight` reports it.
- `tenant_user_upsert` inserts `id = identity_id`, so it also works on an existing tenant `users` table whose `id` is the
  identity and has no default.
- The auth v2 membership list must carry a boolean `is_tenant_owner` on every row; a row without it is refused.

## 4. Notes

- A platform slug is at most 19 characters (lower-case words joined by `_`), so that `{slug}_tenant_{uuid_}` fits the
  63-byte database name limit. `platform:register`, `generate tenancy`, the `platform` table and `platform_upsert` enforce it;
  auth answers 422 `tenant_db_name_too_long` otherwise.
- **One name, three places:** `PLATFORM_CODE` == the slug registered with auth == `DB_GATEWAY_PLATFORM`. The identity token's
  `platform_code` claim must equal it.
- `PLATFORM_UUID` is the registered platform's uuid. `PLATFORM_ID` keeps its old meaning (the platform slug used by the
  gateway commands) and is never written by `platform:register`.
- The auth v2 HTTP contract is implemented in one class, `StoneScriptPHP\Tenancy\Provisioning\AuthV2Client`; its
  docblock lists every path, header and field, and `tests/Unit/Tenancy/AuthV2ClientContractTest.php` pins them.
- Standalone mode: `AUTH_MODE=builtin` selects `BuiltinMembershipAuthority`; call `platform_upsert(<new uuid>, <name>, <slug>, 'builtin')` once.
