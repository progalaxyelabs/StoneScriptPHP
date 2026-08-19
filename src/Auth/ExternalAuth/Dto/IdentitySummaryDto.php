<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Dto;

/**
 * The framework-built §6 session-contract "identity" shape returned by
 * ProvisionTenantRoute / ProfileRoute's api-token-model branch. Unlike
 * {@see IdentityInfoDto} (the auth service's own struct, no `id`), this
 * one always carries `id` since it is assembled from already-authenticated
 * claims (`identity_id`/`sub`).
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Dto
 */
class IdentitySummaryDto
{
    public string $id = '';
    public ?string $email = null;
    public ?string $display_name = null;
}
