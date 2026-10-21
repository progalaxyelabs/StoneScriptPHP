<?php

declare(strict_types=1);

namespace StoneScriptPHP\Db;

/**
 * Typed signal from a non-gateway database bridge: "the engine is unusable" (not initialised, host not present,
 * out of memory). Classified as a connection failure by its TYPE, not by guessing from message text. The
 * `reason` is a short fixed code (`bridge_not_registered`, `engine_not_initialized`, ...) that appears in the
 * framework's safe message; the message text itself never does.
 */
class EngineUnavailableException extends \RuntimeException
{
    public function __construct(private readonly string $reason, string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message !== '' ? $message : $reason, 0, $previous);
    }

    public function reason(): string
    {
        return preg_match('/^[a-z][a-z0-9_]{0,40}$/', $this->reason) === 1 ? $this->reason : 'engine_unavailable';
    }
}
