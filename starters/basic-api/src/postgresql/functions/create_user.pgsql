-- Create User Function
-- Example database function for creating a user

CREATE OR REPLACE FUNCTION create_user(
    p_email VARCHAR,
    p_name VARCHAR,
    p_age INTEGER
)
RETURNS TABLE (
    id INTEGER,
    email VARCHAR,
    name VARCHAR,
    age INTEGER,
    created_at TIMESTAMP
) AS $$
BEGIN
    RETURN QUERY
    INSERT INTO users (email, name, age)
    VALUES (p_email, p_name, p_age)
    RETURNING users.id, users.email, users.name, users.age, users.created_at;
END;
$$ LANGUAGE plpgsql;
