<?php

namespace StoneScriptPHP\Exceptions;

/**
 * 429 Too Many Requests Exception
 */
class RateLimitException extends FrameworkException
{
    protected int $http_status_code = 429;

    public function __construct(string $message = 'Too many requests', array $context = [])
    {
        parent::__construct($message, 429, null, $context);
    }
}
