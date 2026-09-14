<?php

declare(strict_types=1);

namespace StoneScriptPHP\Billing\Exceptions;

/**
 * Raised when a webhook payload cannot be parsed or has a missing/unknown
 * event, by any `StoneScriptPHP\Billing\Contracts\PaymentProvider`
 * implementation.
 *
 * Distinct from SignatureVerificationException (which fires on a
 * signature/HMAC mismatch). WebhookException covers structural problems
 * with the payload itself.
 *
 * @package StoneScriptPHP\Billing\Exceptions
 */
class WebhookException extends PaymentException
{
    public function __construct(string $reason)
    {
        parent::__construct("Webhook processing failed: {$reason}");
    }
}
