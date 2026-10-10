# Client IP and nginx: the standard (framework 11.0.0+)

Code: `StoneScriptPHP\Http\ClientIp`. Entry point for application code: `client_ip()`.

## 0. Upgrade checklist (read first)

**Set `TRUSTED_PROXIES` first, then deploy 11.x, if anything sits between the internet and PHP.**
A platform that is behind a proxy (container behind a host proxy, load balancer, CDN, Traefik) and
upgrades without `TRUSTED_PROXIES` sees the **proxy's address for every visitor**. All visitors then
share one rate-limit bucket: after the first few requests the whole site answers HTTP 429. That is an
availability outage, not a cosmetic change. The framework logs a hint (`TRUSTED_PROXIES is not set`),
but only after the fact.

1. Do you terminate TLS in nginx on the same host as php-fpm, with nothing in front? Then do nothing
   (leave `TRUSTED_PROXIES` unset). `REMOTE_ADDR` is the visitor.
2. Anything in front? Set `TRUSTED_PROXIES` to that proxy (explicit CIDR) **before** deploying.
3. Search your code for `HTTP_X_FORWARDED_FOR`, `HTTP_X_REAL_IP`, `trust_proxy`, `TRUST_PROXY`; replace
   with `client_ip()` / `TRUSTED_PROXIES`.
4. Run the checks in section 5.

## 1. The rule

PHP trusts the TCP peer (`REMOTE_ADDR`) and nothing else, **unless** the peer is listed in
`TRUSTED_PROXIES`. Only then is `X-Forwarded-For` consulted, walked **right to left**; the first hop that
is not itself a trusted proxy is the client. `X-Real-IP` is never read. A client can never choose its
own IP by sending a header.

Everything IP-keyed in the framework goes through `client_ip()`: RateLimiter, RateLimitMiddleware,
CsrfTokenHandler, HCaptchaVerifier / HCaptchaMiddleware, ProofOfWorkMiddleware, RefreshRoute,
PostTrackEventRoute (analytics), Logger, RequestLogger. Application code must call `client_ip()` and never
read `$_SERVER['HTTP_X_FORWARDED_FOR']` / `HTTP_X_REAL_IP` itself.

Output is canonical: lowercase compressed IPv6, IPv4-mapped IPv6 as IPv4, so allow/deny lists and
fingerprints see one spelling. Hops may be `ip`, `ipv4:port`, `[ipv6]` or `[ipv6]:port` (some load balancers add
ports); zone ids and anything else are invalid and make the walk fall back to the peer (never guess).

## 2. PHP configuration

| Where | Form | Notes |
|---|---|---|
| env / `.env` `TRUSTED_PROXIES` | `10.0.0.5, 172.18.0.0/16` | comma/space list of IP, CIDR, or the keyword `private` |
| `TRUSTED_PROXIES_FILE` or `/run/secrets/trusted_proxies` | same contents | via `Env::secret()` |
| `Application::run()` config key `trusted_proxies` | array or string | wins over env; `[]` = trust nobody |
| default | empty | `REMOTE_ADDR` only |

Precedence: `ClientIp::configure()` > config `trusted_proxies` > `TRUSTED_PROXIES` > legacy
`trust_proxy` / `TRUST_PROXY` (deprecated, = `private`). A legacy switch never overrides an explicit
`TRUSTED_PROXIES`, and an explicit but entirely invalid `TRUSTED_PROXIES` does not fall back to it.

The value is read through the framework `Env` accessor, lazily on first use, so it always sees the fully
loaded `.env`. With php-fpm `clear_env = yes` (the default) real environment variables are stripped;
`.env`, the file/secret forms above, or a pool `env[TRUSTED_PROXIES] = ...` directive all still work.

Process model: the list is resolved once per PHP process and memoised. That is correct for php-fpm (per worker,
refreshed when workers restart or the pool reloads) and for long-lived workers (Swoole, RoadRunner, FrankenPHP
worker mode), but a changed `TRUSTED_PROXIES` only takes effect after the worker restarts / reloads. If `Env`
cannot be read at all, the framework logs it (once a minute) and trusts no proxy.

### Validation
`*`, `/0` and any prefix shorter than **public IPv4 /12** or **IPv6 /32** are rejected and logged. Rationale: a
trust entry names proxies, not other people's networks. The widest range any major CDN publishes is /13
(Cloudflare, verified 2026-10-10); a /12 floor leaves one bit of margin, so a public entry shorter than /12
cannot be a proxy fleet (public /8s to /11s are refused); /32 is a typical IPv6 ISP/organisation allocation. Blocks inside private
space (`10.0.0.0/8`, `fc00::/7`, `fe80::/10`, ...) are exempt from the floor because they are not
internet-routable. IPv4-mapped IPv6 entries (`::ffff:10.0.0.5`) are normalised to IPv4 so they can match.

### WARNING: the `private` keyword
`private` (loopback, RFC1918, CGNAT 100.64/10, link-local, IPv6 ULA) is **only safe when the backend can be
reached solely by the proxy**. It becomes "trust every visitor" when:
- the backend port is published on a host interface (e.g. docker `-p 9000:9000`),
- docker's userland-proxy rewrites public clients to the bridge gateway address,
- several tenants/containers share one docker bridge and any of them (or a published port) can talk to the backend,
- any other path lets a public client arrive from a private source address.

