-- Echoes two caller-supplied values into the message, as real-world "already registered to" messages do.
CREATE OR REPLACE FUNCTION echo_two(p_phone TEXT, p_owner TEXT)
RETURNS void AS $$
BEGIN
    RAISE EXCEPTION 'Phone % is already registered to %', p_phone, p_owner;
END;
$$ LANGUAGE plpgsql;
