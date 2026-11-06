# Upgrading to 14.3

Requires StoneScriptDB Gateway **4.10 or later** and `stonescriptdb-gateway-client` **4.2 or later**. Platform tokens carry a scope. Older gateways ignore the extra headers, so the framework can be upgraded first, but the deploy-token flow below needs gateway 4.10.

| Token | Env name | Held by | Used for |
|---|---|---|---|
| runtime | `DB_GATEWAY_PLATFORM_TOKEN` | the application server | function calls; creating and first-migrating a new tenant database |
| deploy | `DB_GATEWAY_DEPLOY_TOKEN` | the deploy manager only | platform registration, schema upload, migrations, export |
| admin | `DB_GATEWAY_ADMIN_TOKEN` | the gateway operator (workstation) | issuing and rotating tokens, first registration, `--force` / `--allow-*` |

## Application

1. Bump `progalaxyelabs/stonescriptphp` to `^14.3`, then run `composer update`.
2. Make sure `DB_GATEWAY_PLATFORM_TOKEN` is present in the application's environment. The framework sends it as `Authorization: Bearer <token>` on every gateway request. `php stone env:check` warns when it is missing in gateway mode.
3. Do not add the deploy token or the admin token to the application's environment. Tenant provisioning from the application needs only the runtime token; if it is missing, provisioning stops with a clear error and does not mint one.

No application code changes are needed. Never log or commit a token.

## Deploy tooling (`php stone gateway:*`)

The CLI reads the deploy token from the **process environment** for the run: `DB_GATEWAY_DEPLOY_TOKEN`, or `DB_GATEWAY_DEPLOY_TOKEN_FILE` pointing at a mode-600 file the deploy script writes before the run and deletes afterwards. It is never read from `.env`; a `.env` that carries the key is reported by name and ignored. When it is missing the command stops with guidance. The CLI does not fall back to the runtime token and does not issue a deploy token itself.

| Step | Credential |
|---|---|
| register platform | `DB_GATEWAY_ADMIN_TOKEN` (the deploy token when the platform exists and no admin token is set) |
| issue / rotate a token | `DB_GATEWAY_ADMIN_TOKEN` |
| upload schema, create database, migrate, migrate-all, list schemas/databases | deploy token |
| `--force` / `--allow-*` | deploy token, plus `DB_GATEWAY_ADMIN_TOKEN` in the `X-Gateway-Admin-Token` header |

The deploy token and the admin token should be present only where deploys are run, never in the application's environment or in files the application can write.

### New platform, in order

Run these on the workstation, where the admin token is available.

1. `php stone gateway:register-main --issue-deploy-token=/path/to/new/platform.token`, with `DB_GATEWAY_ADMIN_TOKEN` set. It registers the platform, issues the deploy token, writes it to that file (a new file, mode 600, in a directory that is mode 700 and owned by you; the token is not printed), uploads the schema and creates the database.
2. Hand the file's content to the deploy manager's secret store.
3. Issue the application's token: `php stone gateway:provision-token --quiet` (runtime scope), and store the output as `DB_GATEWAY_PLATFORM_TOKEN` for the application. The command runs on the workstation too; its output is a secret.
4. Later deploys supply `DB_GATEWAY_DEPLOY_TOKEN` and run `gateway:migrate-main`, `gateway:migrate-tenant`, ...

### Existing platform

The platform's current token is already a runtime token. Issue the deploy token once, from the workstation: `php stone gateway:register-main --issue-deploy-token=<file>`. A token the gateway holds cannot be read back, and the CLI never replaces one on its own; add `--rotate-deploy-token` to replace it deliberately (the previous token stops working immediately). `gateway:provision-token --rotate` does the same for the runtime token; update every place that holds it.

### Rollout order

1. Deploy the gateway (4.10 or later); `GATEWAY_CALL_AUTH=log` is an option while tokens are being distributed. Deploy-scope checks apply from the first start (see the gateway's `docs/UPGRADING-4.9.md`).
2. Roll out this framework version with `DB_GATEWAY_PLATFORM_TOKEN` set in every environment.
3. Issue a deploy token for each platform and store it with the deploy tooling; supply it per run.
4. When the gateway log shows no `call_auth(log)` lines for a full business cycle, remove `GATEWAY_CALL_AUTH=log` and restart the gateway.
