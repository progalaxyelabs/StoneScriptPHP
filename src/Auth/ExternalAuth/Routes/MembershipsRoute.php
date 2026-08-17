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
        // SECURITY: see PlatformCodeGuard's class docblock — the Bearer token
        // was already signature/exp/purpose-verified by AccessTokenMiddleware,
        // but never checked against this platform's own configured code.
        if ($denial = $this->guardPlatformCode(auth()?->platform_code)) {
            return $denial;
        }

        return $this->proxyCall(
            fn() => $this->client->getMemberships($this->getBearerToken())
        );
    }
}