In all those cases a forged `X-Forwarded-For` is honoured and the protection is void. **Prefer an explicit
CIDR for the one proxy** (for example the specific bridge subnet, or the proxy's `/32`). Use `private` only
for the single-tenant, loopback-only or private-VNet-only case where you have verified the above.

### The `unknown` bucket
No usable `REMOTE_ADDR` (CLI, odd SAPI) yields `unknown`. All such requests deliberately share one
rate-limit bucket (fail closed: limited together, never bypassing). On an HTTP request this signals a broken
SAPI/web-server setup and is logged (throttled to once a minute).

### Rate keys and allow/deny lists
Rate-limit buckets use `ClientIp::rateKey()`: IPv4 as is, IPv6 collapsed to its /64. `RateLimiter::addToBlacklist()`
of an IP uses the same key (an IPv6 entry blocks the whole /64); `addToWhitelist()` of an IP matches only that exact
canonical address (a whitelist must stay narrow). CSRF fingerprints use `ClientIp::networkPrefix()`
(IPv4 /24, IPv6 /64).

## 3. nginx standard

Two topologies. Each has one job.

### 3a. Edge nginx (faces the internet directly; nothing in front of it)

1. `REMOTE_ADDR` must be the real client. With the stock `fastcgi_params`
   (`fastcgi_param REMOTE_ADDR $remote_addr;`) and no `real_ip` / `proxy_protocol` directives this is
   automatic. **Do not add `set_real_ip_from` on an edge nginx**: there is no trusted hop to take it from.
2. **Never pass a client-supplied forwarding header on to the application, on EITHER protocol.** Both of these
   are mandatory on every edge vhost, and **they are rollout step 1** while any application behind that
   nginx is still on a framework older than 11 (those versions trust the raw leftmost `X-Forwarded-For`):

   **fastcgi (PHP) locations** - the client's raw header otherwise arrives in PHP as `$_SERVER['HTTP_X_FORWARDED_FOR']`
   (httpoxy-style fix: overwrite the CGI variable, never let the client's value through):
   ```
   fastcgi_param HTTP_X_FORWARDED_FOR "";           # or: $remote_addr
   fastcgi_param HTTP_X_REAL_IP       "";           # or: $remote_addr
   ```
   Blanking is the safe default for an edge that faces visitors directly. Use `$remote_addr` instead if a legacy
   application needs a populated value.

   **proxy_pass locations** - **overwrite**:
   ```
   proxy_set_header X-Forwarded-For $remote_addr;     # NOT $proxy_add_x_forwarded_for
   proxy_set_header X-Real-IP       $remote_addr;
   proxy_set_header X-Forwarded-Proto $scheme;
   ```
   `$proxy_add_x_forwarded_for` appends to whatever the client sent, so every downstream consumer has to know to
   walk right to left. Overwriting makes even naive consumers safe.

   With framework 11 the PHP side no longer depends on these (it ignores the header unless the peer is a trusted
   proxy), so after all applications are on 11 the fastcgi lines become defence in depth. Until then they are the
   protection.
3. **Never put the fastcgi blanking lines (or `= $remote_addr` variants) in a snippet that an inner nginx (3b)
   includes**: they would erase the very header the framework needs, and every visitor would collapse into the
   outer proxy's rate-limit bucket.
4. nginx's own limits (`limit_req_zone`) must key on `$binary_remote_addr`.

### 3b. Inner nginx / PHP behind another proxy (container behind a host proxy, load balancer, Traefik)

1. The **outer** proxy follows 3a (overwrite `X-Forwarded-For` with the address it saw).
2. The inner nginx passes headers through unchanged and does not use `real_ip` (one mechanism only: the
   framework makes the trust decision).
3. PHP sets `TRUSTED_PROXIES` to the outer proxy. **An explicit CIDR of that one proxy** (see the `private`
   warning in section 2). Never a public range you do not own.
4. If the platform cannot name its proxy, leave `TRUSTED_PROXIES` empty: the result is the proxy's address
   (safe, but one shared bucket, see section 0).

### 3c. CDN / managed edge (Cloudflare, Azure Front Door, CloudFront, ...)

- Never `private`. The CDN connects from **public** addresses.
- `TRUSTED_PROXIES` = the CDN's **published** egress ranges (fetch them from the vendor, and refresh them on
  their change notices; they change).
- Take the client from the CDN's own header, which the CDN sets and overwrites, rather than a generic XFF
  chain: Cloudflare `CF-Connecting-IP`, Front Door `X-Azure-ClientIP` / `X-Forwarded-For`, CloudFront
  `CloudFront-Viewer-Address`. Have the origin nginx translate it to a single overwritten `X-Forwarded-For`
  (`proxy_set_header` / `fastcgi_param HTTP_X_FORWARDED_FOR $http_cf_connecting_ip;`) **and** firewall the
  origin so only the CDN ranges can reach it. If the origin is reachable directly, a client can bypass the
  CDN and send anything, so trust in those headers is void.

### 3d. Other services behind the edge nginx
Backend services (Node, etc.) receive the forwarding headers from 3a. After the overwrite in 3a.2 the value is
trustworthy, provided the service listens on loopback/private only.

## 4. Verification (after any nginx or `TRUSTED_PROXIES` change)

1. From outside: `curl -H 'X-Forwarded-For: 1.2.3.4' https://<host>/<route that logs client_ip()>`: the
   logged / rate-keyed IP must be your real egress address, never `1.2.3.4`.
2. Hit a rate-limited route N+1 times with a different forged `X-Forwarded-For` each time: the N+1th must be limited.
3. Behind a proxy: the logged IP must be the visitor, not the proxy. If it is the proxy, set `TRUSTED_PROXIES`.
