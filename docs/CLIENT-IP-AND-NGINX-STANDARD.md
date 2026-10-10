# Client IP and nginx: the standard (framework 11.0.0+)

Status: standard v1 (2026-10-10). Owner of the code: `StoneScriptPHP\Http\ClientIp`.

## 1. The rule in one paragraph

PHP trusts the TCP peer (`REMOTE_ADDR`) and nothing else, **unless** the peer is listed in
`TRUSTED_PROXIES`. Only then is `X-Forwarded-For` consulted, walked **right to left**, and
the first hop that is not itself a trusted proxy is the client. `X-Real-IP` is never read.
A client can therefore never choose its own IP by sending a header.

Everything IP-keyed in the framework goes through `client_ip()` -> `ClientIp::current()`:
RateLimiter, RateLimitMiddleware, CsrfTokenHandler, HCaptchaVerifier / HCaptchaMiddleware,
ProofOfWorkMiddleware, RefreshRoute, PostTrackEventRoute (analytics), Logger, RequestLogger.
Platforms must call `client_ip()` (or `ClientIp::resolve()` for tests) and never read
`$_SERVER['HTTP_X_FORWARDED_FOR']` / `HTTP_X_REAL_IP` themselves.

## 2. PHP configuration

| Where | Form | Notes |
|---|---|---|
| env `TRUSTED_PROXIES` | `10.0.0.5, 172.18.0.0/16` | comma/space list of IP, CIDR, or the keyword `private` |
| `Application::run()` config key `trusted_proxies` | array or string | wins over env; `[]` = trust nobody |
| default | empty | `REMOTE_ADDR` only |

`private` = loopback, RFC1918, CGNAT 100.64/10, link-local, IPv6 ULA (`fc00::/7`, `fe80::/10`).
`*`, `0.0.0.0/0`, `::/0` and malformed entries are rejected and logged: trusting everybody is
exactly the bug this standard removes.

Legacy `request_logging.trust_proxy` / `TRUST_PROXY=true` is deprecated: it is mapped to
`TRUSTED_PROXIES=private` with a log notice.

Misconfiguration hint: if the peer is private/loopback, the request carries `X-Forwarded-For`
and no trusted proxies are configured, the framework logs (once a minute) that every visitor
now shares one rate-limit bucket. That is the signature of "put behind a proxy, forgot
`TRUSTED_PROXIES`".

## 3. nginx standard

There are two nginx topologies. Each has one job.

### 3a. Edge nginx (terminates the public connection; nothing in front of it)

Applies to: every nginx that faces the public internet directly.

1. `REMOTE_ADDR` must be the real client. With `fastcgi_params` (`fastcgi_param REMOTE_ADDR $remote_addr;`)
   and no `real_ip`/`proxy_protocol` directives this is automatic. **Do not add `set_real_ip_from`
   on an edge nginx**; there is no trusted hop to take the address from.
2. Never forward a client-supplied forwarding header. For `proxy_pass` locations, **overwrite**:
   ```
   proxy_set_header X-Forwarded-For $remote_addr;     # NOT $proxy_add_x_forwarded_for
   proxy_set_header X-Real-IP       $remote_addr;
   proxy_set_header X-Forwarded-Proto $scheme;
   ```
   (`$proxy_add_x_forwarded_for` appends to whatever the client sent; downstream code then has to
   know to walk right to left. Overwriting makes even naive consumers safe.)
3. For `fastcgi_pass` locations add defence in depth in your shared fastcgi snippet, so a forged header never even reaches PHP:
   ```
   fastcgi_param HTTP_X_FORWARDED_FOR "";
   fastcgi_param HTTP_X_REAL_IP       "";
   ```
   PHP needs `TRUSTED_PROXIES` **unset** in this topology (the peer *is* the client).
4. nginx's own limits (`limit_req_zone`) must key on `$binary_remote_addr`.

### 3b. Inner nginx / PHP behind another proxy (container behind a host proxy, Traefik, a load balancer)

Applies to: containers behind a host proxy (host proxy -> container nginx -> php-fpm),
and any platform behind a load balancer or CDN.

1. The **outer** proxy follows 3a (overwrite XFF with the client address it saw).
2. The inner nginx passes headers through unchanged (it must not rewrite `REMOTE_ADDR` with `real_ip`;
   one mechanism only: the framework does the trust decision).
3. PHP sets `TRUSTED_PROXIES` to the outer proxy: its address, its CIDR, or `private` when the proxy
   is on a private docker/VNet range (the usual case). Never a public range you do not own.
4. If the platform cannot name its proxy, `TRUSTED_PROXIES` stays empty: the result is the proxy's
   address (safe, but one shared bucket); the framework logs the hint from section 2.

### 3c. Other services behind the edge nginx

Backend services (Node, etc.) receive the forwarding headers from 3a. Once 3a.2 (overwrite) is in place the
value is trustworthy, provided the service listens on loopback/private only.

## 4. Verification (do this after any nginx or TRUSTED_PROXIES change)

1. From outside, `curl -H 'X-Forwarded-For: 1.2.3.4' https://<host>/<route that echoes or logs client_ip>`:
   the logged/rate-keyed IP must be your real egress IP, never `1.2.3.4`.
2. Hit a rate-limited route N+1 times with a different forged XFF each time: the N+1th must be limited.
3. Behind a proxy: the logged IP must be the visitor, not the proxy. If it is the proxy, set `TRUSTED_PROXIES`.
