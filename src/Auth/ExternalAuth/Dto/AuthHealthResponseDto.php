<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Dto;

/**
 * Response contract for GET {prefix}/health — shape of the external auth
 * service's own `/health` payload (`{status, gateway, redis, version}`).
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Dto
 */
class AuthHealthResponseDto
{
    public string $status = '';
    public bool $gateway = false;
    public bool $redis = false;
    public string $version = '';
}
