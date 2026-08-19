-- webhook_quarantine — persist-raw safety net for inbound third-party webhooks
-- (payments, job callbacks, ...). See StoneScriptPHP\Webhooks\WebhookQuarantine.
--
-- Idempotent: IF NOT EXISTS on the table and every index — safe to re-run on
-- any tenant/main DB at any state (framework migration convention, see
-- StoneScriptPHP DEVELOPER.md §3.3.3).

CREATE TABLE IF NOT EXISTS webhook_quarantine (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    platform_code TEXT NOT NULL,
    source TEXT NOT NULL,           -- e.g. 'razorpay'
    event_type TEXT,                -- e.g. 'payment.captured', NULL if unparseable
    reason TEXT NOT NULL,           -- why this was quarantined (contract violation description)
    raw_headers JSONB,              -- inbound headers, secrets redacted (signature/authorization)
    raw_payload JSONB NOT NULL,     -- the verified-signature, contract-failing payload, UNTOUCHED
    status TEXT NOT NULL DEFAULT 'quarantined', -- quarantined | resolved
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    resolved_at TIMESTAMPTZ,
    resolved_by TEXT,
    resolution_note TEXT
);

CREATE INDEX IF NOT EXISTS webhook_quarantine_status_idx ON webhook_quarantine (status, created_at DESC);
CREATE INDEX IF NOT EXISTS webhook_quarantine_source_idx ON webhook_quarantine (source, platform_code);
