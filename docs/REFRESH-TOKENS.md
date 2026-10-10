# Refresh-token persistence, rotation, reuse detection, revocation

A refresh token is only as revocable as the database row behind it. The framework now ships the row.

## Pieces

| Piece | What |
|---|---|
| `src/Auth/RefreshTokens/Schema` | vendor schema: table `auth_refresh_tokens`, functions `auth_rt_*` (staged by `composer install`, activated with `php stone gateway:migrate-vendor-main`; idempotent) |
| `PostgresRefreshTokenStore` | `RotatingRefreshTokenStore` over that schema; always talks to the MAIN database; accepts only SHA-256 hex digests |
| `InMemoryRefreshTokenStore` | reference semantics + tests |
| `RefreshTokenIssuer` | the ONE mint/persist/rotate/revoke service |
| `BuiltinOAuth` callback | mints through the issuer when one is configured |
| `RefreshTokenMiddleware` | detects replay of a spent token and revokes the family (rotating stores) |
| `Routes\BodyRefreshRoute` / `BodyLogoutRoute` | optional body-mode refresh/logout handlers |
| `php stone auth:purge-refresh-tokens` | batched expiry purge (schedule it) |

## Configure

`Application::run()` config `auth.refresh_tokens`, or env:

| key | env | default |
|---|---|---|
| `store` (`postgres` \| `none` \| a `RefreshTokenStore`) | `REFRESH_TOKEN_STORE` | `none` (12.x: tokens minted but NOT persisted) |
| `rotate` | `REFRESH_TOKEN_ROTATE` | `false` |
| `reuse_grace_seconds` | `REFRESH_TOKEN_REUSE_GRACE_SECONDS` | `10` |
| `claims_provider` `fn(array $claims): ?array` | | reuse verified claims; return null = subject gone |

`RefreshTokenIssuer::configured()` returns the issuer so a platform's own login/exchange route calls
`$issuer->issueSession($claims, TokenClaims::PURPOSE_AUTHENTICATION|AUTHORIZATION)` instead of
`generateToken()` + `store()`. The generated email-password / mobile-otp routes do this when configured.

## Before you enable it

1. `composer install` stages the schema into `src/postgresql/vendor/`; review it, then run
   **`php stone gateway:migrate-vendor-main` BEFORE setting `REFRESH_TOKEN_STORE=postgres`**.
2. `php stone auth:check-refresh-store` (exit 1 = not ready) in the deploy pipeline; `RefreshTokenIssuer::healthCheck()`
   for a `/health` route. The first use in each process also probes the schema once and fails loudly
   ("run migrate-vendor-main") instead of every sign-in dying with an opaque database error.
3. Schedule `php stone auth:purge-refresh-tokens` (hourly/daily).

## Security properties

- **Hashed at rest.** Only `sha256(token)` is stored or accepted; a raw JWT is rejected before the database.
- **Unique tokens.** Every refresh token carries a random `jti`, so two mints in one second never collide.
- **Fail closed at mint.** If the row cannot be written, no refresh token is returned and the sign-in aborts.
- **Tenant isolation.** A user id is only unique inside its tenant, so the stored subject is tenant-qualified
  (`{tenant}#{user}`; the bare id for a tenant-less platform; a global `identity_id` as-is).
  `issueSession()` THROWS when the claims carry a `tenant_id` but no global `identity_id` and no explicit subject:
  pass `RefreshTokenIssuer::qualifiedSubject($userId, $tenantId)`. Revoke with
  `revokeAllForUser($userId, $tenantId)`: user 5 of tenant A never logs out user 5 of tenant B.
- **Rotation + reuse detection** (RFC 9700 4.14.2, RFC 6819 5.2.2.3): a refresh swaps the token for a successor in
  the same session family; the old token becomes a tombstone. Presenting a spent token outside the grace window
  deletes the whole family atomically (inside one SQL call) and raises an alert log.
- **Grace is bounded to ONE extra exchange.** A client that lost the first response (or two tabs refreshing
  together) may exchange a spent token once more within the grace window (default 10 s); the second extra use is
  treated as reuse and kills the session. So undetected parallel use of a stolen token is bounded to one exchange.
- **Absolute session cap.** `session_max_seconds` / `REFRESH_TOKEN_SESSION_MAX_SECONDS` (default 180 days) is
  fixed at sign-in and never slides: successors are capped at it, and a session past it is expired however
  recently it was refreshed.
- **Claims refresh.** The claims inside a refresh token are frozen at sign-in unless you supply `claims_provider`
  (called on EVERY refresh, rotating or not, with the verified claims; return the fresh claims, or null when the user is
  gone to reject the refresh). Use it to pick up role/plan changes and deleted users.
