-- auth_rt_revoke_session - logout: delete EVERY token of the session (family) that
-- owns this token, including rotated predecessors and grace-window siblings.
CREATE OR REPLACE FUNCTION auth_rt_revoke_session(p_token_hash TEXT)
RETURNS TABLE (o_deleted INT) LANGUAGE plpgsql AS $$
DECLARE n INT;
BEGIN
    DELETE FROM auth_refresh_tokens
    WHERE family_id = (SELECT t.family_id FROM auth_refresh_tokens t WHERE t.token_hash = p_token_hash);
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN QUERY SELECT n;
END;
$$;
