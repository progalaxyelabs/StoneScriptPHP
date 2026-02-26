<?php

namespace StoneScriptPHP\Exceptions;

/**
 * Configuration Exception
 */
class ConfigurationException extends FrameworkException
{
    protected int $http_status_code = 500;

    public function __construct(string $message = 'Configuration error', array $context = [])
    {
        parent::__construct($message, 500, null, $context);
    }
}
