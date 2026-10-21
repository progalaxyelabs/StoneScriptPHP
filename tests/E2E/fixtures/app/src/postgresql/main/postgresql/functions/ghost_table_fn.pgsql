CREATE OR REPLACE FUNCTION ghost_table_fn()
RETURNS void AS $$
BEGIN
    PERFORM 1 FROM ghost_table;
END;
$$ LANGUAGE plpgsql;
