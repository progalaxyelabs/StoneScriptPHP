CREATE TABLE IF NOT EXISTS analytics_events (
    id          BIGSERIAL PRIMARY KEY,
    event_name  VARCHAR(100)             NOT NULL,
    event_data  JSONB                    NOT NULL DEFAULT '{}',
    session_id  UUID,
    user_id     TEXT,
    tenant_id   TEXT,
    ip_address  INET,
    user_agent  TEXT,
    referrer    TEXT,
    created_at  TIMESTAMPTZ              NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_analytics_events_tenant    ON analytics_events(tenant_id);
CREATE INDEX IF NOT EXISTS idx_analytics_events_event     ON analytics_events(event_name);
CREATE INDEX IF NOT EXISTS idx_analytics_events_session   ON analytics_events(session_id);
CREATE INDEX IF NOT EXISTS idx_analytics_events_created   ON analytics_events(created_at);
