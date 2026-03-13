CREATE OR REPLACE FUNCTION sub_get_plan(p_platform_code TEXT, p_plan_code TEXT)
RETURNS JSON
LANGUAGE plpgsql
AS $$
DECLARE
    v_plan RECORD;
BEGIN
    SELECT id, platform_code, plan_code, display_name, amount_cents, currency, duration_days
    INTO v_plan
    FROM subscription_plans
    WHERE platform_code = p_platform_code AND plan_code = p_plan_code AND is_active = true;

    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    RETURN json_build_object(
        'id', v_plan.id,
        'platform_code', v_plan.platform_code,
        'plan_code', v_plan.plan_code,
        'display_name', v_plan.display_name,
        'amount_cents', v_plan.amount_cents,
        'currency', v_plan.currency,
        'duration_days', v_plan.duration_days
    );
END;
$$;
