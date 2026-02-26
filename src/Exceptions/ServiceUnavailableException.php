<?php

namespace StoneScriptPHP\Exceptions;

/**
 * 503 Service Unavailable Exception
 */
class ServiceUnavailableException extends FrameworkException
{
    protected int $http_status_code = 503;

    public function __construct(string $message = 'Service unavailable', array $context = [])
    {
        parent::__construct($message, 503, null, $context);
    }
}
