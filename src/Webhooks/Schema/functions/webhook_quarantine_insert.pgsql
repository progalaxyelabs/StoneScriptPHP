-- webhook_quarantine_insert — insert one quarantined webhook envelope.
-- Called by StoneScriptPHP\Webhooks\WebhookQuarantine::quarantine().

CREATE OR REPLACE FUNCTION webhook_quarantine_insert(
    p_platform_code TEXT,
    p_source TEXT,
    p_event_type TEXT,
    p_reason TEXT,
    p_raw_headers JSON,
    p_raw_payload JSON
) RETURNS JSON LANGUAGE plpgsql AS $$
DECLARE
    v_id UUID;
BEGIN
    INSERT INTO webhook_quarantine (
        platform_code, source, event_type, reason, raw_headers, raw_payload
    ) VALUES (
        p_platform_code, p_source, p_event_type, p_reason,
        p_raw_headers::jsonb, p_raw_payload::jsonb
    )
    RETURNING id INTO v_id;

    RETURN json_build_object('id', v_id, 'status', 'quarantined');
END;
$$;
