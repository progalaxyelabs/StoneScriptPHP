<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Routes;

use StoneScriptPHP\ApiResponse;

/**
 * POST {prefix}/change-password (PROTECTED)
 *
 * Proxies password change for authenticated users to the auth service.
 * Requires Authorization header forwarding.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Routes
 */
class ChangePasswordRoute extends BaseExternalAuthRoute
{
    public string $current_password = '';
    public string $new_password = '';

    /**
     * {@inheritdoc}
     */
    public function validation_rules(): array
    {
        return [
            'current_password' => 'required|string',
            'new_password' => 'required|string',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function process(): ApiResponse
    {
        return $this->proxyCall(
            fn() => $this->client->changePassword(
                $this->current_password,
                $this->new_password,
                $this->getAuthHeader()
            )
        );
    }
}
