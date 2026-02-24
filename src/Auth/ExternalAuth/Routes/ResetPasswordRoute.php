<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Routes;

use StoneScriptPHP\ApiResponse;

/**
 * POST {prefix}/reset-password
 *
 * Proxies password reset confirmation to the auth service.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Routes
 */
class ResetPasswordRoute extends BaseExternalAuthRoute
{
    public string $token = '';
    public string $new_password = '';

    /**
     * {@inheritdoc}
     */
    public function validation_rules(): array
    {
        return [
            'token' => 'required|string',
            'new_password' => 'required|string',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function process(): ApiResponse
    {
        $input = [
            'token' => $this->token,
            'new_password' => $this->new_password,
        ];

        return $this->proxyCall(
            fn() => $this->client->confirmPasswordReset($this->token, $this->new_password),
            'after_password_reset',
            $input
        );
    }
}
