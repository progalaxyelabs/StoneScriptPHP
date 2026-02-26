<?php

namespace StoneScriptPHP\Exceptions;

/**
 * 404 Not Found Exception
 */
class InvalidRouteException extends FrameworkException
{
    protected int $http_status_code = 404;

    public function __construct(string $message = 'Page not found', array $context = [])
    {
        parent::__construct($message, 404, null, $context);
    }
}