- **Revocation.** `revokeSession(token)` (logout: whole family), `revokeFamily`, `revokeAllForSubject`/`revokeAllForUser`,
  all hard deletes. **Call `revokeAllForUser` on password reset/change (the generated reset template does) and on
  identity/account deletion**: unlike a platform table with `REFERENCES users ... ON DELETE CASCADE`, this table has
  no foreign key to your users table, so deleting a user does not remove their rows by itself.
- **Expiry purge.** Expired rows (token or session cap) are deleted by `php stone auth:purge-refresh-tokens` (batched,
  resumable, `FOR UPDATE SKIP LOCKED`: concurrent purges/reaps never wait on each other) and opportunistically
  (<= 25 rows) on every store.
- **Privacy.** Metadata keeps only a network prefix (IPv4 /24, IPv6 /64) and a truncated user agent, never the full IP.
  Subject ids are opaque.
- **Cookie mode.** The framework's `RefreshRoute` / `LogoutRoute` use the issuer when one is configured: refresh rotates
  (the cookie carries the successor) with replay detection; logout ends the session (and `revoke_all=1` revokes the user's
  sessions in this tenant).

## OAuth popup: tokens go only to allowed origins

The builtin Google callback used to `postMessage(data, '*')` to its opener, so ANY page that opened the initiate URL in
a popup could receive a victim's access and refresh tokens. Now the page posts only to allowed origins: the platform's
`ALLOWED_ORIGINS` (the same list CORS uses) or `allowed_origins` passed to `GoogleOAuthRoutes::register()`. The initiate
route additionally binds the OAuth `state` to the opener's origin (from `Referer`) and refuses a present-but-unlisted
origin before it ever reaches Google; the callback then posts to that single origin. An opener that is not allowed gets
nothing; with no allowed origin configured nothing is posted (fail closed, logged). Wildcards are ignored. The request's
own origin is always allowed (same-origin deployments need no configuration). When the opener is refused the popup shows a
visible configuration message naming `ALLOWED_ORIGINS` (never tokens) and `register()` logs an error at boot; the client
library should time the popup out with an honest message. Run `php stone auth:check-origins` before deploying.

**The `Referer` is a hint only.** A client can strip or (for its own requests) forge it. It is used for an early, clear
refusal and to narrow the target; the lock itself rests on the allowed list and on the browser, which delivers a
`postMessage` only to a window whose real origin equals the target origin.
**Upgrade:** make sure `ALLOWED_ORIGINS` contains every origin that hosts your sign-in page (it must already, for CORS).

## Rotation and clients

Rotation is off by default because the stock body-mode client keeps the refresh token it got at sign-in and only
reads `access_token` from the refresh response. With rotation on, that client's second refresh replays a spent
token and is logged out. Turn `rotate` on when the client stores the returned `refresh_token` (cookie-mode, or a
client updated to do so).

## Migrating a platform's own store (read-only verification of the two known platform stores)

Both existing platform stores hash exactly like this one: `hash('sha256', <raw refresh JWT>)` as lowercase hex, so
existing sessions can be carried over once with plain SQL (run after the vendor schema is applied; both tables live in
the same main database as `auth_refresh_tokens`). Existing tokens carry no `jti`, which does not matter for the hash.
Each copied token becomes its own one-token session family. Leave the old table in place (never DROP).

Platform store with columns `(token_hash, subject, purpose, expires_at, metadata jsonb, created_on)` and a plain user id as subject:

```sql
INSERT INTO auth_refresh_tokens (token_hash, family_id, subject, purpose, issued_at, expires_at, session_expires_at, metadata)
SELECT token_hash, gen_random_uuid(), subject, purpose, created_on, expires_at,
       LEAST(expires_at, now() + interval '180 days'), metadata
FROM refresh_tokens WHERE expires_at > now()
ON CONFLICT (token_hash) DO NOTHING;
```

Platform store with columns `(token_hash, subject uuid, purpose, expires_at, created_at, ip_address, user_agent)`:

```sql
INSERT INTO auth_refresh_tokens (token_hash, family_id, subject, purpose, issued_at, expires_at, session_expires_at, metadata)
SELECT token_hash, gen_random_uuid(), subject::text, purpose, COALESCE(created_at, now()), expires_at,
       LEAST(expires_at, now() + interval '180 days'),
       jsonb_strip_nulls(jsonb_build_object('user_agent', left(user_agent, 255)))
FROM refresh_tokens WHERE expires_at > now()
ON CONFLICT (token_hash) DO NOTHING;
```

(The old `ip_address` is deliberately not copied: full addresses are not kept.) The old per-user subject format must equal
what the new code stores: a tenant-less platform stores the bare id (matches both); a multi-tenant platform must instead
pass `qualifiedSubject($userId, $tenantId)` (rows copied from a per-tenant-id store would need their subject rewritten
to `{tenant}#{id}`; if unsure, skip the copy: users simply sign in again).
