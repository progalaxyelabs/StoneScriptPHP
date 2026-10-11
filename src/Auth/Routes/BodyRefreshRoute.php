<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\Routes;

use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Auth\RefreshTokens\RefreshRejectedException;
use StoneScriptPHP\Auth\RefreshTokens\RefreshTokenIssuer;
use StoneScriptPHP\IRouteHandler;

/**
 * Body-mode refresh: `POST` with `{"refresh_token": "...", "access_token": "..."(ignored)}`.
 * Response `data`: `access_token`, `expires_in`, `token_type`, and `refresh_token` ONLY when the
 * issuer rotates (REFRESH_TOKEN_ROTATE). Reload the user / pick up role changes by supplying
 * `claims_provider` in `auth.refresh_tokens`.
 *
 * Register as `access: authentication, token_type: refresh`. Every rejection is a 401 with the same
 * generic message (the precise reason is logged, never returned) and carries the top-level `error_code`
 * `invalid_grant`. When the server cannot verify tokens at all (key unreadable, JWKS unreachable) the answer is 503
 * with `error_code` `token_verifier_unavailable` - never `invalid_grant`, which clients treat as "sign in again". A 501 means refresh persistence is not configured (`error_code` `refresh_store_not_configured`):
 * a hard configuration error, not a refused token.
 */
final class BodyRefreshRoute implements IRouteHandler
{
    public ?string $refresh_token = null;
    public ?string $access_token = null;

    public function validation_rules(): array
    {
        return ['refresh_token' => 'required'];
    }

    /** Error response carrying the stable machine code (top-level `error_code`). */
    private static function refused(string $message, int $status, string $code): ApiResponse
    {
        $r = new ApiResponse('error', $message, null, $status);
        $r->errorCode = $code;
        return $r;
    }

    public function process(): ApiResponse
    {
        $issuer = RefreshTokenIssuer::configured();
        if ($issuer === null) {
            return self::refused('Refresh token persistence is not configured.', 501, 'refresh_store_not_configured');
        }

        try {
            $issued = $issuer->refresh((string) $this->refresh_token);
        } catch (\StoneScriptPHP\Auth\TokenVerifierUnavailableException $e) {
            // We could not verify the token (key/JWKS outage): NOT a refusal. 503 and no `invalid_grant`, or clients end sessions.
            log_error('BodyRefreshRoute: token verification unavailable: ' . \StoneScriptPHP\Persistence\LogSanitizer::describe($e));
            return self::refused('Token verification is temporarily unavailable. Try again shortly.', 503, 'token_verifier_unavailable');
        } catch (RefreshRejectedException $e) {
            log_info('BodyRefreshRoute: refresh refused (' . $e->reason() . ')');
            return self::refused('Invalid or expired refresh token', 401, 'invalid_grant');
        }

        $data = [
            'access_token' => $issued->accessToken,
            'expires_in'   => $issued->expiresIn,
            'token_type'   => 'Bearer',
        ];
        if ($issued->refreshToken !== null) {
            $data['refresh_token'] = $issued->refreshToken;
        }
        return new ApiResponse('ok', '', $data);
    }
}
