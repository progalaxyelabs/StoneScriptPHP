<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Dto;

/**
 * Response contract for POST {prefix}/refresh-token. Proxies to the external
 * auth service's refresh-response payload, an untagged union of a success
 * variant (access_token, expires_in) and a tenant-selection-required variant
 * (requires_tenant_selection, selection_token — a refresh token minted before
 * a tenant was selected). Flattened for the same reason as
 * {@see LoginResponseDto}.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Dto
 */
class RefreshResponseDto
{
    public ?string $access_token = null;
    public ?int $expires_in = null;
    public ?bool $requires_tenant_selection = null;
    public ?string $selection_token = null;
}
