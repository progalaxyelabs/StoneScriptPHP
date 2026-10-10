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
 * generic message (the precise reason is logged, never returned).
 */
final class BodyRefreshRoute implements IRouteHandler
{
    public ?string $refresh_token = null;
    public ?string $access_token = null;

    public function validation_rules(): array
    {
        return ['refresh_token' => 'required'];
    }

    public function process(): ApiResponse
    {
        $issuer = RefreshTokenIssuer::configured();
        if ($issuer === null) {
            return new ApiResponse('error', 'Refresh token persistence is not configured.', null, 501);
        }

        try {
            $issued = $issuer->refresh((string) $this->refresh_token);
        } catch (RefreshRejectedException $e) {
            log_info('BodyRefreshRoute: refresh refused (' . $e->reason() . ')');
            return new ApiResponse('error', 'Invalid or expired refresh token', null, 401);
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
