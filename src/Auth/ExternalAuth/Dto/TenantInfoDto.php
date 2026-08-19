<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Dto;

/**
 * Shape of the external auth service's "tenant" payload — embedded in its
 * register-tenant response (POST /api/auth/register-tenant, all fields
 * always present/non-null).
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Dto
 */
class TenantInfoDto
{
    public string $id = '';
    public string $name = '';
    public string $slug = '';
    public string $db_schema = '';
}
