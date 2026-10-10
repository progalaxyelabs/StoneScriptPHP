<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\Routes;

use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Auth\RefreshTokens\RefreshTokenIssuer;
use StoneScriptPHP\IRouteHandler;

/**
 * Body-mode logout: `POST {prefix}/logout` with `{"refresh_token": "..."}` (what the stock
 * client sends; no cookie, no CSRF). Ends the whole session (every token of its family).
 *
 * Register as `access: authentication, token_type: refresh` so the typed-auth refresh gate
 * applies. Idempotent: an unknown or already-revoked token still answers ok. Requires a
 * configured {@see RefreshTokenIssuer} (REFRESH_TOKEN_STORE / auth.refresh_tokens.store).
 */
final class BodyLogoutRoute implements IRouteHandler
{
    public ?string $refresh_token = null;

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
        $issuer->revokeSession((string) $this->refresh_token);
        return new ApiResponse('ok', 'Logged out');
    }
}
