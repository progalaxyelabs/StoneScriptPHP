<?php

namespace StoneScriptPHP\Exceptions;

/**
 * 403 Forbidden Exception
 */
class ForbiddenException extends FrameworkException
{
    protected int $http_status_code = 403;

    public function __construct(string $message = 'Forbidden', array $context = [])
    {
        parent::__construct($message, 403, null, $context);
    }
}
