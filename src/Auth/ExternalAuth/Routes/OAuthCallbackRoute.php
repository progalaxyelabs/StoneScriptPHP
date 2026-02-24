<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Routes;

use StoneScriptPHP\ApiResponse;

/**
 * POST {prefix}/oauth/callback
 *
 * Proxies OAuth callback handling to the auth service.
 * Exchanges the authorization code for tokens.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Routes
 */
class OAuthCallbackRoute extends BaseExternalAuthRoute
{
    public string $provider = '';
    public string $code = '';
    public string $state = '';

    /**
     * {@inheritdoc}
     */
    public function validation_rules(): array
    {
        return [
            'provider' => 'required|string',
            'code' => 'required|string',
            'state' => 'required|string',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function process(): ApiResponse
    {
        return $this->proxyCall(
            fn() => $this->client->oauthCallback(
                $this->provider,
                $this->code,
                $this->state
            )
        );
    }
}
