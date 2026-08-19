<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Dto;

/**
 * Response contract for POST {prefix}/oauth/initiate — shape of the
 * external auth service's OAuth-initiate payload.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Dto
 */
class OAuthInitiateResponseDto
{
    public string $authorization_url = '';
}
