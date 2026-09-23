-- package: progalaxyelabs/stonescriptphp v9.19.0
-- StoneScriptPHP :: Subscriptions :: functions :: sub_activate
--
-- 2026-09-17 FIX (task: safe-by-default idempotent payment-capture
-- primitive; this is the ecosystem's ONLY shipped payment example — every
-- auto-provisioned customer app inherits whatever this function does).
-- TWO independent defects fixed in this pass:
--
--   1. STALE 3-ARG CALL (a separate, pre-existing bug, confirmed live in
--      two of our own production apps on 2026-08-22 and hotpatched
--      directly on those DBs at the time — but NEVER fixed here at the
--      SOURCE, so every OTHER/future scaffolded app still shipped the
--      broken 3-arg `PERFORM sub_get_status(p_platform_code, p_tenant_id,
--      p_payer_email)` against a function that only ever took ONE arg
--      (`sub_get_status(p_tenant_id TEXT)`) — a hard "function does not
--      exist" error on EVERY real payment. Replaced with the idempotent
--      "ensure subscription exists" INSERT ... ON CONFLICT DO NOTHING the
--      original comment already promised, keyed on subscriptions'
--      own UNIQUE(platform_code, tenant_id).
--
--   2. DOUBLE-APPLY RACE (the task this pass exists for): the previous body
--      read the subscription with a plain, UNLOCKED SELECT, computed a new
--      expiry, then unconditionally UPDATEd and unconditionally INSERTed a
--      payment row — no lock, no ON CONFLICT, only a NON-unique index on
--      gateway_payment_id (see sub_012_subscription_payments.pgsql). A
--      retried/concurrent Razorpay webhook redelivery for the SAME
--      payment_id (Razorpay redelivers payment.captured at-least-once on
--      timeout — routine, not exotic) could extend the subscription twice
--      and record a duplicate payment row. Proven live via a discriminating
--      concurrency test: 15 simultaneous calls with the SAME payment_id
--      against the OLD shape produced 15 duplicate payment rows (see this
--      task's concurrency-test evidence).
--
-- FIX SHAPE — mirrors stonescriptphp-payments's canonical
-- pay_captured_payments / pay_record_captured_payment() primitive (this
-- function does NOT call into stonescriptphp-payments's SQL directly:
-- Subscriptions has ZERO required dependency on the `stonescriptphp-payments`
-- package — see
-- StoneScriptPHP\Billing\README.md's "the framework depends on no concrete
-- payment package" law — so a hard cross-package SQL call here would break
-- every Subscriptions-without-pay installation. `stonescriptphp-invoice`'s
-- own inv_payments/inv_record_payment already establishes the same
-- ecosystem convention: each consumer implements the SAME proven
-- idempotent-capture SHAPE independently in its own schema, not a live
-- runtime call into another package's function). Concretely:
--   - SELECT ... FOR UPDATE locks the subscription row FIRST, so two
--     DIFFERENT genuine payments for the same tenant (not just retries of
--     the SAME payment_id) serialize their expiry extensions correctly
--     instead of losing an update to a stale read.
--   - When a payment_id is given, the payment INSERT itself is the
--     idempotency gate: `INSERT ... ON CONFLICT (platform_code,
--     gateway_payment_id) WHERE gateway_payment_id IS NOT NULL DO NOTHING
--     RETURNING id`. Only a caller whose INSERT actually inserts a row
--     "wins" and gets to extend the subscription's expiry in this same
--     transaction; a caller that loses the race (0 rows returned) returns
--     the CURRENT (locked, unchanged-by-this-call) subscription state and
--     the EXISTING payment row's id — never a double-extend, never a
--     duplicate payment row, never a spurious exception for an entirely
--     normal webhook redelivery.
--   - No advisory lock / exception-savepoint needed on top of the above:
--     unlike a wallet-credit primitive that must be able to undo a side
--     effect applied BEFORE its own idempotency insert, this function's
--     only side effect (the expiry UPDATE) runs strictly AFTER the
--     payment insert has already won its gate, in the same transaction —
--     there is nothing left to unwind on the losing path.
--
-- 2026-09-21 HARDENING (independent code review before this pass shipped —
-- caught 4 production-breaking gaps in the first draft, fixed here rather
-- than shipped and discovered live):
--   - Conflict key changed from (payment_gateway, gateway_payment_id) to
--     (platform_code, gateway_payment_id): one of our own production apps
--     had ALREADY independently converged on a (platform_code,
--     gateway_payment_id) unique key via its own earlier hotpatch. Two
--     competing idempotency keys on the same table is exactly the kind of
--     footgun this fix exists to close — this function now targets the
--     SAME key shape, so `CREATE UNIQUE INDEX IF NOT EXISTS` genuinely
--     no-ops on an install that already carries it, instead of silently
--     creating a second, divergent constraint that ON CONFLICT can't see.
--   - `v_sub IS NULL` guards added after the FOR-UPDATE SELECT and after
--     the UPDATE...RETURNING: this function is a primitive any consumer
--     can call under its OWN transaction's isolation level, not just the
--     READ COMMITTED default this framework assumes. Under
--     REPEATABLE READ/SERIALIZABLE the ensure-insert/select pair is not
--     guaranteed race-free the way it is under READ COMMITTED — failing
--     loud beats silently capturing a payment against a NULL
--     subscription_id and reporting `is_active: true` regardless.
--   - Replay-lookup tenant check: the (platform_code, gateway_payment_id)
--     lookup on the "lost the race" path did not previously verify the
--     WINNING row's tenant_id matches the caller's — a genuine gateway
--     payment id belongs to exactly one tenant, so a mismatch here means
--     something upstream is wrong (misrouted call, reused id), not a
--     normal replay. Now RAISEs instead of silently reporting
--     `already_applied: true` to a tenant a payment doesn't belong to.
--   - `is_active` on the winner path changed from a hardcoded `true` to
--     the same `expires_at > NOW() AND status NOT IN ('cancelled')`
--     expression used on the replay path, so a `p_duration_days = 0` edge
--     case can't make this function and `sub_get_status` disagree about
--     whether the subscription is actually active.
--   - `payment_gateway` is now set explicitly in the INSERT column list
--     (was previously relying on the table's DEFAULT 'razorpay' while the
--     replay lookup hardcoded the literal) — one source of truth for the
--     value instead of an implicit coupling between a column default and
--     a query literal.
--
-- See sub_012_subscription_payments.pgsql's docblock for the matching
-- index-side reconciliation (duplicate-data guard before the constraint
-- is added, and the canonical key rationale).
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
    v_already_applied BOOLEAN := false;
    v_existing_tenant_id TEXT;
BEGIN
    -- Ensure a subscription row exists (idempotent — a re-run/race here is
    -- always a harmless no-op, guarded by subscriptions' own
    -- UNIQUE(platform_code, tenant_id)). Replaces the old broken 3-arg
    -- `PERFORM sub_get_status(...)` call (defect #1 above).
    INSERT INTO subscriptions (platform_code, tenant_id, plan_code, status, expires_at, owner_email)
    VALUES (p_platform_code, p_tenant_id, 'trial', 'trial', NOW(), NULLIF(p_payer_email, ''))
    ON CONFLICT (platform_code, tenant_id) DO NOTHING;

    -- Lock the subscription row BEFORE deciding the new expiry — serializes
    -- ALL concurrent activations for this (platform_code, tenant_id), not
    -- just retries of the same payment_id (defect #2 above).
    SELECT * INTO v_sub
    FROM subscriptions
    WHERE platform_code = p_platform_code
      AND tenant_id = p_tenant_id
    FOR UPDATE;

    -- Defensive guard, not a READ COMMITTED requirement: under READ
    -- COMMITTED (this framework's assumed default) the ensure-insert above
    -- makes this SELECT race-free by construction. But this function is a
    -- generic primitive any consuming app can call under its OWN
    -- transaction's isolation level — under REPEATABLE READ/SERIALIZABLE,
    -- `INSERT ... ON CONFLICT DO NOTHING` does not raise on a concurrently
    -- committed row, and this SELECT's snapshot could still miss it,
    -- leaving v_sub NULL. Failing loud here (dev-critique review) beats
    -- silently inserting a payment row with subscription_id=NULL and
    -- reporting is_active:true with no subscription ever touched — a
    -- payment-captured, subscription-never-activated silent failure.
    IF v_sub IS NULL THEN
        RAISE EXCEPTION 'sub_activate: subscription row missing for platform_code=%, tenant_id=% after ensure-insert — likely a non-READ-COMMITTED transaction; retry the call in its own READ COMMITTED transaction', p_platform_code, p_tenant_id;
    END IF;

    IF p_payment_id IS NOT NULL THEN
        -- The idempotency gate: only a caller whose INSERT actually lands a
        -- row gets to extend the subscription below. A replay/concurrent
        -- redelivery of the SAME (platform_code, gateway_payment_id)
        -- conflicts against uq_subscription_payments_gateway and inserts
        -- nothing. Conflict target intentionally matches the partial
        -- unique index already live in production (see
        -- sub_012_subscription_payments.pgsql's docblock) rather than a
        -- second, competing key — one canonical idempotency key per
        -- install, not two that can silently diverge.
        INSERT INTO subscription_payments (
            subscription_id, platform_code, tenant_id,
            payment_gateway, gateway_payment_id, amount_cents, currency,
            payer_email, payer_phone, payment_method,
            status, raw_payload
        ) VALUES (
            v_sub.id, p_platform_code, p_tenant_id,
            'razorpay', p_payment_id, p_amount_cents, 'INR',
            p_payer_email, p_payer_phone, p_payment_method,
            'captured', p_raw_payload
        )
        ON CONFLICT (platform_code, gateway_payment_id) WHERE gateway_payment_id IS NOT NULL DO NOTHING
        RETURNING id INTO v_payment_id;

        IF v_payment_id IS NULL THEN
            -- Lost the race / genuine replay: this exact gateway payment was
            -- already applied by an earlier call. Report the EXISTING
            -- payment + the subscription's CURRENT (unchanged by this call)
            -- state — never re-extend, never insert a duplicate.
            v_already_applied := true;

            SELECT id, tenant_id INTO v_payment_id, v_existing_tenant_id
            FROM subscription_payments
            WHERE platform_code = p_platform_code AND gateway_payment_id = p_payment_id;

            -- Integrity check (dev-critique review): the replay lookup is
            -- keyed on (platform_code, gateway_payment_id), not tenant_id —
            -- a genuine gateway payment id can only ever belong to ONE
            -- tenant, so if the caller's tenant doesn't match the tenant
            -- that actually won this payment id, something is wrong
            -- upstream (a forged/misrouted admin call, a gateway id reused
            -- across tenants). Silently returning "already_applied: true"
            -- here would extend the WRONG tenant's silence about a payment
            -- that isn't theirs — fail loud instead.
            IF v_existing_tenant_id IS DISTINCT FROM p_tenant_id THEN
                RAISE EXCEPTION 'sub_activate: gateway_payment_id % for platform_code % belongs to tenant_id %, not the requested tenant_id % — refusing to report already_applied for a payment that is not this tenant''s', p_payment_id, p_platform_code, v_existing_tenant_id, p_tenant_id;
            END IF;

            RETURN json_build_object(
                'subscription_id', v_sub.id,
                'platform_code', v_sub.platform_code,
                'tenant_id', v_sub.tenant_id,
                'plan_code', v_sub.plan_code,
                'status', v_sub.status,
                'is_active', (v_sub.expires_at > NOW() AND v_sub.status NOT IN ('cancelled')),
                'expires_at', v_sub.expires_at,
                'payment_id', v_payment_id,
                'already_applied', v_already_applied,
                'activated_at', v_sub.updated_at
            );
        END IF;
    END IF;

    -- This call is either payment-less (admin activation / no payment_id
    -- given) or the exactly-once winner for a NEW payment_id — safe to
    -- extend now, still inside the FOR UPDATE lock taken above.
    IF v_sub.expires_at > NOW() AND v_sub.status = 'active' THEN
        v_new_expires := v_sub.expires_at + MAKE_INTERVAL(days => p_duration_days);
    ELSE
        v_new_expires := NOW() + MAKE_INTERVAL(days => p_duration_days);
    END IF;

    UPDATE subscriptions SET
        plan_code = p_plan_code,
        status = 'active',
        started_at = NOW(),
        expires_at = v_new_expires,
        updated_at = NOW(),
        owner_email = COALESCE(NULLIF(p_payer_email, ''), owner_email)
    WHERE id = v_sub.id
    RETURNING * INTO v_sub;

    -- Defensive guard mirroring the one above: v_sub.id was locked and
    -- known non-NULL going in, so this UPDATE targeting that exact id
    -- cannot legitimately miss — but fail loud rather than silently
    -- return a NULL-fielded "success" if it ever does.
    IF v_sub IS NULL THEN
        RAISE EXCEPTION 'sub_activate: UPDATE unexpectedly matched no row for subscription id (platform_code=%, tenant_id=%)', p_platform_code, p_tenant_id;
    END IF;

    RETURN json_build_object(
        'subscription_id', v_sub.id,
        'platform_code', v_sub.platform_code,
        'tenant_id', v_sub.tenant_id,
        'plan_code', v_sub.plan_code,
        'status', v_sub.status,
        'is_active', (v_sub.expires_at > NOW() AND v_sub.status NOT IN ('cancelled')),
        'expires_at', v_sub.expires_at,
        'payment_id', v_payment_id,
        'already_applied', v_already_applied,
        'activated_at', NOW()
    );
END;
$$;
