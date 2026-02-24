<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Routes;

use StoneScriptPHP\ApiResponse;

/**
 * POST {prefix}/select-tenant (PROTECTED)
 *
 * Proxies tenant selection to the auth service.
 * Used after a multi-tenant login returns a selection_token.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Routes
 */
class SelectTenantRoute extends BaseExternalAuthRoute
{
    public string $selection_token = '';
    public string $tenant_id = '';

    /**
     * {@inheritdoc}
     */
    public function validation_rules(): array
    {
        return [
            'selection_token' => 'required|string',
            'tenant_id' => 'required|string',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function process(): ApiResponse
    {
        $input = [
            'selection_token' => $this->selection_token,
            'tenant_id' => $this->tenant_id,
        ];

        return $this->proxyCall(
            fn() => $this->client->selectTenant(
                $this->selection_token,
                $this->tenant_id,
                $this->getAuthHeader()
            ),
            'after_select_tenant',
            $input
        );
    }
}
