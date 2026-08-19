<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Dto;

/**
 * Shape of the external auth service's "verified OAuth profile" payload —
 * embedded in the OAuth-no-existing-account variant of {@see LoginResponseDto}.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Dto
 */
class OAuthVerifiedProfileDto
{
    public string $email = '';
    public ?string $display_name = null;
    public ?string $photo_url = null;
}
