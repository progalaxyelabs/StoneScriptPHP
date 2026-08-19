<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Dto;

/**
 * Response contract for POST {prefix}/logout — shape of the external auth
 * service's logout-response payload.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Dto
 */
class LogoutResponseDto
{
    public bool $success = false;
}
