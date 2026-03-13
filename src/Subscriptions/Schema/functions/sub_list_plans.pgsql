CREATE OR REPLACE FUNCTION sub_list_plans(p_platform_code TEXT)
RETURNS JSON
LANGUAGE plpgsql
AS $$
DECLARE
    v_result JSON;
BEGIN
    SELECT json_agg(row_to_json(p))
    INTO v_result
    FROM (
        SELECT id, platform_code, plan_code, display_name, amount_cents, currency, duration_days
        FROM subscription_plans
        WHERE platform_code = p_platform_code AND is_active = true
        ORDER BY amount_cents ASC
    ) p;

    RETURN COALESCE(v_result, '[]'::json);
END;
$$;
