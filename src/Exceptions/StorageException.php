<?php

namespace StoneScriptPHP\Exceptions;

/**
 * File Storage Exception
 */
class StorageException extends FrameworkException
{
    protected int $http_status_code = 500;

    public function __construct(string $message = 'Storage error', array $context = [])
    {
        parent::__construct($message, 500, null, $context);
    }
}
