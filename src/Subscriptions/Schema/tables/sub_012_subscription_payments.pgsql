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

-- IDEMPOTENCY FIX (pay-primitive + sub_activate double-apply race,
-- 2026-09-17): the plain index above was NON-unique — nothing in the schema
-- prevented two rows recording the SAME gateway_payment_id. Combined with
-- sub_activate()'s old unlocked check-then-act body, a retried/concurrent
-- Razorpay webhook delivery (routine — Razorpay redelivers payment.captured
-- at-least-once on timeout) could extend a subscription's expiry twice and
-- record the same captured payment twice. This partial UNIQUE index is the
-- DB-level backstop half of the fix; sub_activate() (see that file's
-- docblock) now gates the expiry-extension side effect on winning THIS
-- insert, mirroring stonescriptphp-payments's pay_captured_payments /
-- pay_record_captured_payment() primitive (INSERT ... ON CONFLICT DO
-- NOTHING — no side effect of its own to roll back, so no advisory lock or
-- exception-savepoint machinery is needed here either).
--
-- Partial (WHERE gateway_payment_id IS NOT NULL): a subscription can be
-- activated with NO payment at all (admin activation, free trial start —
-- sub_activate()'s p_payment_id is optional), and those legitimately
-- NULL-payment rows must never collide with each other or be constrained by
-- this uniqueness rule — only a REAL gateway payment id must be unique.
--
-- 2026-09-21 RECONCILIATION (independent code review before this pass
-- shipped): the first draft of this index keyed on (payment_gateway,
-- gateway_payment_id). A deployed app had ALREADY
-- independently converged on a (platform_code, gateway_payment_id) key via
-- an earlier hotpatch, deployed before this framework-source fix
-- landed. Keying on payment_gateway added no real specificity (it is a
-- near-constant default) while risking two DIFFERENT unique constraints
-- coexisting on future installs that migrate from a hotpatched state —
-- `ON CONFLICT` only suppresses conflicts on the index it targets, so a
-- second, divergent unique index would raise an unhandled `unique_violation`
-- the moment its arbiter columns disagreed with the live data shape.
-- Reconciled to the (platform_code, gateway_payment_id) key + name already
-- proven in production, so `CREATE UNIQUE INDEX IF NOT EXISTS` is a
-- genuine no-op there instead of adding a second key.
--
-- Guard BEFORE constraining (2026-09-21, same review): `CREATE UNIQUE
-- INDEX IF NOT EXISTS` is NOT a no-op when duplicate data already exists —
-- it raises `unique_violation` and aborts. An install carrying the exact
-- duplicates this fix exists to prevent is precisely the install this
-- index would fail loudest against. Fail with a clear, actionable message
-- naming the offending rows rather than an opaque constraint-violation
-- deep in a schema-sync run.
DO $$
DECLARE
    v_dupe_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO v_dupe_count
    FROM (
        SELECT platform_code, gateway_payment_id
        FROM subscription_payments
        WHERE gateway_payment_id IS NOT NULL
        GROUP BY platform_code, gateway_payment_id
        HAVING COUNT(*) > 1
    ) dupes;

    IF v_dupe_count > 0 THEN
        RAISE EXCEPTION 'subscription_payments has % (platform_code, gateway_payment_id) group(s) with duplicate rows — uq_subscription_payments_gateway cannot be created until these are reconciled manually (see sub_012_subscription_payments.pgsql). Query: SELECT platform_code, gateway_payment_id, COUNT(*) FROM subscription_payments WHERE gateway_payment_id IS NOT NULL GROUP BY 1,2 HAVING COUNT(*) > 1;', v_dupe_count;
    END IF;
END $$;

CREATE UNIQUE INDEX IF NOT EXISTS uq_subscription_payments_gateway
    ON subscription_payments (platform_code, gateway_payment_id)
    WHERE gateway_payment_id IS NOT NULL;
