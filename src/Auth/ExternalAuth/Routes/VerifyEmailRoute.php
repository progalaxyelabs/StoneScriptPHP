<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Routes;

use StoneScriptPHP\ApiResponse;

/**
 * POST {prefix}/verify-email
 *
 * Proxies email verification to the auth service.
 * Public route — no JWT required.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Routes
 */
class VerifyEmailRoute extends BaseExternalAuthRoute
{
    public string $email = '';
    public string $code = '';

    /**
     * {@inheritdoc}
     */
    public function validation_rules(): array
    {
        return [
            'email' => 'required|string',
            'code' => 'required|string',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function process(): ApiResponse
    {
        return $this->proxyCall(
            fn() => $this->client->verifyEmail($this->email, $this->code)
        );
    }
}
