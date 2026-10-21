CREATE OR REPLACE FUNCTION create_member(p_user_id UUID, p_org_id UUID)
RETURNS void AS $$
BEGIN
    INSERT INTO members (user_id, org_id) VALUES (p_user_id, p_org_id);
END;
$$ LANGUAGE plpgsql;
