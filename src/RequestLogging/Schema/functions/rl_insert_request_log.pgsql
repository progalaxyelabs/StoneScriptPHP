-- rl_insert_request_log: insert one row into request_logs (§8 of request-logging spec)
--
-- All nullable UUID columns accept empty string in addition to NULL so that
-- PHP callers do not need to distinguish between null and '' when building
-- the params array passed to GatewayClient::callFunction().
--
-- Parameters follow the same order as the PHP row array in RequestLogger::writeLog().

CREATE OR REPLACE FUNCTION rl_insert_request_log(
    p_request_id    TEXT,
    p_occurred_at   TEXT,
    p_platform_code TEXT,
    p_host          TEXT,
    p_method        TEXT,
    p_path          TEXT,
    p_status        INTEGER,
    p_duration_ms   NUMERIC,
    p_client_ip     TEXT,
    p_identity_id   TEXT,
    p_tenant_id     TEXT,
    p_role          TEXT,
    p_error_class   TEXT,
    p_error_message TEXT,
    p_user_agent    TEXT,
    p_referer       TEXT
)
RETURNS BIGINT
LANGUAGE plpgsql
AS $$
DECLARE
    v_id BIGINT;
BEGIN
    INSERT INTO request_logs (
        request_id,
        occurred_at,
        platform_code,
        host,
        method,
        path,
        status,
        duration_ms,
        client_ip,
        identity_id,
        tenant_id,
        role,
        error_class,
        error_message,
        user_agent,
        referer
    ) VALUES (
        p_request_id,
        p_occurred_at::TIMESTAMPTZ,
        COALESCE(p_platform_code, ''),
        COALESCE(p_host, ''),
        COALESCE(p_method, ''),
        COALESCE(p_path, ''),
        p_status,
        p_duration_ms,
        COALESCE(p_client_ip, ''),
        NULLIF(p_identity_id, '')::UUID,
        NULLIF(p_tenant_id,   '')::UUID,
        NULLIF(p_role,        ''),
        NULLIF(p_error_class, ''),
        NULLIF(p_error_message, ''),
        NULLIF(p_user_agent, ''),
        NULLIF(p_referer,    '')
    )
    RETURNING id INTO v_id;

    RETURN v_id;
END;
$$;
