<?php

namespace StoneScriptPHP\Exceptions;

/**
 * Cache Exception
 */
class CacheException extends FrameworkException
{
    protected int $http_status_code = 500;

    public function __construct(string $message = 'Cache error', array $context = [])
    {
        parent::__construct($message, 500, null, $context);
    }
}
