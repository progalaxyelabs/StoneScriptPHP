<?php

namespace StoneScriptPHP\Auth\Client;

/**
 * Invitation Client
 *
 * HTTP client for managing user invitations via the ProGalaxy Auth Service.
 * Use this for backend-to-backend operations like system automation or bulk invitations.
 *
 * For frontend user invitations, consider calling the auth service directly from Angular.
 *
 * @package StoneScriptPHP\Auth\Client
 */
class InvitationClient extends AuthServiceClient
{
    /**
     * Invite a user to a tenant with a specific role
     *
     * @param string $email The email address of the user to invite
     * @param string $tenantId The tenant identifier
     * @param string $role The role to assign (e.g., 'admin', 'member', 'viewer')
     * @param string|null $authToken JWT bearer token for authentication
     * @return array Created invitation data
     * @throws AuthServiceException
     */
    public function inviteUser(
        string $email,
        string $tenantId,
        string $role,
        ?string $authToken = null
    ): array {
        $endpoint = '/memberships/invite';

        $payload = [
            'email' => $email,
            'tenant_id' => $tenantId,
            'role' => $role
        ];

        $response = $this->post($endpoint, $payload, $this->buildAuthHeader($authToken));

        if (!isset($response['invitation'])) {
            throw new AuthServiceException('Invalid response: missing invitation field');
        }

        return $response['invitation'];
    }

    /**
     * Get details of a specific invitation
     *
     * @param string $invitationId The invitation identifier
     * @param string|null $authToken JWT bearer token for authentication
     * @return array Invitation data
     * @throws AuthServiceException
     */
    public function getInvitation(string $invitationId, ?string $authToken = null): array
    {
        $endpoint = '/memberships/invite/' . urlencode($invitationId);
        $response = $this->get($endpoint, $this->buildAuthHeader($authToken));

        if (!isset($response['invitation'])) {
            throw new AuthServiceException('Invalid response: missing invitation field');
        }

        return $response['invitation'];
    }

    /**
     * Cancel a pending invitation
     *
     * @param string $invitationId The invitation identifier
     * @param string|null $authToken JWT bearer token for authentication
     * @return void
     * @throws AuthServiceException
     */
    public function cancelInvitation(string $invitationId, ?string $authToken = null): void
    {
        $endpoint = '/memberships/invite/' . urlencode($invitationId) . '/cancel';
        $this->post($endpoint, [], $this->buildAuthHeader($authToken));
    }

    /**
     * Resend an invitation email
     *
     * @param string $invitationId The invitation identifier
     * @param string|null $authToken JWT bearer token for authentication
     * @return void
     * @throws AuthServiceException
     */
    public function resendInvitation(string $invitationId, ?string $authToken = null): void
    {
        $endpoint = '/memberships/invite/' . urlencode($invitationId) . '/resend';
        $this->post($endpoint, [], $this->buildAuthHeader($authToken));
    }

    /**
     * Bulk invite multiple users
     *
     * Use this for backend automation when you need to invite many users at once.
     *
     * @param array $invitations Array of invitation data [['email' => ..., 'tenant_id' => ..., 'role' => ...], ...]
     * @param string|null $authToken JWT bearer token for authentication
     * @return array Array of created invitations
     * @throws AuthServiceException
     */
    public function bulkInvite(array $invitations, ?string $authToken = null): array
    {
        $endpoint = '/memberships/invite/bulk';

        $payload = ['invitations' => $invitations];

        $response = $this->post($endpoint, $payload, $this->buildAuthHeader($authToken));

        if (!isset($response['invitations'])) {
            throw new AuthServiceException('Invalid response: missing invitations field');
        }

        return $response['invitations'];
    }
}
