<?php

namespace StoneScriptPHP\Exceptions;

use Exception;
use Throwable;

/**
 * Base Exception for StoneScriptPHP Framework
 */
abstract class FrameworkException extends Exception
{
    protected int $http_status_code = 500;
    protected array $context = [];

    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    public function getHttpStatusCode(): int
    {
        return $this->http_status_code;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function toArray(): array
    {
        return [
            'error' => [
                'type' => get_class($this),
                'message' => $this->getMessage(),
                'code' => $this->getCode(),
                'http_status' => $this->http_status_code,
                'context' => $this->context
            ]
        ];
    }
}
