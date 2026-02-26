<?php

namespace StoneScriptPHP\Exceptions;

/**
 * 401 Unauthorized Exception
 */
class UnauthorizedException extends FrameworkException
{
    protected int $http_status_code = 401;

    public function __construct(string $message = 'Unauthorized', array $context = [])
    {
        parent::__construct($message, 401, null, $context);
    }
}
