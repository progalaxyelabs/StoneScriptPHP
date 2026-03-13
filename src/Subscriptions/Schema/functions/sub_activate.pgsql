CREATE OR REPLACE FUNCTION sub_activate(
    p_platform_code VARCHAR,
    p_tenant_id TEXT,
    p_plan_code VARCHAR,
    p_duration_days INTEGER,
    p_payment_id VARCHAR DEFAULT NULL,
    p_payer_email VARCHAR DEFAULT NULL,
    p_payer_phone VARCHAR DEFAULT NULL,
    p_amount_cents INTEGER DEFAULT 0,
    p_payment_method VARCHAR DEFAULT NULL,
    p_raw_payload JSONB DEFAULT NULL
)
RETURNS JSON
LANGUAGE plpgsql
AS $$
DECLARE
    v_sub RECORD;
    v_new_expires TIMESTAMPTZ;
    v_payment_id INTEGER;
BEGIN
    -- Ensure subscription exists (create trial if not)
    PERFORM sub_get_status(p_platform_code, p_tenant_id, p_payer_email);

    -- Calculate new expiry: from NOW or from current expires_at if still active
    SELECT * INTO v_sub
    FROM subscriptions
    WHERE platform_code = p_platform_code
      AND tenant_id = p_tenant_id;

    IF v_sub.expires_at > NOW() AND v_sub.status = 'active' THEN
        -- Extend from current expiry
        v_new_expires := v_sub.expires_at + MAKE_INTERVAL(days => p_duration_days);
    ELSE
        -- Start fresh from now
        v_new_expires := NOW() + MAKE_INTERVAL(days => p_duration_days);
    END IF;

    -- Update subscription
    UPDATE subscriptions SET
        plan_code = p_plan_code,
        status = 'active',
        started_at = NOW(),
        expires_at = v_new_expires,
        updated_at = NOW(),
        owner_email = COALESCE(NULLIF(p_payer_email, ''), owner_email)
    WHERE id = v_sub.id
    RETURNING * INTO v_sub;

    -- Record payment if payment_id provided
    IF p_payment_id IS NOT NULL THEN
        INSERT INTO subscription_payments (
            subscription_id, platform_code, tenant_id,
            gateway_payment_id, amount_cents, currency,
            payer_email, payer_phone, payment_method,
            status, raw_payload
        ) VALUES (
            v_sub.id, p_platform_code, p_tenant_id,
            p_payment_id, p_amount_cents, 'INR',
            p_payer_email, p_payer_phone, p_payment_method,
            'captured', p_raw_payload
        )
        RETURNING id INTO v_payment_id;
    END IF;

    RETURN json_build_object(
        'subscription_id', v_sub.id,
        'platform_code', v_sub.platform_code,
        'tenant_id', v_sub.tenant_id,
        'plan_code', v_sub.plan_code,
        'status', v_sub.status,
        'is_active', true,
        'expires_at', v_sub.expires_at,
        'payment_id', v_payment_id,
        'activated_at', NOW()
    );
END;
$$;
