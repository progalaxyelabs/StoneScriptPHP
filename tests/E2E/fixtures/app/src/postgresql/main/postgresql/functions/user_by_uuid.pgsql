CREATE OR REPLACE FUNCTION user_by_uuid(p_id UUID)
RETURNS TABLE (email VARCHAR) AS $$
BEGIN
    RETURN QUERY SELECT u.email FROM users u WHERE u.id = p_id;
END;
$$ LANGUAGE plpgsql;
