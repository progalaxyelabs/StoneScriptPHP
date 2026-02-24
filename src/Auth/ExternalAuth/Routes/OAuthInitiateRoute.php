<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Routes;

use StoneScriptPHP\ApiResponse;

/**
 * POST {prefix}/oauth/initiate
 *
 * Proxies OAuth flow initiation to the auth service.
 * Returns a redirect URL for the specified provider.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Routes
 */
class OAuthInitiateRoute extends BaseExternalAuthRoute
{
    public string $provider = '';
    public string $redirect_uri = '';
    public ?string $tenant_slug = null;

    /**
     * {@inheritdoc}
     */
    public function validation_rules(): array
    {
        return [
            'provider' => 'required|string',
            'redirect_uri' => 'required|string',
            'tenant_slug' => 'optional|string',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function process(): ApiResponse
    {
        return $this->proxyCall(
            fn() => $this->client->initiateOAuth(
                $this->provider,
                $this->redirect_uri,
                $this->tenant_slug
            )
        );
    }
}
