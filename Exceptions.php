<?php

namespace Framework;

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

/**
 * Database Exception
 */
class DatabaseException extends FrameworkException
{
    protected int $http_status_code = 500;

    public function __construct(string $message = 'Database error', array $context = [])
    {
        parent::__construct($message, 500, null, $context);
    }
}

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

/**
 * Cache Exception
 */
class CacheException extends FrameworkException
{
    protected int $http_status_code = 500;

    public function __construct(string $message = 'Cache error', array $context = [])
    {
        parent::__construct($message, 500, null, $context);
    }
}

/**
 * File Storage Exception
 */
class StorageException extends FrameworkException
{
    protected int $http_status_code = 500;

    public function __construct(string $message = 'Storage error', array $context = [])
    {
        parent::__construct($message, 500, null, $context);
    }
}