# StoneScriptPHP

[![Packagist Version](https://img.shields.io/packagist/v/progalaxyelabs/stonescriptphp)](https://packagist.org/packages/progalaxyelabs/stonescriptphp)
[![License](https://img.shields.io/github/license/progalaxyelabs/StoneScriptPHP)](LICENSE)

A PHP backend framework for building PostgreSQL-backed APIs, with Angular-inspired
routing, a `stone` CLI for code generation, and improved developer ergonomics.

This repository is the **core framework library** — the routing, auth, validation,
and database-binding code that everything else in the ecosystem is built on.

## Which package do I want?

| I want to... | Use this |
|---|---|
| Start a new API project | [`stonescriptphp-server`](https://github.com/progalaxyelabs/StoneScriptPHP-Server) — `composer create-project progalaxyelabs/stonescriptphp-server my-api` |
| Contribute to the framework, build a custom project template, or integrate StoneScriptPHP into an existing app | This repository — `composer require progalaxyelabs/stonescriptphp` |

If you're just getting started, go create your project with `stonescriptphp-server`
first — it already depends on this package, so you'll get it automatically. Come
back here if you need to read or modify the framework's own code.

## Features

- **PostgreSQL-first** — business logic lives in SQL functions; PHP models wrap them with types
- **JWT authentication** — RSA & HMAC, built-in Google OAuth, pluggable auth modes
- **RBAC** — role-based permissions
- **Request validation** — 12+ built-in rules
- **Redis caching** — optional, with tag-based invalidation
- **CLI code generation** — routes, models, migrations, TypeScript clients, all via `php stone`
- **Structured logging** — PSR-3 compatible, colorized console output
- **VS Code extension** — snippets for the framework's conventions

## Requirements

- PHP >= 8.2, with `pdo`, `pdo_pgsql`, `json`, `openssl`
- PostgreSQL >= 13
- Composer
- Redis (optional, for caching)

## Architecture in one paragraph

Routes handle HTTP concerns only (validation, auth, response shaping). Each route
calls a generated PHP model, which calls a PostgreSQL function — that's where the
actual business logic and queries live. This keeps the PHP layer thin and puts
logic close to the data it operates on. See [HLD.md](HLD.md) for the full picture.

## Auth Service clients (backend-to-backend)

If your app needs to manage memberships or invitations from backend code (e.g. a
payment webhook creating a membership, or a bulk-invite CLI job), the framework
ships thin HTTP clients for the Auth Service:

```php
use StoneScriptPHP\Auth\Client\MembershipClient;

$client = new MembershipClient('http://auth-service:5000');
$client->createMembership([
    'identity_id' => $userId,
    'tenant_id' => $tenantId,
    'role' => 'premium_member',
], $systemAdminToken);
```

`InvitationClient` works the same way for invite/bulk-invite/cancel. These are for
backend automation only — for frontend login and token handling, use the Angular
client (`ngx-stonescriptphp-client`) instead.

## Subscription enforcement: read-only by default (v10)

When a tenant's subscription or trial is expired, inactive, or missing, the framework keeps
the customer's data accessible instead of locking them out:

| Request | Result |
|---|---|
| `GET` / `HEAD` / `OPTIONS` | allowed |
| writes on the allow-list (`/health`, `/auth`, `/subscription`, `/export`, `/internal`, `/account/subscription`, `DELETE /account`, `POST /account/delete`, `POST /account/cancel-deletion` + your `write_allow_list`) | allowed |
| any other write | **HTTP 423**, `data.error_code` = `READ_ONLY_TRIAL_EXPIRED` / `READ_ONLY_PLAN_ENDED` / `READ_ONLY_NO_SUBSCRIPTION` |

Every authenticated response carries `X-Subscription-State` (`ok`, `trial_ending; ends_at=..; days=..`,
`plan_ending; ...`, `read_only; ended_at=..; reason=..`) so a client can show a warning banner with
no extra call (default window: 7 days).

```php
'subscription' => [
    'expired_mode'         => 'read_only',        // default. 'block' = legacy 402 lockout
    'write_allow_list'     => ['/devices/pair'],  // extra writes allowed while read-only
    'warning_days'         => 7,
    'missing_subscription' => 'read_only',        // or 'allow'
],
```

**Your deletion and renewal routes MUST be allow-listed.** Every prefix in the defaults
(`/auth`, `/subscription`, `/export`, `/internal`, `/account/subscription`) is unprotected for all methods,
and only the exact account routes named above are exempt. If your account-deletion, cancel-deletion,
renewal or payment routes live elsewhere (e.g. tenant-scoped), list them, or an expired tenant gets a
423 on its right to erasure:

```php
'write_allow_list' => [
    'POST /portal/tenant/{tenantId}/account/delete',           // {param} = exactly one segment
    'POST /portal/tenant/{tenantId}/account/cancel-deletion',
    '/portal/tenant/{tenantId}/files/authorize',               // any method, and sub-paths
    'POST /devices/pairing/redeem',                            // METHOD /path = exact path
],
```
Forms: `/p` (exact or sub-path), `/p/` (prefix), `METHOD /p` (that method, exact path),
`{param}` (one non-empty segment, like the Router).
A `cancelled` subscription stays active until its `expires_at` (cancel-at-period-end).
In `block` mode the state header says `blocked`, not `read_only`.

**Behaviour change in v10:** earlier versions returned HTTP 402 on every call. Set
`'expired_mode' => 'block'` to keep that. See the CHANGELOG for details.

## Contributing to the framework

```bash
git clone https://github.com/progalaxyelabs/StoneScriptPHP.git
cd StoneScriptPHP
composer install
composer test
```

To test local framework changes against a real project without publishing a
release, point that project's `composer.json` at this checkout:

```json
{
    "repositories": [
        { "type": "path", "url": "../StoneScriptPHP", "options": {"symlink": false} }
    ],
    "require": { "progalaxyelabs/stonescriptphp": "@dev" }
}
```
then run `composer update progalaxyelabs/stonescriptphp` in that project.

## Versioning

[Semantic Versioning](https://semver.org/) — patch releases are safe to take
anytime, minor releases add backward-compatible features, major releases may
break things (read the migration guide first). Check
[Packagist](https://packagist.org/packages/progalaxyelabs/stonescriptphp) or this
repo's tags for the current version — don't rely on a hardcoded number in this
README, it goes stale.

## Documentation

The `stonescriptphp.org` marketing site doesn't have a `/docs` section live yet —
the documentation that exists today lives in this repo:

- [High-Level Design](HLD.md)
- [Framework contract / SPEC](SPEC.md)
- [Changelog](CHANGELOG.md)
- [stonescriptphp.org](https://stonescriptphp.org) — overview, features, ecosystem
- [stonescriptphp-server README](https://github.com/progalaxyelabs/StoneScriptPHP-Server#readme) — getting-started walkthrough for new projects

## Support

- [GitHub Issues](https://github.com/progalaxyelabs/StoneScriptPHP/issues)
- [stonescriptphp.org](https://stonescriptphp.org)

## License

MIT — see [LICENSE](LICENSE).
