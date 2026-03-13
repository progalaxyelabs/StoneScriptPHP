CREATE OR REPLACE FUNCTION sub_get_status(p_tenant_id TEXT)
RETURNS JSON LANGUAGE plpgsql AS $$
DECLARE
    v_sub RECORD;
    v_is_active BOOLEAN;
    v_days_remaining INTEGER;
BEGIN
    SELECT * INTO v_sub FROM subscriptions WHERE tenant_id = p_tenant_id;
    IF NOT FOUND THEN RETURN NULL; END IF;
    v_is_active := v_sub.expires_at > NOW() AND v_sub.status NOT IN ('cancelled');
    v_days_remaining := GREATEST(0, EXTRACT(DAY FROM (v_sub.expires_at - NOW()))::INTEGER);
    RETURN json_build_object(
        'id', v_sub.id, 'platform_code', v_sub.platform_code,
        'tenant_id', v_sub.tenant_id, 'owner_email', v_sub.owner_email,
        'plan_code', v_sub.plan_code, 'status', v_sub.status,
        'is_trial', (v_sub.status = 'trial'), 'is_active', v_is_active,
        'days_remaining', v_days_remaining, 'started_at', v_sub.started_at,
        'expires_at', v_sub.expires_at, 'created_at', v_sub.created_at
    );
END; $$;
