<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Routes;

use StoneScriptPHP\ApiResponse;

/**
 * POST {prefix}/resend-code
 *
 * Proxies resend verification code request to the auth service.
 * Public route — no JWT required.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Routes
 */
class ResendVerificationCodeRoute extends BaseExternalAuthRoute
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
            fn() => $this->client->resendVerificationCode($this->email)
        );
    }
}
