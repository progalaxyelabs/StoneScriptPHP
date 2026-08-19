<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Dto;

/**
 * Response contract for GET {prefix}/onboarding/status — shape of the
 * external auth service's onboarding-status payload. `tenant_slug`/
 * `tenant_name` are only present when the identity has actually completed
 * onboarding, so both are nullable.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Dto
 */
class OnboardingStatusResponseDto
{
    public bool $onboarded = false;
    public ?string $tenant_slug = null;
    public ?string $tenant_name = null;
}
