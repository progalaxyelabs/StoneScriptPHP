CREATE TABLE IF NOT EXISTS subscription_payments (
    id SERIAL PRIMARY KEY,
    subscription_id INTEGER REFERENCES subscriptions(id),
    platform_code VARCHAR(50) NOT NULL,
    tenant_id TEXT NOT NULL,
    payment_gateway VARCHAR(50) NOT NULL DEFAULT 'razorpay',
    gateway_payment_id VARCHAR(100),
    amount_cents INTEGER NOT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'INR',
    payer_email VARCHAR(255),
    payer_phone VARCHAR(20),
    payment_method VARCHAR(50),
    status VARCHAR(50) NOT NULL DEFAULT 'captured',
    raw_payload JSONB,
    created_at TIMESTAMPTZ DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_subscription_payments_sub ON subscription_payments(subscription_id);
CREATE INDEX IF NOT EXISTS idx_subscription_payments_gateway ON subscription_payments(gateway_payment_id);
