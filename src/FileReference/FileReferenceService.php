<?php

namespace StoneScriptPHP\FileReference;

use StoneScriptPHP\Database;

class FileReferenceService
{
    /**
     * Create or update a file reference linking a file to a business entity.
     *
     * @param string $tenantId Tenant identifier
     * @param string $entityType Entity type (e.g., 'order', 'product', 'invoice')
     * @param string $entityId Business entity ID
     * @param string $fileId File UUID from files service
     * @param string $fileName Original file name
     * @param string $contentType MIME content type
     * @param int $size File size in bytes
     * @param string $uploadedBy User ID who uploaded the file
     * @return FileReference|null The upserted file reference
     */
    public static function upsert(
        string $tenantId,
        string $entityType,
        string $entityId,
        string $fileId,
        string $fileName,
        string $contentType,
        int $size,
        string $uploadedBy
    ): ?FileReference {
        $result = Database::fn('upsert_file_reference', [
            'p_tenant_id' => $tenantId,
            'p_entity_type' => $entityType,
            'p_entity_id' => $entityId,
            'p_file_id' => $fileId,
            'p_file_name' => $fileName,
            'p_content_type' => $contentType,
            'p_size' => $size,
            'p_uploaded_by' => $uploadedBy,
        ]);

        return Database::result_as_single('upsert_file_reference', $result, FileReference::class);
    }

    /**
     * Get all file references for a business entity.
     *
     * @param string $tenantId Tenant identifier
     * @param string $entityType Entity type
     * @param string $entityId Business entity ID
     * @return FileReference[] Array of file references
     */
    public static function getForEntity(
        string $tenantId,
        string $entityType,
        string $entityId
    ): array {
        $result = Database::fn('get_file_references', [
            'p_tenant_id' => $tenantId,
            'p_entity_type' => $entityType,
            'p_entity_id' => $entityId,
        ]);

        return Database::result_as_table('get_file_references', $result, FileReference::class);
    }

    /**
     * Delete a file reference.
     *
     * @param string $tenantId Tenant identifier
     * @param string $fileId File UUID
     * @return int Number of affected rows
     */
    public static function delete(string $tenantId, string $fileId): int
    {
        $result = Database::fn('delete_file_reference', [
            'p_tenant_id' => $tenantId,
            'p_file_id' => $fileId,
        ]);

        if (!empty($result) && isset($result[0]['affected_count'])) {
            return (int) $result[0]['affected_count'];
        }

        return 0;
    }
}
