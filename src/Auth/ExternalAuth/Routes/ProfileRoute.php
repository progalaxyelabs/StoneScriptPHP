<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Routes;

use StoneScriptPHP\ApiResponse;

/**
 * GET {prefix}/me (PROTECTED)
 *
 * Proxies profile request to the auth service.
 *
 * IMPORTANT: This MUST proxy to the auth service rather than just reading JWT claims.
 * JWT claims are limited (identity_id, tenant_id, role). The auth service returns
 * full profile data (email, display_name, is_email_verified, etc.).
 *
 * If the auth service does not have a dedicated /me endpoint, this falls back to
 * deriving profile data from the memberships endpoint.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Routes
 */
class ProfileRoute extends BaseExternalAuthRoute
{
    /**
     * {@inheritdoc}
     */
    public function validation_rules(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function process(): ApiResponse
    {
        return $this->proxyCall(
            fn() => $this->client->getProfile($this->getAuthHeader())
        );
    }
}
