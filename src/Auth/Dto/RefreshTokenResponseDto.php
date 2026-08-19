<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\Dto;

/**
 * Response contract for POST /auth/refresh (Model A — self-contained
 * cookie-based auth). See {@see \StoneScriptPHP\Auth\Routes\RefreshRoute}.
 *
 * @package StoneScriptPHP\Auth\Dto
 */
class RefreshTokenResponseDto
{
    public string $access_token = '';
    public int $expires_in = 0;
    public string $token_type = 'Bearer';
}
