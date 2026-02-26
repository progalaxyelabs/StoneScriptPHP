<?php

namespace StoneScriptPHP\Exceptions;

/**
 * 422 Validation Exception
 */
class ValidationException extends FrameworkException
{
    protected int $http_status_code = 422;
    protected array $validation_errors = [];

    public function __construct(array $validation_errors, string $message = 'Validation failed')
    {
        $this->validation_errors = $validation_errors;
        parent::__construct($message, 422, null, ['validation_errors' => $validation_errors]);
    }

    public function getValidationErrors(): array
    {
        return $this->validation_errors;
    }
}
