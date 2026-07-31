<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\Invitations;

/**
 * InvitationException
 *
 * Typed exception raised by {@see InvitationCompletionService}, carrying the
 * error-code taxonomy this design defines for accept-invite
 * failures. The first four codes are the taxonomy inherited from
 * the external auth service's now-removed `accept-invite` handlers (kept
 * byte-for-byte identical so any client-side salvage work
 * needs zero code-mapping changes for those
 * four). `EMAIL_MISMATCH` is new —
 * this failure mode did not exist under auth's old atomic accept.
 *
 * Mirrors {@see \StoneScriptPHP\Auth\TokenExchangeException}'s shape
 * (message + short string error code + toArray()) rather than
 * {@see \StoneScriptPHP\Exceptions\FrameworkException}'s — this sits next to
 * TokenExchangeException conceptually (both are Auth-layer, code-driven
 * exceptions consumed by a route's own catch block, not the framework's
 * central error handler).
 *
 * @package StoneScriptPHP\Auth\Invitations
 */
class InvitationException extends \Exception
{
    /** No invitation row matches the given token hash. */
    public const NOT_FOUND = 'invite_not_found';

    /** Invitation row exists, `expires_at` is in the past. */
    public const EXPIRED = 'invite_expired';

    /** Invitation row exists, `status` is not `pending` (already accepted or revoked). */
    public const ALREADY_USED = 'invite_already_used';

    /** Authenticated passport's email does not match the invitation's email. */
    public const EMAIL_MISMATCH = 'invite_email_mismatch';

    /** The Authorization: Bearer passport failed JWKS/issuer/audience validation. */
    public const INVALID_PASSPORT = 'invite_invalid_passport';

    /** The server-to-server create-membership call to auth failed. */
    public const MEMBERSHIP_FAILED = 'invite_membership_failed';

    /** Required platform configuration (platform_secret, etc.) is missing. */
    public const CONFIG_ERROR = 'invite_config_error';

    private string $errorCode;
    private int $httpStatusCode;

    public function __construct(
        string $message,
        string $errorCode,
        int $httpStatusCode = 400,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $errorCode;
        $this->httpStatusCode = $httpStatusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    /**
     * Convert to array for JSON API responses — same shape as
     * TokenExchangeException::toArray().
     */
    public function toArray(): array
    {
        return [
            'error'   => $this->errorCode,
            'message' => $this->getMessage(),
        ];
    }
}
