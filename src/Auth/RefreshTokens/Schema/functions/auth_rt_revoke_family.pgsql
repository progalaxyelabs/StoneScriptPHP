-- auth_rt_revoke_family - delete every token of one session family.
CREATE OR REPLACE FUNCTION auth_rt_revoke_family(p_family_id UUID)
RETURNS TABLE (o_deleted INT) LANGUAGE plpgsql AS $$
DECLARE n INT;
BEGIN
    DELETE FROM auth_refresh_tokens WHERE family_id = p_family_id;
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN QUERY SELECT n;
END;
$$;
