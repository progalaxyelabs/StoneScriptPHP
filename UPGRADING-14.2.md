# Upgrading to 14.2

Requires StoneScriptDB Gateway **4.9 or later** for the authenticated flow below. Older gateways ignore the extra headers, so the framework can be upgraded first.

## Application

1. Bump `progalaxyelabs/stonescriptphp` to `^14.2.1` (it requires `progalaxyelabs/stonescriptdb-gateway-client` `^4.1.1`), then run `composer update`.
2. Make sure `DB_GATEWAY_PLATFORM_TOKEN` is present in the application's environment. The framework sends it as `Authorization: Bearer <token>` on every gateway request.
3. Run `php stone env:check`; it warns when the variable is missing in gateway mode.

No application code changes are needed. Never log or commit the token.

## Deploy tooling (`php stone gateway:*`)

The CLI authenticates each step with the right credential:

| Step | Credential |
|---|---|
| register platform | `DB_GATEWAY_ADMIN_TOKEN` (platform token if the platform already exists and no admin token is set) |
| issue / rotate platform token | `DB_GATEWAY_ADMIN_TOKEN` |
| upload schema, create database, migrate, migrate-all, list schemas/databases | `DB_GATEWAY_PLATFORM_TOKEN` |
| `--force` / `--allow-*` | platform token, plus `DB_GATEWAY_ADMIN_TOKEN` in the `X-Gateway-Admin-Token` header |

Deploy hosts that run `--force` or `--allow-*` therefore need both tokens; all other steps need only the platform token.

### New platform, in order

1. `php stone gateway:register-main` with `DB_GATEWAY_ADMIN_TOKEN` set. It registers the platform, issues the platform token, uploads the schema and creates the database, in that order.
2. The platform token is shown once. Store it as `DB_GATEWAY_PLATFORM_TOKEN` in your secret store. In CI, run `php stone gateway:provision-token --quiet` instead, which prints only the token to stdout so it can be written straight to a secret store without reaching a build log. (With `--quiet`, commands that would have to issue a token refuse rather than discard it.)
3. Later commands (`gateway:migrate-main`, `gateway:migrate-tenant`, ...) use `DB_GATEWAY_PLATFORM_TOKEN`.

### Existing platform

Set `DB_GATEWAY_PLATFORM_TOKEN` and run the commands as before. A token the gateway already holds cannot be read back, and the CLI never replaces it on its own. To replace it deliberately (the previous token stops working immediately), run `php stone gateway:provision-token --rotate` and update every place that holds the token.

### Rollout order

1. Deploy the gateway (4.9 or later) with `GATEWAY_CALL_AUTH=log`.
2. Roll out this framework version with `DB_GATEWAY_PLATFORM_TOKEN` set in every environment, and run your deploy tooling once in this mode.
3. When the gateway log shows no `call_auth(log)` lines for a full business cycle, remove `GATEWAY_CALL_AUTH=log` (enforce is the default) and restart the gateway.

### Publish order (maintainers)

1. Gateway 4.9.1.
2. `stonescriptdb-gateway-client` 4.1.1 (must be available on Packagist before step 3).
3. Framework: run `composer update progalaxyelabs/stonescriptdb-gateway-client`, commit the refreshed `composer.lock`, run the unit suite, tag 14.2.1.
