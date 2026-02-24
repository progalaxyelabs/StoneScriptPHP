<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Routes;

use StoneScriptPHP\ApiResponse;

/**
 * POST {prefix}/logout
 *
 * Proxies logout (refresh token invalidation) to the auth service.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Routes
 */
class LogoutRoute extends BaseExternalAuthRoute
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
            fn() => $this->client->logout($this->refresh_token)
        );
    }
}
