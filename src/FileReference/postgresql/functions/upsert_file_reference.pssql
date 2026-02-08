CREATE OR REPLACE FUNCTION upsert_file_reference(
    p_tenant_id VARCHAR(255),
    p_entity_type VARCHAR(100),
    p_entity_id VARCHAR(255),
    p_file_id UUID,
    p_file_name VARCHAR(500),
    p_content_type VARCHAR(255),
    p_size BIGINT,
    p_uploaded_by VARCHAR(255)
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
    INSERT INTO file_references (tenant_id, entity_type, entity_id, file_id, file_name, content_type, size, uploaded_by)
    VALUES (p_tenant_id, p_entity_type, p_entity_id, p_file_id, p_file_name, p_content_type, p_size, p_uploaded_by)
    ON CONFLICT (tenant_id, file_id) DO UPDATE SET
        entity_type = EXCLUDED.entity_type,
        entity_id = EXCLUDED.entity_id,
        file_name = EXCLUDED.file_name,
        content_type = EXCLUDED.content_type,
        size = EXCLUDED.size
    RETURNING
        file_references.id,
        file_references.tenant_id,
        file_references.entity_type,
        file_references.entity_id,
        file_references.file_id,
        file_references.file_name,
        file_references.content_type,
        file_references.size,
        file_references.uploaded_by,
        file_references.created_at;
END;
$$ LANGUAGE plpgsql;
