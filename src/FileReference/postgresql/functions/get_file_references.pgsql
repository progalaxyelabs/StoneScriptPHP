CREATE OR REPLACE FUNCTION get_file_references(
    p_tenant_id VARCHAR(255),
    p_entity_type VARCHAR(100),
    p_entity_id VARCHAR(255)
)
RETURNS TABLE (
    id INT,
    tenant_id VARCHAR(255),
    entity_type VARCHAR(100),
    entity_id VARCHAR(255),
    file_id UUID,
    file_name VARCHAR(500),
    content_type VARCHAR(255),
    size BIGINT,
    uploaded_by VARCHAR(255),
    created_at TIMESTAMPTZ
) AS $$
BEGIN
    RETURN QUERY
    SELECT
        fr.id,
        fr.tenant_id,
        fr.entity_type,
        fr.entity_id,
        fr.file_id,
        fr.file_name,
        fr.content_type,
        fr.size,
        fr.uploaded_by,
        fr.created_at
    FROM file_references fr
    WHERE fr.tenant_id = p_tenant_id
      AND fr.entity_type = p_entity_type
      AND fr.entity_id = p_entity_id
    ORDER BY fr.created_at DESC;
END;
$$ LANGUAGE plpgsql;
