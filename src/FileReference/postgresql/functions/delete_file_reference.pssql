CREATE OR REPLACE FUNCTION delete_file_reference(
    p_tenant_id VARCHAR(255),
    p_file_id UUID
)
RETURNS TABLE (
    affected_count INT
) AS $$
DECLARE
    v_count INT;
BEGIN
    DELETE FROM file_references
    WHERE file_references.tenant_id = p_tenant_id
      AND file_references.file_id = p_file_id;

    GET DIAGNOSTICS v_count = ROW_COUNT;

    RETURN QUERY SELECT v_count;
END;
$$ LANGUAGE plpgsql;
