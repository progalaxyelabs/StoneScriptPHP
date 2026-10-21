CREATE OR REPLACE FUNCTION create_user(p_email TEXT, p_name TEXT, p_age INT DEFAULT NULL)
RETURNS TABLE (user_id UUID) AS $$
BEGIN
    RETURN QUERY INSERT INTO users (email, name, age) VALUES (p_email, p_name, p_age) RETURNING id;
END;
$$ LANGUAGE plpgsql;
