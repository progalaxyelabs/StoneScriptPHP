<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Routes;

use StoneScriptPHP\ApiResponse;

/**
 * GET {prefix}/onboarding/status
 *
 * Proxies onboarding status check to the auth service.
 * Accepts identity_id as a query parameter.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Routes
 */
class OnboardingStatusRoute extends BaseExternalAuthRoute
{
    /** @var string Identity ID from query parameter */
    public string $identity_id = '';

    /**
     * {@inheritdoc}
     */
    public function validation_rules(): array
    {
        return [
            'identity_id' => 'required|string',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function process(): ApiResponse
    {
        return $this->proxyCall(
            fn() => $this->client->getOnboardingStatus($this->identity_id)
        );
    }
}
