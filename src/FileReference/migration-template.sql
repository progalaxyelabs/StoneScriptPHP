-- File References Migration
-- Copy this file to your platform's migrations/ directory
-- Run: php stone migrate

-- Table
CREATE TABLE IF NOT EXISTS file_references (
    id SERIAL PRIMARY KEY,
    tenant_id VARCHAR(255) NOT NULL,
    entity_type VARCHAR(100) NOT NULL,
    entity_id VARCHAR(255) NOT NULL,
    file_id UUID NOT NULL,
    file_name VARCHAR(500) NOT NULL,
    content_type VARCHAR(255) NOT NULL DEFAULT 'application/octet-stream',
    size BIGINT NOT NULL DEFAULT 0,
    uploaded_by VARCHAR(255) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_file_refs_unique ON file_references(tenant_id, file_id);
CREATE INDEX IF NOT EXISTS idx_file_refs_entity ON file_references(tenant_id, entity_type, entity_id);

-- Functions
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
