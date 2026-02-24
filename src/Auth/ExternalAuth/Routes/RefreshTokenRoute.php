<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Routes;

use StoneScriptPHP\ApiResponse;

/**
 * POST {prefix}/refresh-token
 *
 * Proxies token refresh to the auth service.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Routes
 */
class RefreshTokenRoute extends BaseExternalAuthRoute
{
    public string $refresh_token = '';

    /**
     * {@inheritdoc}
     */
    public function validation_rules(): array
    {
        return [
            'refresh_token' => 'required|string',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function process(): ApiResponse
    {
        return $this->proxyCall(
            fn() => $this->client->refresh($this->refresh_token)
        );
    }
}
