<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Routes;

use StoneScriptPHP\ApiResponse;

/**
 * POST {prefix}/accept-invite
 *
 * Proxies invitation acceptance to the auth service.
 * New users may provide a password to create their account.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Routes
 */
class AcceptInviteRoute extends BaseExternalAuthRoute
{
    public string $token = '';
    public ?string $password = null;

    /**
     * {@inheritdoc}
     */
    public function validation_rules(): array
    {
        return [
            'token' => 'required|string',
            'password' => 'optional|string',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function process(): ApiResponse
    {
        $input = [
            'token' => $this->token,
            'password' => $this->password,
        ];

        return $this->proxyCall(
            fn() => $this->client->acceptInvite($this->token, $this->password),
            'after_accept_invite',
            $input
        );
    }
}
