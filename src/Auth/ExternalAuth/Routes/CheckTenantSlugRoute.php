<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Routes;

use StoneScriptPHP\ApiResponse;

/**
 * GET {prefix}/check-tenant-slug/:slug
 *
 * Proxies tenant slug availability check to the auth service.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Routes
 */
class CheckTenantSlugRoute extends BaseExternalAuthRoute
{
    /** @var string Tenant slug from path parameter */
    public string $slug = '';

    /**
     * {@inheritdoc}
     */
    public function validation_rules(): array
    {
        return [
            'slug' => 'required|string',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function process(): ApiResponse
    {
        return $this->proxyCall(
            fn() => $this->client->checkTenantSlug($this->slug)
        );
    }
}
