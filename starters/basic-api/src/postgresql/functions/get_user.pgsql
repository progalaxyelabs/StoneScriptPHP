-- Get User Function
-- Example database function for retrieving a user by ID

CREATE OR REPLACE FUNCTION get_user(
    p_user_id INTEGER
)
RETURNS TABLE (
    id INTEGER,
    email VARCHAR,
    name VARCHAR,
    age INTEGER,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
) AS $$
BEGIN
    RETURN QUERY
    SELECT users.id, users.email, users.name, users.age, users.created_at, users.updated_at
    FROM users
    WHERE users.id = p_user_id;
END;
$$ LANGUAGE plpgsql;
