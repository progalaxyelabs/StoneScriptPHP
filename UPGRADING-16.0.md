# Upgrading from 15.0.0 to 16.0.0

16.0.0 is a rename-only release: the framework stops carrying a version in the names of its tenancy code. Behaviour is
unchanged EXCEPT the tenancy flag, whose meaning is inverted (it now names the OLD side). Do the renames below, then
run `php stone env:check`.

If you are on 14.x, read `UPGRADING-15.0.md` first (all names in it are already the 16.0.0 names) and skip this file's
tables: there is nothing to rename on a platform that never used the 15.0.0 names.

## 1. Environment variables

| 15.0.0 | 16.0.0 | Note |
|---|---|---|
| `TENANCY_V2` (default `false`) | `LEGACY_TENANCY` (default `true`) | INVERTED. Old `TENANCY_V2=true` becomes `LEGACY_TENANCY=false`; old unset/`false` becomes unset or `LEGACY_TENANCY=true` (the legacy 14.x flow, unchanged). Delete the old line: if you leave `LEGACY_TENANCY` unset the platform stays on the legacy flow even though the old line said `true`. |
| `AUTH_V2_CLIENT_ID` | `PLATFORM_CLIENT_ID` | written by `php stone platform:register` |
| `AUTH_V2_CLIENT_SECRET` | `PLATFORM_CLIENT_SECRET` | written by `php stone platform:register` |
| `AUTH_V2_CLIENT_ID_NEXT` | `PLATFORM_CLIENT_ID_NEXT` | written by `php stone platform:rotate-credential` |
| `AUTH_V2_CLIENT_SECRET_NEXT` | `PLATFORM_CLIENT_SECRET_NEXT` | written by `php stone platform:rotate-credential` |

`EXTERNAL_AUTH_CLIENT_SECRET` and `PLATFORM_UUID` are unchanged, and the framework still never writes the former.
Rename the variables in the runtime `.env` / secrets file on every machine before deploying 16.0.0; a platform
whose credential is still under the old names has no credential and its auth calls fail with the
"run platform:register" message.

## 2. PHP names

| 15.0.0 | 16.0.0 |
|---|---|
| `StoneScriptPHP\Tenancy\Provisioning\AuthV2Client` | `StoneScriptPHP\Tenancy\Provisioning\AuthTenancyClient` |
| `TenancyMode::v2Enabled()` (true = new flow) | `TenancyMode::legacy()` (true = legacy flow; the check is inverted) |
| `Env::$TENANCY_V2` (false) | `Env::$LEGACY_TENANCY` (true) |
| `Env::$AUTH_V2_CLIENT_ID`, `Env::$AUTH_V2_CLIENT_SECRET` | `Env::$PLATFORM_CLIENT_ID`, `Env::$PLATFORM_CLIENT_SECRET` |
| `BaseExternalAuthRoute::tenancyV2Enabled()` (protected; override point) | `BaseExternalAuthRoute::legacyTenancy()` (inverted: return `true` for the legacy flow) |
| `$platformConfig['tenancy_v2']` (invitation completion) | `$platformConfig['legacy_tenancy']` (inverted) |
| private `processTenancyV2`, `promoteOAuthForV2`, `findOwnedTenantsV2`, `completeTenancyV2`, `tenancyV2` | `processTenancy`, `promoteOAuth`, `findOwnedTenants`, `completeTenancy`, `legacyTenancy` |

A platform that overrides `tenancyV2Enabled()` must rename the override AND invert its return value. A platform
that passes `'tenancy_v2' => ...` in its invitation platform config must rename the key AND invert the value.

## 3. Auth service routes called by `AuthTenancyClient`

Same methods, bodies and responses; only the path prefix lost its version segment (the auth service ships the same
rename):

| 15.0.0 | 16.0.0 |
|---|---|
| `/api/internal/v2/operator/platforms` (and `/{id}/credentials/rotate`) | `/api/internal/operator/platforms` (and `/{id}/credentials/rotate`) |
| `/api/internal/v2/tenants...` | `/api/internal/tenants...` |
| `/api/internal/v2/memberships...` | `/api/internal/memberships...` |

Deploy the auth service with the matching routes BEFORE a platform that runs 16.0.0 switches to the new flow; with
`LEGACY_TENANCY=true` none of these routes is called.

## 4. Generated files and SQL

| 15.0.0 | 16.0.0 |
|---|---|
| template `main/migrations/tenancy_v2_core.pgsql.template` | `main/migrations/tenancy_core.pgsql.template` |
| generated migration file `NNN_tenancy_v2_core.pgsql` | `NNN_tenancy_core.pgsql` for a project that generates it from now on |
| migration error / notice prefix `tenancy_v2:` | `tenancy:` |

A project that ALREADY generated `NNN_tenancy_v2_core.pgsql` keeps that file as it is (it is idempotent and already
applied; renaming an applied migration would run it again). `generate tenancy` run again on such a project writes the
new `tenancy_core` migration alongside; it is idempotent too. No generated SQL function or table name carried the old
version, so nothing in the databases changes.

## 5. Tests and docs

`AuthV2ClientContractTest` -> `AuthTenancyClientContractTest`, `InvitationV2JourneyTest` -> `InvitationJourneyTest`,
`SelectTenantV2Test` -> `SelectTenantFlowTest`; test route doubles `V2ProvisionRoute` / `V2MembershipsRoute` ->
`SwitchableProvisionRoute` / `SwitchableMembershipsRoute`. Log text that said "v2 membership ..." now says
"membership ...". Platform tests that fake the authority or override the flag follow section 2.

## 6. Checklist

1. In every runtime `.env`: replace `TENANCY_V2=true` with `LEGACY_TENANCY=false` (and remove `TENANCY_V2=false`),
   rename the four credential variables.
2. Rename and invert any `tenancyV2Enabled()` override and `tenancy_v2` config key.
3. Auth service on the matching routes, then `composer update progalaxyelabs/stonescriptphp`.
4. `sudo -u <fpm-user> php stone env:check` (it prints a NOTE when `LEGACY_TENANCY` is false).
5. `grep -rniE "TENANCY_V2|AUTH_V2|AuthV2Client|tenancyV2|tenancy_v2|v2Enabled"` in your code finds anything left.
