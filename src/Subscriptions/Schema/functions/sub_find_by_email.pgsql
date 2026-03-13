CREATE OR REPLACE FUNCTION sub_find_by_email(p_email TEXT)
RETURNS JSON
LANGUAGE plpgsql
AS $$
DECLARE
    v_sub RECORD;
BEGIN
    SELECT id, platform_code, tenant_id, owner_email, plan_code, status
    INTO v_sub
    FROM subscriptions
    WHERE LOWER(owner_email) = LOWER(p_email)
    ORDER BY created_at DESC
    LIMIT 1;

    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    RETURN json_build_object(
        'id', v_sub.id,
        'platform_code', v_sub.platform_code,
        'tenant_id', v_sub.tenant_id,
        'owner_email', v_sub.owner_email,
        'plan_code', v_sub.plan_code,
        'status', v_sub.status
    );
END;
$$;
