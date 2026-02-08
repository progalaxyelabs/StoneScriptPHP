<?php

namespace StoneScriptPHP\FileReference;

class FileReference
{
    public int $id;
    public string $tenant_id;
    public string $entity_type;
    public string $entity_id;
    public string $file_id;
    public string $file_name;
    public string $content_type;
    public int $size;
    public string $uploaded_by;
    public string $created_at;
}
