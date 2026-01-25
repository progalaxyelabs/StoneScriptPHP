<?php

namespace StoneScriptPHP\Auth\Client;

/**
 * Membership Client
 *
 * HTTP client for managing user memberships via the ProGalaxy Auth Service.
 * Use this for backend-to-backend operations like system automation or webhooks.
 *
 * For request validation middleware, use TokenValidator instead.
 *
 * @package StoneScriptPHP\Auth\Client
 */
class MembershipClient extends AuthServiceClient
{
    /**
     * Get user memberships for an identity
     *
     * @param string $identityId The identity ID (sub claim from JWT)
     * @param string|null $platformCode Optional platform code to filter by
     * @param string|null $authToken JWT bearer token for authentication
     * @return array Array of membership data
     * @throws AuthServiceException
     */
    public function getUserMemberships(
        string $identityId,
        ?string $platformCode = null,
        ?string $authToken = null
    ): array {
        $endpoint = '/memberships';

        if ($platformCode) {
            $endpoint .= '?platform_code=' . urlencode($platformCode);
        }

        $response = $this->get($endpoint, $this->buildAuthHeader($authToken));

        if (!isset($response['memberships'])) {
            throw new AuthServiceException('Invalid response: missing memberships field');
        }

        return $response['memberships'];
    }

    /**
     * Get a single membership by ID
     *
     * @param string $membershipId The membership ID
     * @param string|null $authToken JWT bearer token for authentication
     * @return array Membership data
     * @throws AuthServiceException
     */
    public function getMembership(string $membershipId, ?string $authToken = null): array
    {
        $endpoint = '/memberships/' . urlencode($membershipId);
        $response = $this->get($endpoint, $this->buildAuthHeader($authToken));

        if (!isset($response['membership'])) {
            throw new AuthServiceException('Invalid response: missing membership field');
        }

        return $response['membership'];
    }

    /**
     * Update a membership
     *
     * @param string $membershipId The membership ID
     * @param array $changes Changes to apply (e.g., ['role' => 'admin', 'status' => 'active'])
     * @param string|null $authToken JWT bearer token (requires admin+ role)
     * @return array Updated membership data
     * @throws AuthServiceException
     */
    public function updateMembership(
        string $membershipId,
        array $changes,
        ?string $authToken = null
    ): array {
        $endpoint = '/memberships/' . urlencode($membershipId);
        $response = $this->put($endpoint, $changes, $this->buildAuthHeader($authToken));

        if (!isset($response['id'])) {
            throw new AuthServiceException('Invalid response: missing membership data');
        }

        return $response;
    }

    /**
     * Suspend a membership
     *
     * @param string $membershipId The membership ID
     * @param string $reason Reason for suspension
     * @param string|null $authToken JWT bearer token (requires admin+ role)
     * @return array Updated membership data
     * @throws AuthServiceException
     */
    public function suspendMembership(
        string $membershipId,
        string $reason,
        ?string $authToken = null
    ): array {
        return $this->updateMembership($membershipId, [
            'status' => 'suspended',
            'suspension_reason' => $reason
        ], $authToken);
    }

    /**
     * Reactivate a suspended membership
     *
     * @param string $membershipId The membership ID
     * @param string|null $authToken JWT bearer token (requires admin+ role)
     * @return array Updated membership data
     * @throws AuthServiceException
     */
    public function reactivateMembership(
        string $membershipId,
        ?string $authToken = null
    ): array {
        return $this->updateMembership($membershipId, [
            'status' => 'active'
        ], $authToken);
    }

    /**
     * Create a new membership
     *
     * Use this for backend-initiated membership creation (e.g., after payment).
     *
     * @param array $data Membership data (identity_id, tenant_id, role, etc.)
     * @param string|null $authToken JWT bearer token (requires admin+ role)
     * @return array Created membership data
     * @throws AuthServiceException
     */
    public function createMembership(array $data, ?string $authToken = null): array
    {
        $endpoint = '/memberships';
        $response = $this->post($endpoint, $data, $this->buildAuthHeader($authToken));

        if (!isset($response['membership'])) {
            throw new AuthServiceException('Invalid response: missing membership field');
        }

        return $response['membership'];
    }
}
