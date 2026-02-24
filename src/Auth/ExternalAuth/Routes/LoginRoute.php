<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Routes;

use StoneScriptPHP\ApiResponse;

/**
 * POST {prefix}/login
 *
 * Proxies login to the auth service.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Routes
 */
class LoginRoute extends BaseExternalAuthRoute
{
    public string $email = '';
    public string $password = '';
    public ?string $tenant_slug = null;

    /**
     * {@inheritdoc}
     */
    public function validation_rules(): array
    {
        return [
            'email' => 'required|string',
            'password' => 'required|string',
            'tenant_slug' => 'optional|string',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function process(): ApiResponse
    {
        $input = [
            'email' => $this->email,
            'password' => $this->password,
            'tenant_slug' => $this->tenant_slug,
        ];

        return $this->proxyCall(
            fn() => $this->client->login($this->email, $this->password, $this->tenant_slug),
            'after_login',
            $input
        );
    }
}
