-- stonescript-lint: ignore-file
-- DELIBERATELY UNSAFE: RAISE messages that start with an interpolated value. They exist only to prove, against a real
-- gateway, that such a value is never taken for a public marker and is taken for an app code only when it has a code shape.
CREATE OR REPLACE FUNCTION raise_unsafe(p_kind TEXT, p_val TEXT)
RETURNS void AS $$
BEGIN
    CASE p_kind
        WHEN 'value_exists' THEN RAISE EXCEPTION '% exists', p_val;
        WHEN 'bare_value'   THEN RAISE EXCEPTION '%', p_val;
        ELSE RAISE EXCEPTION 'bad kind';
    END CASE;
END;
$$ LANGUAGE plpgsql;
