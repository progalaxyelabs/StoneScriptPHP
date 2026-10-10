-- auth_rt_revoke - hard-delete one refresh token row. Absent hash = no-op (0).
CREATE OR REPLACE FUNCTION auth_rt_revoke(p_token_hash TEXT)
RETURNS TABLE (o_deleted INT) LANGUAGE plpgsql AS $$
DECLARE n INT;
BEGIN
    DELETE FROM auth_refresh_tokens WHERE token_hash = p_token_hash;
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN QUERY SELECT n;
END;
$$;
