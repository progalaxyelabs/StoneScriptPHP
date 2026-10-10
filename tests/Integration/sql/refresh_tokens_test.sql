-- Behavioural test of the refresh-token vendor schema against a REAL PostgreSQL (13+).
-- Run (from the repo root):
--   tests/Integration/sql/run-refresh-tokens-test.sh
-- Any failed assertion RAISEs and aborts with a non-zero exit.
-- Expects the schema files already applied (the runner does that).

\set ON_ERROR_STOP on
BEGIN;

CREATE OR REPLACE FUNCTION pg_temp.assert(p_ok boolean, p_msg text) RETURNS void LANGUAGE plpgsql AS $$
BEGIN IF NOT COALESCE(p_ok, false) THEN RAISE EXCEPTION 'ASSERT FAILED: %', p_msg; END IF; END; $$;

DO $$
DECLARE
    fam uuid; fam2 uuid; s text; n int; r record;
BEGIN
    -- store: new family, idempotent re-store
    SELECT o_family_id INTO fam FROM auth_rt_store('h_a', 'u1', 'authentication', now() + interval '1 day', '{"ip_address":"1.2.3.4"}', NULL, NULL);
    PERFORM pg_temp.assert(fam IS NOT NULL, 'store returns a family');
    SELECT o_family_id INTO fam2 FROM auth_rt_store('h_a', 'u1', 'authentication', now() + interval '1 day', '{}', NULL, NULL);
    PERFORM pg_temp.assert(fam = fam2, 're-store keeps the family');
    PERFORM pg_temp.assert((SELECT count(*) FROM auth_refresh_tokens) = 1, 're-store adds no row');

    -- purpose CHECK constraint
    BEGIN
        PERFORM auth_rt_store('h_bad', 'u1', 'nonsense', now() + interval '1 day', '{}', NULL, NULL);
        RAISE EXCEPTION 'ASSERT FAILED: bad purpose accepted';
    EXCEPTION WHEN check_violation THEN NULL; END;

    -- inspect valid / unknown
    SELECT o_status INTO s FROM auth_rt_inspect('h_a', 10);   PERFORM pg_temp.assert(s = 'valid', 'valid');
    SELECT o_status INTO s FROM auth_rt_inspect('nope', 10);  PERFORM pg_temp.assert(s = 'unknown', 'unknown');

    -- rotate ok: same family, old becomes grace, new valid
    SELECT * INTO r FROM auth_rt_rotate('h_a', 'h_b', now() + interval '1 day', '{}', 10);
    PERFORM pg_temp.assert(r.o_status = 'ok' AND r.o_family_id = fam AND r.o_subject = 'u1' AND r.o_purpose = 'authentication', 'rotate ok keeps family/subject/purpose');
    SELECT o_status INTO s FROM auth_rt_inspect('h_a', 10);   PERFORM pg_temp.assert(s = 'grace', 'old token in grace');
    SELECT o_status INTO s FROM auth_rt_inspect('h_b', 10);   PERFORM pg_temp.assert(s = 'valid', 'new token valid');

    -- second rotate of old inside grace: ok, a sibling in the same family
    SELECT * INTO r FROM auth_rt_rotate('h_a', 'h_b2', now() + interval '1 day', '{}', 10);
    PERFORM pg_temp.assert(r.o_status = 'ok', 'rotate inside grace ok');
    PERFORM pg_temp.assert((SELECT count(DISTINCT family_id) FROM auth_refresh_tokens) = 1, 'all in one family');
    PERFORM pg_temp.assert((SELECT replaced_by FROM auth_refresh_tokens WHERE token_hash='h_a') = 'h_b', 'replaced_by keeps the FIRST successor');

    -- age the rotation beyond grace: inspect says reused (read-only, nothing deleted)
    UPDATE auth_refresh_tokens SET rotated_at = now() - interval '1 minute' WHERE token_hash = 'h_a';
    SELECT o_status INTO s FROM auth_rt_inspect('h_a', 10);   PERFORM pg_temp.assert(s = 'reused', 'reused beyond grace');
    PERFORM pg_temp.assert((SELECT count(*) FROM auth_refresh_tokens) = 3, 'inspect does not delete');

    -- rotate of a reused token: family wiped in the same call
    SELECT * INTO r FROM auth_rt_rotate('h_a', 'h_evil', now() + interval '1 day', '{}', 10);
    PERFORM pg_temp.assert(r.o_status = 'reused', 'reuse detected');
    PERFORM pg_temp.assert((SELECT count(*) FROM auth_refresh_tokens) = 0, 'family revoked: legit tokens dead too');
    PERFORM pg_temp.assert(NOT EXISTS (SELECT 1 FROM auth_refresh_tokens WHERE token_hash = 'h_evil'), 'no successor for a replay');

    -- expired
    PERFORM auth_rt_store('h_old', 'u2', 'authorization', now() - interval '1 second', '{}', NULL, NULL);
    SELECT o_status INTO s FROM auth_rt_inspect('h_old', 10); PERFORM pg_temp.assert(s = 'expired', 'expired');
    SELECT o_status INTO s FROM auth_rt_rotate('h_old', 'h_new', now() + interval '1 day', '{}', 10); PERFORM pg_temp.assert(s = 'expired', 'rotate expired');
    PERFORM pg_temp.assert(NOT EXISTS (SELECT 1 FROM auth_refresh_tokens WHERE token_hash = 'h_old'), 'expired row reaped on rotate');

    -- unknown rotate
    SELECT o_status INTO s FROM auth_rt_rotate('ghost', 'h_new', now() + interval '1 day', '{}', 10); PERFORM pg_temp.assert(s = 'unknown', 'rotate unknown');

    -- revoke: row / session / family / subject
    PERFORM auth_rt_store('s1', 'u3', 'authentication', now() + interval '1 day', '{}', NULL, NULL);
    PERFORM auth_rt_rotate('s1', 's2', now() + interval '1 day', '{}', 10);
    PERFORM auth_rt_store('other', 'u3', 'authentication', now() + interval '1 day', '{}', NULL, NULL);
    SELECT o_deleted INTO n FROM auth_rt_revoke('s2');        PERFORM pg_temp.assert(n = 1, 'revoke one row');
    SELECT o_deleted INTO n FROM auth_rt_revoke('s2');        PERFORM pg_temp.assert(n = 0, 'revoke absent = 0');
    SELECT o_deleted INTO n FROM auth_rt_revoke_session('s1'); PERFORM pg_temp.assert(n = 1, 'revoke_session deletes the rest of the family (s1)');
    PERFORM pg_temp.assert(EXISTS (SELECT 1 FROM auth_refresh_tokens WHERE token_hash = 'other'), 'other session untouched');

    PERFORM auth_rt_store('p1', 'u4', 'authentication', now() + interval '1 day', '{}', NULL, NULL);
    PERFORM auth_rt_store('p2', 'u4', 'authorization', now() + interval '1 day', '{}', NULL, NULL);
    SELECT o_deleted INTO n FROM auth_rt_revoke_all_for_subject('u4', 'authorization'); PERFORM pg_temp.assert(n = 1, 'revoke_all by purpose');
    SELECT o_deleted INTO n FROM auth_rt_revoke_all_for_subject('u4', NULL);            PERFORM pg_temp.assert(n = 1, 'revoke_all any purpose');

    SELECT o_family_id INTO fam FROM auth_rt_store('f1', 'u5', 'authentication', now() + interval '1 day', '{}', NULL, NULL);
    SELECT o_deleted INTO n FROM auth_rt_revoke_family(fam);  PERFORM pg_temp.assert(n = 1, 'revoke_family');

    -- grace allows exactly ONE extra exchange; the second is reuse and wipes the family
    PERFORM auth_rt_store('g1', 'ug', 'authentication', now() + interval '1 day', '{}', NULL, NULL);
    SELECT * INTO r FROM auth_rt_rotate('g1', 'g2', now() + interval '1 day', '{}', 10);  PERFORM pg_temp.assert(r.o_status = 'ok', 'g: first rotation');
    SELECT * INTO r FROM auth_rt_rotate('g1', 'g3', now() + interval '1 day', '{}', 10);  PERFORM pg_temp.assert(r.o_status = 'ok', 'g: the ONE extra exchange in grace');
    SELECT o_status INTO s FROM auth_rt_inspect('g1', 10);                                  PERFORM pg_temp.assert(s = 'reused', 'g: after the extra exchange the token is spent for good');
    SELECT * INTO r FROM auth_rt_rotate('g1', 'g4', now() + interval '1 day', '{}', 10);  PERFORM pg_temp.assert(r.o_status = 'reused', 'g: second extra exchange = reuse');
    PERFORM pg_temp.assert(NOT EXISTS (SELECT 1 FROM auth_refresh_tokens WHERE subject = 'ug'), 'g: family wiped, g2/g3 dead');

    -- absolute session cap: never slides, successors are capped, expiry kills the session
    PERFORM auth_rt_store('c1', 'uc', 'authentication', now() + interval '10 days', '{}', NULL, now() + interval '1 hour');
    PERFORM pg_temp.assert((SELECT expires_at FROM auth_refresh_tokens WHERE token_hash = 'c1') <= now() + interval '1 hour' + interval '1 second', 'c: row expiry capped by the session cap');
    SELECT * INTO r FROM auth_rt_rotate('c1', 'c2', now() + interval '30 days', '{}', 10); PERFORM pg_temp.assert(r.o_status = 'ok', 'c: rotate within cap');
    PERFORM pg_temp.assert((SELECT expires_at FROM auth_refresh_tokens WHERE token_hash = 'c2') <= now() + interval '1 hour' + interval '1 second', 'c: successor expiry capped (no sliding)');
    PERFORM pg_temp.assert((SELECT session_expires_at FROM auth_refresh_tokens WHERE token_hash = 'c2') = (SELECT session_expires_at FROM auth_refresh_tokens WHERE token_hash = 'c1'), 'c: session cap inherited unchanged');
    UPDATE auth_refresh_tokens SET session_expires_at = now() - interval '1 second' WHERE subject = 'uc';
    SELECT o_status INTO s FROM auth_rt_inspect('c2', 10);                                  PERFORM pg_temp.assert(s = 'expired', 'c: past the cap = expired even though the token is fresh');
    UPDATE auth_refresh_tokens SET expires_at = now() + interval '1 day' WHERE subject = 'uc';
    SELECT o_status INTO s FROM auth_rt_rotate('c2', 'c3', now() + interval '1 day', '{}', 10); PERFORM pg_temp.assert(s = 'expired', 'c: cannot rotate past the cap');

    -- purge: batched, only expired, older-than window
    DELETE FROM auth_refresh_tokens;
    INSERT INTO auth_refresh_tokens (token_hash, family_id, subject, purpose, expires_at, session_expires_at)
        SELECT 'e' || g, gen_random_uuid(), 'u', 'authentication', now() - interval '2 hours', now() - interval '2 hours' FROM generate_series(1, 5) g;
    PERFORM auth_rt_store('live', 'u', 'authentication', now() + interval '1 day', '{}', NULL, NULL);
    -- (auth_rt_store itself reaps up to 25 expired rows; refill after it)
    INSERT INTO auth_refresh_tokens (token_hash, family_id, subject, purpose, expires_at, session_expires_at)
        SELECT 'f' || g, gen_random_uuid(), 'u', 'authentication', now() - interval '2 hours', now() - interval '2 hours' FROM generate_series(1, 5) g;
    SELECT o_deleted INTO n FROM auth_rt_purge_expired(2, 0);     PERFORM pg_temp.assert(n = 2, 'purge honours batch');
    SELECT o_deleted INTO n FROM auth_rt_purge_expired(100, 86400); PERFORM pg_temp.assert(n = 0, 'purge honours older-than');
    SELECT o_deleted INTO n FROM auth_rt_purge_expired(100, 0);   PERFORM pg_temp.assert(n = 3, 'purge the rest');
    SELECT o_deleted INTO n FROM auth_rt_purge_expired(100, 0);   PERFORM pg_temp.assert(n = 0, 'purge done');
    PERFORM pg_temp.assert(EXISTS (SELECT 1 FROM auth_refresh_tokens WHERE token_hash = 'live'), 'live row survives purge');

    -- opportunistic reap inside store(): bounded to 25 per call
    DELETE FROM auth_refresh_tokens;
    INSERT INTO auth_refresh_tokens (token_hash, family_id, subject, purpose, expires_at, session_expires_at)
        SELECT 'x' || g, gen_random_uuid(), 'u', 'authentication', now() - interval '1 hour', now() - interval '1 hour' FROM generate_series(1, 40) g;
    PERFORM auth_rt_store('fresh', 'u', 'authentication', now() + interval '1 day', '{}', NULL, NULL);
    PERFORM pg_temp.assert((SELECT count(*) FROM auth_refresh_tokens WHERE token_hash LIKE 'x%') = 15, 'store reaps at most 25 expired rows');

    RAISE NOTICE 'ALL REFRESH-TOKEN SQL ASSERTIONS PASSED';
END $$;

ROLLBACK;
