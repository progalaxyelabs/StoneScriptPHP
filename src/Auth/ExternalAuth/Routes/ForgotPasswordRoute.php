<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Routes;

use StoneScriptPHP\ApiResponse;

/**
 * POST {prefix}/forgot-password
 *
 * Proxies password reset request to the auth service.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Routes
 */
class ForgotPasswordRoute extends BaseExternalAuthRoute
{
    public string $email = '';

    /**
     * {@inheritdoc}
     */
    public function validation_rules(): array
    {
        return [
            'email' => 'required|string',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function process(): ApiResponse
    {
        return $this->proxyCall(
            fn() => $this->client->requestPasswordReset($this->email)
        );
    }
}
