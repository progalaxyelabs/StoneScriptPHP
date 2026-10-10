-- auth_rt_rotate - atomically exchange a refresh token for its successor.
-- The old row is locked (FOR UPDATE) so two concurrent refreshes cannot both win.
--   ok       old token valid: successor stored in the SAME family, old token marked rotated.
--            Or the old token was rotated within the grace window and its ONE extra exchange was
--            still unused: a second successor is issued and the extra exchange is consumed.
--   reused   old token already rotated beyond the grace window, or its grace exchange was already
--            taken: the WHOLE FAMILY is deleted in this same transaction (session killed), nothing stored.
--   expired  old token past expires_at, or the session is past its absolute cap (row deleted)
--   unknown  no such token
-- Undetected parallel use of a stolen token is therefore bounded to ONE extra exchange.
-- The successor's expiry is capped at the session's absolute cap (session_expires_at), which never slides.
CREATE OR REPLACE FUNCTION auth_rt_rotate(
    p_old_hash TEXT,
    p_new_hash TEXT,
    p_new_expires_at TIMESTAMPTZ,
    p_metadata TEXT,
    p_grace_seconds INT
) RETURNS TABLE (
    o_status TEXT,
    o_family_id UUID,
    o_subject TEXT,
    o_purpose TEXT
) LANGUAGE plpgsql AS $$
DECLARE
    r auth_refresh_tokens%ROWTYPE;
    v_use_grace BOOLEAN := FALSE;
BEGIN
    SELECT * INTO r FROM auth_refresh_tokens t WHERE t.token_hash = p_old_hash FOR UPDATE;
    IF NOT FOUND THEN
        RETURN QUERY SELECT 'unknown'::text, NULL::uuid, NULL::text, NULL::text;
        RETURN;
    END IF;

    IF LEAST(r.expires_at, r.session_expires_at) <= NOW() THEN
        DELETE FROM auth_refresh_tokens WHERE token_hash = p_old_hash;
        RETURN QUERY SELECT 'expired'::text, r.family_id, r.subject, r.purpose;
        RETURN;
    END IF;

    IF r.rotated_at IS NOT NULL THEN
        IF NOT r.grace_used
           AND r.rotated_at > NOW() - make_interval(secs => GREATEST(p_grace_seconds, 0)) THEN
            v_use_grace := TRUE;
        ELSE
            DELETE FROM auth_refresh_tokens WHERE family_id = r.family_id;
            RETURN QUERY SELECT 'reused'::text, r.family_id, r.subject, r.purpose;
            RETURN;
        END IF;
    END IF;

    INSERT INTO auth_refresh_tokens (token_hash, family_id, subject, purpose, expires_at, session_expires_at, metadata)
    VALUES (
        p_new_hash, r.family_id, r.subject, r.purpose,
        LEAST(p_new_expires_at, r.session_expires_at), r.session_expires_at,
        COALESCE(NULLIF(p_metadata, '')::jsonb, '{}'::jsonb)
    );

    UPDATE auth_refresh_tokens
    SET rotated_at = COALESCE(rotated_at, NOW()),
        replaced_by = COALESCE(replaced_by, p_new_hash),
        grace_used = grace_used OR v_use_grace,
        last_used_at = NOW()
    WHERE token_hash = p_old_hash;

    RETURN QUERY SELECT 'ok'::text, r.family_id, r.subject, r.purpose;
END;
$$;
