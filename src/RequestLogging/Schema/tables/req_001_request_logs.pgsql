-- request_logs: platform-level request log table (§8 of request-logging spec)
--
-- One row per HTTP request, written in the shutdown function after the response
-- has been sent to the client (fastcgi_finish_request). The row is self-sufficient
-- and works whether or not Traefik is in front of the app.
--
-- This file is delivered with the StoneScriptPHP framework. Platforms must copy
-- (or symlink) it into their postgresql/tables/ directory and run:
--
--   php stone migrate up
--
-- to install the table. The framework ships with a fail-open guard so the app
-- continues to serve requests even when this table has not been applied yet.
--
-- Gateway-managed: do NOT write raw DDL from application code.

CREATE TABLE IF NOT EXISTS request_logs (
    id           BIGSERIAL    PRIMARY KEY,
    request_id   TEXT         NOT NULL,
    occurred_at  TIMESTAMPTZ  NOT NULL,
    platform_code TEXT        NOT NULL DEFAULT '',
    host         TEXT         NOT NULL DEFAULT '',
    method       TEXT         NOT NULL DEFAULT '',
    path         TEXT         NOT NULL DEFAULT '',
    status       INTEGER      NOT NULL,
    duration_ms  NUMERIC(12,2) NOT NULL,
    client_ip    TEXT         NOT NULL DEFAULT '',
    identity_id  UUID         NULL,
    tenant_id    UUID         NULL,
    role         TEXT         NULL,
    error_class  TEXT         NULL,
    error_message TEXT        NULL,
    user_agent   TEXT         NULL,
    referer      TEXT         NULL,
    logged_at    TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- Indexes per §8
CREATE INDEX IF NOT EXISTS idx_request_logs_occurred_at        ON request_logs (occurred_at);
CREATE INDEX IF NOT EXISTS idx_request_logs_identity_id        ON request_logs (identity_id);
CREATE INDEX IF NOT EXISTS idx_request_logs_status             ON request_logs (status);
CREATE INDEX IF NOT EXISTS idx_request_logs_request_id         ON request_logs (request_id);
CREATE INDEX IF NOT EXISTS idx_request_logs_platform_occurred  ON request_logs (platform_code, occurred_at);
