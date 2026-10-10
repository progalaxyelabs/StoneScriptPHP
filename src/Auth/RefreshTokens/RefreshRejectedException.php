<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\RefreshTokens;

/** A presented refresh token was refused. `reason()` is stable and safe to log; never shown to a client verbatim. */
final class RefreshRejectedException extends \RuntimeException
{
    public const INVALID_TOKEN = 'invalid_token';
    public const WRONG_TYPE = 'wrong_type';
    public const UNKNOWN = 'unknown';
    public const EXPIRED = 'expired';
    public const REUSE_DETECTED = 'reuse_detected';
    public const SUBJECT_GONE = 'subject_gone';

    public function __construct(private readonly string $reason)
    {
        parent::__construct("Refresh token rejected: $reason", 401);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
