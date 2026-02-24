<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Routes;

use StoneScriptPHP\ApiResponse;

/**
 * GET {prefix}/health
 *
 * Proxies health check to the auth service.
 * Returns the auth service's health status.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Routes
 */
class AuthHealthRoute extends BaseExternalAuthRoute
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
            fn() => $this->client->healthCheck()
        );
    }
}
