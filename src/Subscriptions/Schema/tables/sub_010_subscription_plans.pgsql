CREATE TABLE IF NOT EXISTS subscription_plans (
    id SERIAL PRIMARY KEY,
    platform_code VARCHAR(50) NOT NULL,
    plan_code VARCHAR(50) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    amount_cents INTEGER NOT NULL DEFAULT 0,
    currency VARCHAR(3) NOT NULL DEFAULT 'INR',
    duration_days INTEGER NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT true,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE(platform_code, plan_code)
);
