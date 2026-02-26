<?php

namespace StoneScriptPHP\Exceptions;

/**
 * 500 Internal Server Error Exception
 */
class InternalServerErrorException extends FrameworkException
{
    protected int $http_status_code = 500;

    public function __construct(string $message = 'Internal server error', array $context = [])
    {
        parent::__construct($message, 500, null, $context);
    }
}
