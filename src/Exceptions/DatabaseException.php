<?php

namespace StoneScriptPHP\Exceptions;

/**
 * Database Exception
 */
class DatabaseException extends FrameworkException
{
    protected int $http_status_code = 500;

    public function __construct(string $message = 'Database error', array $context = [])
    {
        parent::__construct($message, 500, null, $context);
    }
}
