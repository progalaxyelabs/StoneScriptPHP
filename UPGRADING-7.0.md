# Upgrading to StoneScriptPHP 7.0.0

7.0.0 makes the **typed access/token_type route model** and the **typed-auth
middleware** the framework's single, strict auth model. There is no pre-7.0
fallback mode. Every platform with protected routes must migrate on upgrade or it
will **fail fast at boot** with an actionable error (it will not serve requests on
the old schema).

If you are upgrading only to pick up an unrelated fix (e.g. the CORS fail-closed
fix), you still must complete this migration — 7.0.0 is single-mode by design.

## What changed

- The `is_public` route boolean is superseded by `access` ∈
  `{public, authentication, authorization}` plus `token_type` ∈ `{access, refresh}`.
  `is_public=true` still resolves to `access=public`; a protected route with no
  explicit `access` is treated as `authorization`/`access`.
- Authentication is enforced by two middleware, split by credential type:
  - `AccessTokenMiddleware` — stateless Bearer access-token verification.
  - `RefreshTokenMiddleware` — stateful body refresh-token verification, gated on a
    `RefreshTokenStore` row (revoke = delete the row).
- `Application::run()` refuses to boot if protected routes exist but these middleware
  are not (fully) wired.

## Migration steps

### 1. Declare `access` / `token_type` on your routes

In `routes.php`, classify each route:

```php
return [
    'GET' => [
        '/health'                 => ['handler' => HealthRoute::class, 'access' => 'public'],
        '/api/warehouses'         => ['handler' => ListWarehouses::class,
                                      'access' => 'authorization', 'token_type' => 'access'],
    ],
    'POST' => [
        '/api/auth/exchange'      => ['handler' => ExchangeRoute::class,
                                      'access' => 'authentication', 'token_type' => 'access'],
        '/api/auth/refresh'       => ['handler' => RefreshRoute::class,
                                      'access' => 'authorization', 'token_type' => 'refresh'],
        '/api/auth/identity-refresh' => ['handler' => IdentityRefreshRoute::class,
                                      'access' => 'authentication', 'token_type' => 'refresh'],
        '/api/auth/logout'        => ['handler' => LogoutRoute::class,
                                      'access' => 'authentication', 'token_type' => 'refresh'],
    ],
];
```

Guidance:
- Open/pre-auth endpoints (health, home, JWKS, login, register, OAuth initiate) →
  `access: public`.
- Identity-bearer endpoints (exchange) → `authentication` + `access`.
- API-token/business endpoints → `authorization` + `access`.
- Refresh/logout endpoints (credential in the body) → `authentication` or
  `authorization` + `token_type: refresh` (NOT public — they carry a refresh token).

### 2. Wire both typed-auth middleware

```php
use StoneScriptPHP\Auth\Middleware\AuthMiddlewareRegistrar;
use StoneScriptPHP\Auth\TrustedIssuerVerifier;

$verifier = new TrustedIssuerVerifier([
    // builtin/standalone: identity + API token share one local key + iss
    'https://api.yourapp.in' => ['kind' => 'local', 'public_key_path' => '/keys/jwt-public.pem'],
    // federated identity (external mode): auth tokens via JWKS
    // 'https://auth.example.com' => ['kind' => 'jwks', 'jwks_url' => 'https://auth.example.com/auth/jwks'],
]);

$store = new YourRefreshTokenStore(/* PDO ... */); // implements RefreshTokenStore

$auth = AuthMiddlewareRegistrar::create($verifier, $store);

Application::run([
    'routes'     => require __DIR__ . '/routes.php',
    'middleware' => [...$auth],   // installs BOTH — you cannot get one without the other
    // ... rest of your config
]);
```

`create()` returns both middleware as a unit. Installing only one fails the boot
guard (half-wire error), because leaving one credential class unenforced would make
those routes unauthenticated.

### 3. Provide a `TrustedIssuerVerifier` and a `RefreshTokenStore`

- `TrustedIssuerVerifier` — the trusted issuer→key map. `iss` selects the key; an
  unknown/forged issuer is rejected. Local RSA key for builtin/card issuers, JWKS for
  federated identity issuers.
- `RefreshTokenStore` — persist **every** refresh token (identity AND card,
  discriminated by `purpose`); `exists()` gates refresh; `revoke()` deletes the row.
  See `InMemoryRefreshTokenStore` for the exact contract; back it with your database
  for production.

## Expected one-time effects

- **Forced re-login fleet-wide.** Refresh is now gated on a stored row; all
  previously-issued (rowless) refresh tokens are rejected once. Identity refresh
  tokens, never persisted before, must be written to the store at mint time.
- Strict token typing means an identity token can no longer be used on a card route
  (or vice-versa), even when signature + `iss` are valid.

## The boot error is your checklist

If you upgrade without migrating, `Application::run()` returns a controlled 500 whose
body/log is the migration checklist (see
`AuthMiddlewareRegistrar::migrationMessage()`). Follow it and re-boot.
