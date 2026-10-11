<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth;

/**
 * The server could not DECIDE whether a token is valid: a verifying key is unreadable or malformed, or the JWKS of a
 * trusted issuer cannot be fetched (and no usable cache exists).
 *
 * That is an infrastructure failure, not a verdict on the token. Callers that tell clients to end the session on a
 * refusal (refresh endpoints: HTTP 401 `invalid_grant`) must answer 503 instead, so an outage never signs everybody out.
 */
final class TokenVerifierUnavailableException extends \RuntimeException
{
}
