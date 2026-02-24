<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Routes;

use StoneScriptPHP\ApiResponse;

/**
 * POST {prefix}/invite-member (PROTECTED)
 *
 * Proxies member invitation to the auth service.
 * Requires Authorization header forwarding.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Routes
 */
class InviteMemberRoute extends BaseExternalAuthRoute
{
    public string $email = '';
    public string $tenant_id = '';
    public string $role = '';

    /**
     * {@inheritdoc}
     */
    public function validation_rules(): array
    {
        return [
            'email' => 'required|string',
            'tenant_id' => 'required|string',
            'role' => 'required|string',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function process(): ApiResponse
    {
        return $this->proxyCall(
            fn() => $this->client->inviteMember(
                $this->email,
                $this->tenant_id,
                $this->role,
                $this->getAuthHeader()
            )
        );
    }
}
