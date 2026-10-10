-- auth_rt_revoke_all_for_subject - logout-everywhere / password change / breach response.
-- p_purpose NULL = every purpose.
CREATE OR REPLACE FUNCTION auth_rt_revoke_all_for_subject(p_subject TEXT, p_purpose TEXT)
RETURNS TABLE (o_deleted INT) LANGUAGE plpgsql AS $$
DECLARE n INT;
BEGIN
    DELETE FROM auth_refresh_tokens
    WHERE subject = p_subject AND (p_purpose IS NULL OR purpose = p_purpose);
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN QUERY SELECT n;
END;
$$;
