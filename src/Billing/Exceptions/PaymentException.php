<?php

declare(strict_types=1);

namespace StoneScriptPHP\Billing\Exceptions;

/**
 * Base exception for all payment errors raised by a
 * `StoneScriptPHP\Billing\Contracts\PaymentProvider` implementation
 * (e.g. `stonescriptphp-pay`'s drivers).
 *
 * Wraps provider-level errors (network failures, API errors) so consuming
 * code can catch a single, framework-owned type without coupling to any
 * downstream package's exception classes.
 *
 * @package StoneScriptPHP\Billing\Exceptions
 */
class PaymentException extends \RuntimeException
{
    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
