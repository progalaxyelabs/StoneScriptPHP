-- One function per RAISE shape under test. p_val is customer-grade data (email/phone).
CREATE OR REPLACE FUNCTION raise_it(p_kind TEXT, p_val TEXT)
RETURNS void AS $$
BEGIN
    CASE p_kind
        WHEN 'code'         THEN RAISE EXCEPTION 'not_found';
        WHEN 'code_slug'    THEN RAISE EXCEPTION 'slug_taken';
        WHEN 'code_text'    THEN RAISE EXCEPTION 'job_conflict: %', p_val;
        WHEN 'code_colon'   THEN RAISE EXCEPTION 'not_found: workspace id=% not found', p_val;
        WHEN 'tag_er'       THEN RAISE EXCEPTION '[ER102] stock short for %', p_val;
        WHEN 'tag_public'   THEN RAISE EXCEPTION '[public:code:409] already here: %', p_val;
        WHEN 'sentence'     THEN RAISE EXCEPTION 'Insufficient stock for item';
        WHEN 'value_exists' THEN RAISE EXCEPTION '% exists', p_val;
        WHEN 'value_only'   THEN RAISE EXCEPTION 'User % exists', p_val;
        WHEN 'detail'       THEN RAISE EXCEPTION 'Something failed' USING DETAIL = 'not_found ' || p_val, HINT = 'slug_taken ' || p_val;
        WHEN 'smuggle'      THEN RAISE EXCEPTION 'dup for constraint "smuggled_key" column "smuggled_col" %', p_val;
        ELSE RAISE EXCEPTION 'bad kind';
    END CASE;
END;
$$ LANGUAGE plpgsql;
