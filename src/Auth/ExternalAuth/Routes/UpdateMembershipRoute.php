<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Routes;

use StoneScriptPHP\ApiResponse;

/**
 * PUT {prefix}/memberships/:id (PROTECTED)
 *
 * Proxies membership updates (role/status changes) to the auth service.
 * Requires Authorization header forwarding.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Routes
 */
class UpdateMembershipRoute extends BaseExternalAuthRoute
{
    /** @var string Membership ID from path parameter */
    public string $id = '';

    public ?string $role = null;
    public ?string $status = null;

    /**
     * {@inheritdoc}
     */
    public function validation_rules(): array
    {
        return [
            'id' => 'required|string',
            'role' => 'optional|string',
            'status' => 'optional|string',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function process(): ApiResponse
    {
        $data = [];
        if ($this->role !== null) {
            $data['role'] = $this->role;
        }
        if ($this->status !== null) {
            $data['status'] = $this->status;
        }

        return $this->proxyCall(
            fn() => $this->client->updateMembership(
                $this->id,
                $data,
                $this->getAuthHeader()
            )
        );
    }
}
