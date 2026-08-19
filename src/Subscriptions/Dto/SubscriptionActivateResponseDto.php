<?php

declare(strict_types=1);

namespace StoneScriptPHP\Subscriptions\Dto;

/**
 * Response contract for POST {prefix}/admin/activate. Mirrors the
 * `sub_activate` SQL function's `json_build_object(...)`
 * (src/Subscriptions/Schema/functions/sub_activate.pgsql).
 *
 * @package StoneScriptPHP\Subscriptions\Dto
 */
class SubscriptionActivateResponseDto
{
    public string $subscription_id = '';
    public string $platform_code = '';
    public string $tenant_id = '';
    public string $plan_code = '';
    public string $status = '';
    public bool $is_active = false;
    public string $expires_at = '';
    public ?string $payment_id = null;
    public string $activated_at = '';
}
