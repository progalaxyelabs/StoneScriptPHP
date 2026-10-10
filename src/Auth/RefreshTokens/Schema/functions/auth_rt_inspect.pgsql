-- auth_rt_inspect - read-only status of a refresh token hash.
--   valid    row present, not rotated, not expired (neither the token nor its session cap)
--   grace    rotated less than p_grace_seconds ago and its ONE extra exchange not yet taken
--   reused   rotated longer ago, or the grace exchange already used: replay of a spent token
--            (the caller must revoke the family)
--   expired  past expires_at or past the session's absolute cap
--   unknown  no row (never issued, revoked, or purged)
CREATE OR REPLACE FUNCTION auth_rt_inspect(
    p_token_hash TEXT,
    p_grace_seconds INT
) RETURNS TABLE (
    o_status TEXT,
    o_family_id UUID,
    o_subject TEXT,
    o_purpose TEXT,
    o_expires_at TIMESTAMPTZ
) LANGUAGE plpgsql AS $$
DECLARE
    r auth_refresh_tokens%ROWTYPE;
BEGIN
    SELECT * INTO r FROM auth_refresh_tokens t WHERE t.token_hash = p_token_hash;
    IF NOT FOUND THEN
        RETURN QUERY SELECT 'unknown'::text, NULL::uuid, NULL::text, NULL::text, NULL::timestamptz;
        RETURN;
    END IF;

    IF LEAST(r.expires_at, r.session_expires_at) <= NOW() THEN
        RETURN QUERY SELECT 'expired'::text, r.family_id, r.subject, r.purpose, r.expires_at;
    ELSIF r.rotated_at IS NULL THEN
        RETURN QUERY SELECT 'valid'::text, r.family_id, r.subject, r.purpose, r.expires_at;
    ELSIF NOT r.grace_used
          AND r.rotated_at > NOW() - make_interval(secs => GREATEST(p_grace_seconds, 0)) THEN
        RETURN QUERY SELECT 'grace'::text, r.family_id, r.subject, r.purpose, r.expires_at;
    ELSE
        RETURN QUERY SELECT 'reused'::text, r.family_id, r.subject, r.purpose, r.expires_at;
    END IF;
END;
$$;
