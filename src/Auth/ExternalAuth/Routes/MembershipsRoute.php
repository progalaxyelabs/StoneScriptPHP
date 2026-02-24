<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Routes;

use StoneScriptPHP\ApiResponse;

/**
 * GET {prefix}/memberships (PROTECTED)
 *
 * Proxies membership listing to the auth service.
 * Platform code is automatically injected by the client.
 * Requires Authorization header forwarding.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Routes
 */
class MembershipsRoute extends BaseExternalAuthRoute
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
            fn() => $this->client->getMemberships($this->getAuthHeader())
        );
    }
}
