# Upgrading to 14.3

Requires StoneScriptDB Gateway **4.10 or later** and `stonescriptdb-gateway-client` **4.2 or later**. Platform tokens now carry a scope.

| Token | Env name | Held by | Used for |
|---|---|---|---|
| runtime | `DB_GATEWAY_PLATFORM_TOKEN` | the application server | function calls; creating and first-migrating a new tenant database |
| deploy | `DB_GATEWAY_DEPLOY_TOKEN` | the deploy manager only | platform registration, schema upload, migrations, export |
| admin | `DB_GATEWAY_ADMIN_TOKEN` | the gateway operator | issuing and rotating tokens, first registration, `--force` / `--allow-*` |

## Application

No change. The existing `DB_GATEWAY_PLATFORM_TOKEN` keeps working as the runtime token. Do not add the deploy token to the application's environment.

## Deploy tooling (`php stone gateway:*`)

The CLI reads the deploy token from the **process environment** for the run: `DB_GATEWAY_DEPLOY_TOKEN`, or `DB_GATEWAY_DEPLOY_TOKEN_FILE` pointing at a mode-600 file the deploy script writes before the run and deletes afterwards. It is never read from `.env`; a `.env` that carries the key is reported by name and ignored. When it is missing, the command stops with a clear error. The CLI does not fall back to the runtime token.

| Step | Credential |
|---|---|
| register platform | `DB_GATEWAY_ADMIN_TOKEN` (the deploy token when the platform exists and no admin token is set) |
| issue / rotate a token | `DB_GATEWAY_ADMIN_TOKEN` |
| upload schema, create database, migrate, migrate-all, list schemas/databases | deploy token |
| `--force` / `--allow-*` | deploy token, plus `DB_GATEWAY_ADMIN_TOKEN` in the `X-Gateway-Admin-Token` header |

### New platform, in order

1. `php stone gateway:register-main` with `DB_GATEWAY_ADMIN_TOKEN` set. It registers the platform, issues the deploy token (shown once), uploads the schema and creates the database.
2. Store the deploy token in the deploy manager's secret store.
3. Issue the application's token: `php stone gateway:provision-token --quiet` (runtime scope, the default) and store the output as `DB_GATEWAY_PLATFORM_TOKEN` for the application.
4. Later deploys supply `DB_GATEWAY_DEPLOY_TOKEN` and run `gateway:migrate-main`, `gateway:migrate-tenant`, ...

### Existing platform

The platform's current token is already a runtime token. Issue the deploy token once: `php stone gateway:provision-token --scope=deploy --quiet`. A token the gateway holds cannot be read back, and the CLI never replaces one on its own; use `--rotate` to replace it deliberately (the previous token stops working immediately).

### Rollout order

1. Upgrade the gateway to 4.10 or later.
2. Issue a deploy token for each platform and store it with the deploy tooling.
3. Upgrade the framework and the deploy scripts; supply the deploy token per run.

### Publish order (maintainers)

1. Gateway 4.10.0.
2. `stonescriptdb-gateway-client` 4.2.0 (must be on Packagist before step 3).
3. Framework: `composer update progalaxyelabs/stonescriptdb-gateway-client`, commit the refreshed `composer.lock`, run the unit suite, tag 14.3.0.
