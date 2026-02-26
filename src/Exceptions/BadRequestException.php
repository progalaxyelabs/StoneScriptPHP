<?php

namespace StoneScriptPHP\Exceptions;

/**
 * 400 Bad Request Exception
 */
class BadRequestException extends FrameworkException
{
    protected int $http_status_code = 400;

    public function __construct(string $message = 'Bad request', array $context = [])
    {
        parent::__construct($message, 400, null, $context);
    }
}
