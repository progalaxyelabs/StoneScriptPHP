CREATE OR REPLACE FUNCTION ana_insert_event(
    p_event_name  TEXT,
    p_event_data  TEXT,
    p_session_id  TEXT,
    p_user_id     TEXT,
    p_tenant_id   TEXT,
    p_ip_address  TEXT,
    p_user_agent  TEXT,
    p_referrer    TEXT
)
RETURNS BIGINT
LANGUAGE plpgsql
AS $$
DECLARE
    v_id BIGINT;
BEGIN
    INSERT INTO analytics_events (
        event_name,
        event_data,
        session_id,
        user_id,
        tenant_id,
        ip_address,
        user_agent,
        referrer
    ) VALUES (
        p_event_name,
        COALESCE(NULLIF(p_event_data, '')::JSONB, '{}'::JSONB),
        NULLIF(p_session_id, '')::UUID,
        NULLIF(p_user_id,    ''),
        NULLIF(p_tenant_id,  ''),
        NULLIF(p_ip_address, '')::INET,
        NULLIF(p_user_agent, ''),
        NULLIF(p_referrer,   '')
    )
    RETURNING id INTO v_id;

    RETURN v_id;
END;
$$;
