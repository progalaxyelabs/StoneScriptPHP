-- auth_rt_store - persist one refresh token (new session family unless p_family_id given).
-- Re-storing the same hash is a no-op that returns the existing family (a retried mint must not
-- fail loudly). Also reaps a few expired rows per call so abandoned sessions cannot accumulate
-- even if the purge job is never scheduled (bounded; SKIP LOCKED so it never waits on a concurrent
-- reaper; the job does the bulk work).
-- p_metadata is TEXT (already JSON-encoded) on purpose: one unambiguous wire type.
-- p_session_expires_at: absolute cap for the whole session (NULL = same as p_expires_at); never slides.
CREATE OR REPLACE FUNCTION auth_rt_store(
    p_token_hash TEXT,
    p_subject TEXT,
    p_purpose TEXT,
    p_expires_at TIMESTAMPTZ,
    p_metadata TEXT,
    p_family_id UUID,
    p_session_expires_at TIMESTAMPTZ
) RETURNS TABLE (o_family_id UUID) LANGUAGE plpgsql AS $$
DECLARE
    v_family UUID;
    v_session TIMESTAMPTZ := COALESCE(p_session_expires_at, p_expires_at);
BEGIN
    DELETE FROM auth_refresh_tokens
    WHERE token_hash IN (
        SELECT t.token_hash FROM auth_refresh_tokens t
        WHERE LEAST(t.expires_at, t.session_expires_at) <= NOW()
        ORDER BY t.expires_at LIMIT 25
        FOR UPDATE SKIP LOCKED
    );

    INSERT INTO auth_refresh_tokens (token_hash, family_id, subject, purpose, expires_at, session_expires_at, metadata)
    VALUES (
        p_token_hash,
        COALESCE(p_family_id, gen_random_uuid()),
        p_subject,
        p_purpose,
        LEAST(p_expires_at, v_session),
        v_session,
        COALESCE(NULLIF(p_metadata, '')::jsonb, '{}'::jsonb)
    )
    ON CONFLICT (token_hash) DO NOTHING
    RETURNING family_id INTO v_family;

    IF v_family IS NULL THEN
        SELECT t.family_id INTO v_family FROM auth_refresh_tokens t WHERE t.token_hash = p_token_hash;
    END IF;

    RETURN QUERY SELECT v_family;
END;
$$;
