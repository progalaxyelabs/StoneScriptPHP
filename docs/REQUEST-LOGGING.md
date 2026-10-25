# Request logging, retention and personal data (13.2)

`RequestLogger` writes one `request_logs` row per request to the platform's main database, after the response has
been sent to the client. It is on by default (`request_logging.enabled`).

## What a row holds

| Column | Content | Personal data? |
|---|---|---|
| `request_id` | `X-Request-Id` header if present, else a random UUIDv4 | no (unless a client puts one in the header) |
| `occurred_at`, `logged_at`, `duration_ms`, `status`, `method` | timing, HTTP status, verb | no |
| `platform_code`, `host` | which platform / virtual host | no |
| `path` | request path, **query string dropped**, long opaque segments masked (see below); may contain short ids | possibly (ids in path segments) |
| `client_ip` | spoof-safe client IP (`Http\ClientIp`) | **yes** (IP address) |
| `user_agent` | `User-Agent`, cut to 1000 chars | **yes** (device fingerprint) |
| `referer` | `Referer` with query string and fragment dropped, secret-looking path segments masked | possibly |
| `identity_id` | authenticated user id (UUID), NULL when unauthenticated | **yes** (pseudonymous user id) |
| `tenant_id` | tenant (organisation) UUID | no (organisation, not a person) |
| `role` | role of the authenticated user | no |
| `error_class`, `error_message` | failure class + a length-limited, sanitised message (`FailureText`); only for failures | should not, but is free text: treat as possibly |

No request or response body, no headers other than those above, no cookies, no tokens, no query strings are stored.

## Secrets in URL paths are masked

Before `path` (and the referer path, and any framework log line that prints a request path) is stored, opaque segments are
replaced with `{redacted}` by `PathScrubber`: 20+ characters of `[A-Za-z0-9_-]` that are not purely digits, not a UUID and not a
readable kebab/snake word list (`/invitations/<token>` -> `/invitations/{redacted}`), and hex runs of 32+ characters. Short ids,
numeric ids (`/api/workspaces/123/status`), UUIDs and route words are untouched. Add your own rules with
`PathScrubber::addPattern('~/promo/[^/]+~')` or `request_logging.path_scrub_patterns` (list of PCRE patterns; the match becomes
the marker; invalid patterns are ignored). Rows written by earlier versions may hold raw tokens: they age out with retention.
Prefer not to carry secrets in paths at all; this is the safety net.

## Retention (default 30 days)

Rows older than the window are deleted by `php stone request-logs:purge`, which calls the framework-shipped main
schema function `rl_purge_request_logs(p_older_than_days, p_batch)` in a loop (many short transactions, resumable,
`SKIP LOCKED`, no DDL). The window is `--days=N`, else `REQUEST_LOG_RETENTION_DAYS`, else **30**. A value that is not
a whole number >= 1 falls back to 30 and the SQL function refuses `< 1`, so a bad setting can never mean "delete
everything". An index on `occurred_at` (`idx_request_logs_occurred_at`) makes the purge cheap.

Options: `--days`, `--batch` (default 5000), `--max-batches` (default 2000 per run; the next run continues),
`--sleep-ms` (default 100 between batches). Exit code 0 = ran, 1 = could not run.

The purge does not run by itself. Install the timer (below) on every platform.

### Install the timer

Templates ship in `contrib/systemd/` (`vendor/progalaxyelabs/stonescriptphp/contrib/systemd/`):

```
cp vendor/progalaxyelabs/stonescriptphp/contrib/systemd/stonescriptphp-request-log-purge.service /etc/systemd/system/<app>-request-log-purge.service
cp vendor/progalaxyelabs/stonescriptphp/contrib/systemd/stonescriptphp-request-log-purge.timer   /etc/systemd/system/<app>-request-log-purge.timer
# edit User= and WorkingDirectory= in the .service
systemctl daemon-reload
systemctl enable --now <app>-request-log-purge.timer
systemctl list-timers '*request-log-purge*'
```

A platform with several apps on one host installs one pair per app (each has its own project root and database).

## Write latency and failure behaviour

The write happens after `fastcgi_finish_request()`, but the php-fpm worker is still occupied until it returns, so it is
bounded: connect timeout 1 s, total 2 s (whole seconds, clamped to 1..10). Configure with
`request_logging.connect_timeout_seconds` / `write_timeout_seconds` or `REQUEST_LOG_CONNECT_TIMEOUT` /
`REQUEST_LOG_WRITE_TIMEOUT`. It fails open. After 3 consecutive failed writes the worker skips success-row writes (failure rows are still attempted, one probe per 5 s) for
`request_logging.failure_cooldown_seconds` (default 30; `REQUEST_LOG_FAILURE_COOLDOWN`), and reports **one**
summarized error line per minute per worker (with the number of failed and skipped writes), never one per request.

## Probes are not logged

A request that **succeeded** (status < 400, no recorded error) is not logged when it is a `HEAD` request or its path
equals, or is under, one of `request_logging.skip_paths` (default `/health`, `/healthz`; or comma list
`REQUEST_LOG_SKIP_PATHS`; an explicit empty list logs every path). Failures are always logged, including failed probes.
