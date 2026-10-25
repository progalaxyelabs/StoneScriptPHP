# Upgrading to 13.2.0

No code changes are needed. Two things to do per platform:

1. **Apply the new schema function and index.** `composer update` stages `rl_purge_request_logs.pgsql` into
   `src/postgresql/vendor/` (or copy it from `vendor/progalaxyelabs/stonescriptphp/src/RequestLogging/Schema/functions/`),
   then run `php stone gateway:migrate-vendor-main`. Additive and idempotent. Without it the purge command exits 1.
2. **Install the retention timer** (templates in `contrib/systemd/`, steps in `docs/REQUEST-LOGGING.md`). Until you do,
   `request_logs` still grows without bound. Set `REQUEST_LOG_RETENTION_DAYS` if 30 days is not what you want.

Behaviour changes that need no action: successful `/health`, `/healthz` and `HEAD` requests are no longer written to
`request_logs`; the log write has a 1 s connect / 2 s total timeout and a 30 s per-worker cooldown after a failure
(previously 10 s / 30 s with no breaker). To keep the old probe rows set `request_logging.skip_paths` to `[]`.

First run on a platform with a large backlog: `php stone request-logs:purge` works through up to 10 million rows per
run (`--batch` x `--max-batches`) in small transactions; run it once by hand, off-peak, or let the nightly timer catch up.
